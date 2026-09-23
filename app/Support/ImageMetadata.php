<?php

namespace App\Support;

/**
 * Remove metadados de imagens enviadas pelo usuário (fotos de perfil).
 *
 * Motivo: foto tirada no celular normalmente carrega latitude/longitude no EXIF. A foto é
 * vista pela família inteira e pelo painel administrativo e vai para os backups — sem esta
 * limpeza, a localização da casa da família iria junto com o rosto.
 *
 * Por que não re-encodar com GD (o caminho usual): o container tem a extensão GD, mas
 * compilada SEM suporte a JPEG nem a WebP (`imagejpeg` e `imagewebp` não existem), e
 * rebuildar a imagem só por isso não se justifica. Então a limpeza é feita no nível dos
 * bytes do arquivo, e a imagem não perde qualidade nem muda de dimensão:
 *
 * - JPEG: descarta os segmentos APPn (EXIF vive em APP1, ICC/Photoshop em APP2/APPD) e
 *   os comentários COM.
 * - PNG: descarta os chunks de texto/metadado (`eXIf`, `tEXt`, `zTXt`, `iTXt`, `tIME`).
 * - WebP: descarta os chunks `EXIF` e `XMP ` (e qualquer chunk que não seja de imagem),
 *   apaga os bits deles no `VP8X` e recalcula o tamanho do RIFF.
 * - GIF: descarta comentários, texto puro e as extensões de aplicação (é onde vivem o XMP e
 *   qualquer dado de programa); ficam a imagem, o controle de quadros e a repetição da
 *   animação (`NETSCAPE2.0`), para um GIF animado continuar animado.
 *
 * WebP e GIF entraram em 22/09/2026 (achado A-13 da auditoria de 05/09): a regra `image`
 * aceita os dois, e uma foto em WebP chegava ao disco com o GPS intacto.
 *
 * Formato não reconhecido ou arquivo corrompido: devolve os bytes originais sem tocar.
 * A regra `image` do Laravel já validou o MIME antes de chegar aqui, e o risco que isto
 * cobre é o de uma CÂMERA (que grava arquivos bem formados), não o de quem monta um
 * arquivo torto de propósito para vazar a própria localização.
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

    /**
     * Chunks WebP que FICAM: os que desenham a imagem (RFC 9649). Lista de permissão, e não de
     * negação: `EXIF` e `XMP ` saem, e um chunk que ninguém conhece também — o decodificador o
     * ignoraria de qualquer jeito, e é o lugar óbvio para um programa esconder o que quiser.
     * O perfil de cor (`ICCP`) fica, como o `iCCP` do PNG: sem ele as cores mudam.
     */
    private const WEBP_CHUNKS_DA_IMAGEM = ['VP8 ', 'VP8L', 'VP8X', 'ALPH', 'ANIM', 'ANMF', 'ICCP'];

    /** Bits do `VP8X` que anunciam EXIF (0x08) e XMP (0x04): mentiriam depois da limpeza. */
    private const WEBP_FLAGS_DE_METADADO = 0x08 | 0x04;

    /** Extensões de aplicação do GIF que só dizem quantas vezes a animação repete. */
    private const GIF_APLICACOES_DA_ANIMACAO = ['NETSCAPE2.0', 'ANIMEXTS1.0'];

    /** Limpa os bytes de uma imagem, devolvendo a versão sem metadados. */
    public static function strip(string $bytes): string
    {
        if (str_starts_with($bytes, "\xFF\xD8")) {
            return self::stripJpeg($bytes);
        }

        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return self::stripPng($bytes);
        }

        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return self::stripWebp($bytes);
        }

        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return self::stripGif($bytes);
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

    /**
     * Remonta o RIFF só com os chunks da imagem. Cada chunk é
     * [tipo:4][tamanho:4, little-endian][dados][1 byte de preenchimento se o tamanho for
     * ímpar]; o cabeçalho é 'RIFF' + [tamanho do resto] + 'WEBP'. Como o arquivo encolhe, o
     * tamanho do RIFF é recalculado — com o antigo, o leitor procuraria bytes que não existem.
     * O que vier depois do fim que o RIFF declara não é imagem, e não é copiado.
     */
    private static function stripWebp(string $bytes): string
    {
        $declarado = unpack('V', substr($bytes, 4, 4));
        if ($declarado === false) {
            return $bytes;
        }

        // Declarar mais do que existe é arquivo truncado: vale o que existe.
        $fim = min(strlen($bytes), 8 + $declarado[1]);
        $corpo = '';
        $i = 12;

        while ($i + 8 <= $fim) {
            $tipo = substr($bytes, $i, 4);
            $tamanho = unpack('V', substr($bytes, $i + 4, 4))[1];
            $fimDosDados = $i + 8 + $tamanho;

            if ($fimDosDados > $fim) {
                return $bytes; // chunk maior que o arquivo: inconsistente
            }

            if (in_array($tipo, self::WEBP_CHUNKS_DA_IMAGEM, true)) {
                $dados = substr($bytes, $i + 8, $tamanho);

                if ($tipo === 'VP8X' && $tamanho > 0) {
                    $dados[0] = chr(ord($dados[0]) & ~self::WEBP_FLAGS_DE_METADADO);
                }

                // O preenchimento é reescrito, e não copiado: há gravadores que o esquecem
                // no último chunk, e aqui ele deixa de ser o último.
                $corpo .= $tipo.pack('V', $tamanho).$dados.($tamanho % 2 === 1 ? "\x00" : '');
            }

            $i = $fimDosDados + ($tamanho % 2);
        }

        return 'RIFF'.pack('V', 4 + strlen($corpo)).'WEBP'.$corpo;
    }

    /**
     * Percorre os blocos do GIF copiando a imagem e deixando os metadados para trás.
     *
     * Estrutura: cabeçalho (6) + descritor da tela (7) + tabela global de cores (opcional),
     * depois uma sequência de blocos — imagem (0x2C), extensão (0x21 + rótulo) — até o fim
     * (0x3B). Imagem e extensão terminam numa cadeia de sub-blocos [tamanho:1][dados], fechada
     * por um de tamanho 0. O que vem depois do 0x3B não é imagem, e não é copiado.
     */
    private static function stripGif(string $bytes): string
    {
        $tamanho = strlen($bytes);

        if ($tamanho < 13) {
            return $bytes;
        }

        $i = 13 + self::tamanhoDaTabelaDeCores(ord($bytes[10]));
        if ($i > $tamanho) {
            return $bytes;
        }

        $saida = substr($bytes, 0, $i);

        while ($i < $tamanho) {
            $bloco = $bytes[$i];

            if ($bloco === "\x3B") {
                return $saida."\x3B";
            }

            if ($bloco === "\x2C") {
                // Descritor (10 bytes, flags no último) + tabela local de cores + o byte do
                // tamanho mínimo do código LZW; depois, os sub-blocos com os pixels.
                if ($i + 10 > $tamanho) {
                    return $bytes;
                }

                $inicioDosDados = $i + 10 + self::tamanhoDaTabelaDeCores(ord($bytes[$i + 9])) + 1;
                $fimDoBloco = self::fimDosSubBlocos($bytes, $inicioDosDados);

                if ($fimDoBloco === null) {
                    return $bytes;
                }

                $saida .= substr($bytes, $i, $fimDoBloco - $i);
                $i = $fimDoBloco;

                continue;
            }

            if ($bloco === "\x21" && $i + 2 <= $tamanho) {
                $fimDoBloco = self::fimDosSubBlocos($bytes, $i + 2);

                if ($fimDoBloco === null) {
                    return $bytes;
                }

                if (self::extensaoDoGifFica(substr($bytes, $i, $fimDoBloco - $i))) {
                    $saida .= substr($bytes, $i, $fimDoBloco - $i);
                }

                $i = $fimDoBloco;

                continue;
            }

            return $bytes; // byte que não abre bloco nenhum: não é um GIF que se entenda
        }

        // Acabou sem o 0x3B (arquivo cortado — o navegador mostra assim mesmo): fecha aqui.
        return $saida."\x3B";
    }

    /**
     * Fica o que desenha a imagem: o controle gráfico (0xF9 — tempo de cada quadro e
     * transparência) e a repetição da animação. Sai o comentário (0xFE), o texto puro (0x01,
     * que navegador nenhum desenha), toda outra extensão de aplicação (0xFF — o XMP vive em
     * "XMP DataXMP") e rótulo desconhecido.
     */
    private static function extensaoDoGifFica(string $extensao): bool
    {
        return match ($extensao[1] ?? '') {
            "\xF9" => true,
            "\xFF" => ($extensao[2] ?? '') === "\x0B"
                && in_array(substr($extensao, 3, 11), self::GIF_APLICACOES_DA_ANIMACAO, true),
            default => false,
        };
    }

    /** Bytes da tabela de cores anunciada num byte de flags do GIF: 3 × 2^(N+1), ou 0. */
    private static function tamanhoDaTabelaDeCores(int $flags): int
    {
        return ($flags & 0x80) ? 3 * (2 << ($flags & 0x07)) : 0;
    }

    /** Posição logo depois do sub-bloco de tamanho 0, ou null se o arquivo acabar antes. */
    private static function fimDosSubBlocos(string $bytes, int $i): ?int
    {
        $tamanho = strlen($bytes);

        while ($i < $tamanho) {
            $n = ord($bytes[$i]);
            $i += 1 + $n;

            if ($n === 0) {
                return $i;
            }
        }

        return null;
    }
}
