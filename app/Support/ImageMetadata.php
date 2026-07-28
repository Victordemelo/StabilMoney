<?php

namespace App\Support;

/**
 * Remove metadados de imagens enviadas pelo usuário (fotos de perfil).
 *
 * Motivo: foto tirada no celular normalmente carrega latitude/longitude no EXIF, e os
 * avatares são gravados no disco `public` — servidos sem autenticação. Sem esta limpeza,
 * a localização da casa da família ficaria num arquivo público.
 *
 * Por que não re-encodar com GD (o caminho usual): o container tem a extensão GD, mas
 * compilada SEM suporte a JPEG (`imagejpeg` não existe), e rebuildar a imagem só por
 * isso não se justifica. Então a limpeza é feita no nível dos bytes do arquivo:
 *
 * - JPEG: descarta os segmentos APPn (EXIF vive em APP1, ICC/Photoshop em APP2/APPD) e
 *   os comentários COM. Copia todo o resto intacto, então a imagem não perde qualidade
 *   nem muda de dimensão — diferente do re-encode.
 * - PNG: descarta os chunks de texto/metadado (`eXIf`, `tEXt`, `zTXt`, `iTXt`, `tIME`).
 *
 * Formato não reconhecido ou arquivo corrompido: devolve os bytes originais sem tocar.
 * A regra `image` do Laravel já validou o MIME antes de chegar aqui.
 */
class ImageMetadata
{
    /** Segmentos JPEG que carregam metadado e podem sair sem afetar a imagem. */
    private const JPEG_DESCARTAVEIS = [
        0xE0, 0xE1, 0xE2, 0xE3, 0xE4, 0xE5, 0xE6, 0xE7, // APP0..APP7 (APP1 = EXIF)
        0xE8, 0xE9, 0xEA, 0xEB, 0xEC, 0xED, 0xEE, 0xEF, // APP8..APP15
        0xFE,                                            // COM (comentário)
    ];

    /** Chunks PNG de metadado. */
    private const PNG_DESCARTAVEIS = ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'];

    /** Limpa os bytes de uma imagem, devolvendo a versão sem metadados. */
    public static function strip(string $bytes): string
    {
        if (str_starts_with($bytes, "\xFF\xD8")) {
            return self::stripJpeg($bytes);
        }

        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return self::stripPng($bytes);
        }

        return $bytes;
    }

    /**
     * Percorre a cadeia de segmentos do JPEG copiando tudo, exceto APPn e COM.
     * Ao encontrar SOS (início dos dados comprimidos) copia o resto de uma vez —
     * dali para frente não há mais estrutura de segmentos para interpretar.
     */
    private static function stripJpeg(string $bytes): string
    {
        $tamanho = strlen($bytes);
        $saida = "\xFF\xD8";  // SOI
        $i = 2;

        while ($i + 1 < $tamanho) {
            // Todo marcador começa com 0xFF; fora disso o arquivo não segue o formato.
            if ($bytes[$i] !== "\xFF") {
                return $bytes;
            }

            $marcador = ord($bytes[$i + 1]);

            // SOS (0xDA) ou EOI (0xD9): daqui em diante é payload, copia tudo.
            if ($marcador === 0xDA || $marcador === 0xD9) {
                return $saida.substr($bytes, $i);
            }

            // Marcadores sem payload (0x01 e 0xD0..0xD7): 2 bytes e segue.
            if ($marcador === 0x01 || ($marcador >= 0xD0 && $marcador <= 0xD7)) {
                $saida .= substr($bytes, $i, 2);
                $i += 2;

                continue;
            }

            if ($i + 3 >= $tamanho) {
                return $bytes; // truncado
            }

            // Os 2 bytes seguintes são o tamanho do segmento (big-endian, inclusive eles).
            $comprimento = (ord($bytes[$i + 2]) << 8) | ord($bytes[$i + 3]);
            if ($comprimento < 2 || $i + 2 + $comprimento > $tamanho) {
                return $bytes; // tamanho inconsistente
            }

            if (! in_array($marcador, self::JPEG_DESCARTAVEIS, true)) {
                $saida .= substr($bytes, $i, 2 + $comprimento);
            }

            $i += 2 + $comprimento;
        }

        return $saida;
    }

    /**
     * Copia os chunks do PNG pulando os de metadado. Cada chunk é
     * [tamanho:4][tipo:4][dados:tamanho][crc:4] — como só removemos chunks inteiros,
     * os CRC dos que ficam continuam válidos.
     */
    private static function stripPng(string $bytes): string
    {
        $tamanho = strlen($bytes);
        $saida = substr($bytes, 0, 8);  // assinatura
        $i = 8;

        while ($i + 8 <= $tamanho) {
            $dados = unpack('N', substr($bytes, $i, 4));
            if ($dados === false) {
                return $bytes;
            }

            $comprimento = $dados[1];
            $tipo = substr($bytes, $i + 4, 4);
            $total = 12 + $comprimento;  // tamanho + tipo + dados + crc

            if ($comprimento < 0 || $i + $total > $tamanho) {
                return $bytes; // truncado ou inconsistente
            }

            if (! in_array($tipo, self::PNG_DESCARTAVEIS, true)) {
                $saida .= substr($bytes, $i, $total);
            }

            $i += $total;

            if ($tipo === 'IEND') {
                break;
            }
        }

        return $saida;
    }
}
