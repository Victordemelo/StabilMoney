<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * TOTP — o código de 6 dígitos do app autenticador (RFC 6238 sobre HOTP, RFC 4226).
 *
 * Não há biblioteca aqui de propósito: o algoritmo cabe em 40 linhas, é fechado por
 * norma (não muda), e escrevê-lo permite duas coisas que os pacotes prontos não dão
 * de graça e que este app precisa:
 *
 *  1. **Devolver QUAL passo casou** (`verificar`), que é o que torna possível barrar
 *     replay — ver `two_factor_last_step` no User.
 *  2. Comparação em tempo constante (`hash_equals`) e normalização do que o usuário
 *     digita ("123 456" com espaço, como o Google Authenticator mostra).
 *
 * O segredo trafega e é guardado em **base32** porque é o que os apps autenticadores
 * leem — tanto no QR (`otpauth://`) quanto na digitação manual.
 *
 * Coberto por tests/Unit/TotpTest.php, com os vetores oficiais do apêndice B da RFC 6238.
 */
final class Totp
{
    /** Dígitos do código. 6 é o que todo autenticador espera por padrão. */
    public const DIGITOS = 6;

    /** Segundos de vida de cada código. */
    public const PERIODO = 30;

    /**
     * Passos de tolerância para cada lado do relógio.
     *
     * 1 = aceita o código anterior e o seguinte (janela total de 90 s). Existe porque o
     * relógio do celular nunca bate exatamente com o do servidor, e porque a pessoa leva
     * alguns segundos entre ler e digitar. Aumentar isto amplia a janela de força bruta
     * proporcionalmente — 1 é o valor recomendado pela própria RFC (§5.2).
     */
    public const JANELA = 1;

    /** Alfabeto do base32 (RFC 4648, §6). */
    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Segredo novo, em base32.
     *
     * 20 bytes = 160 bits, que é o tamanho da chave HMAC-SHA1 recomendado pela RFC 4226
     * (§4, R6) e o que o Google Authenticator gera. Vira 32 caracteres base32.
     */
    public static function gerarSegredo(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /** Em que passo de 30 segundos o relógio está (o "T" da RFC). */
    public static function passoAtual(?int $emSegundos = null): int
    {
        return intdiv($emSegundos ?? time(), self::PERIODO);
    }

    /** O código de 6 dígitos daquele segredo naquele passo. */
    public static function codigo(string $segredo, int $passo): string
    {
        $chave = self::base32Decode($segredo);

        // 'J' = inteiro de 64 bits, big-endian — o contador de 8 bytes da RFC 4226 §5.1.
        $hmac = hash_hmac('sha1', pack('J', $passo), $chave, true);

        // Truncamento dinâmico (RFC 4226 §5.3): o último nibble diz de onde tirar os
        // 4 bytes, e o bit mais alto é zerado para o número nunca sair negativo.
        $offset = ord($hmac[19]) & 0x0F;
        $trecho = unpack('N', substr($hmac, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad(
            (string) ($trecho % (10 ** self::DIGITOS)),
            self::DIGITOS,
            '0',
            STR_PAD_LEFT,
        );
    }

    /**
     * O código confere? Devolve o PASSO que casou, ou null.
     *
     * Devolver o passo (em vez de um bool) é o que permite ao chamador guardar o último
     * passo usado e recusar o mesmo código uma segunda vez: sem isso, quem espia a tela
     * ou intercepta o POST tem 30 segundos para reusar o mesmo número.
     *
     * @param  int|null  $depoisDoPasso  recusa passos <= este (proteção de replay)
     * @param  int|null  $agora  timestamp de referência (só os testes passam)
     */
    public static function verificar(
        string $segredo,
        string $codigo,
        ?int $depoisDoPasso = null,
        ?int $agora = null,
    ): ?int {
        $codigo = self::normalizar($codigo);

        if (strlen($codigo) !== self::DIGITOS) {
            return null;
        }

        $atual = self::passoAtual($agora);

        for ($delta = -self::JANELA; $delta <= self::JANELA; $delta++) {
            $passo = $atual + $delta;

            // Já usado (ou anterior ao último usado): o código é válido pelo relógio,
            // mas gastar de novo seria replay.
            if ($depoisDoPasso !== null && $passo <= $depoisDoPasso) {
                continue;
            }

            if (hash_equals(self::codigo($segredo, $passo), $codigo)) {
                return $passo;
            }
        }

        return null;
    }

    /**
     * URI `otpauth://` que o QR carrega (formato do Google Authenticator).
     *
     * O emissor aparece DUAS vezes de propósito: no rótulo ("Emissor:conta", o que os
     * apps antigos leem) e no parâmetro `issuer` (o que os atuais leem). Sem o segundo,
     * várias contas do mesmo app aparecem sem nome na lista do autenticador.
     */
    public static function uri(string $segredo, string $conta, string $emissor): string
    {
        $rotulo = rawurlencode($emissor).':'.rawurlencode($conta);

        return 'otpauth://totp/'.$rotulo.'?'.http_build_query([
            'secret' => $segredo,
            'issuer' => $emissor,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITOS,
            'period' => self::PERIODO,
        ], encoding_type: PHP_QUERY_RFC3986);
    }

    /**
     * Segredo em grupos de 4 para digitação manual ("JBSW Y3DP EHPK 3PXP").
     * Quem não consegue ler o QR digita isto — e digitar 32 caracteres corridos,
     * sem separação, é como se perde a linha.
     */
    public static function formatarSegredo(string $segredo): string
    {
        return trim(chunk_split($segredo, 4, ' '));
    }

    /** Só os dígitos: o autenticador mostra "123 456" e o teclado do celular deixa passar espaço. */
    public static function normalizar(string $codigo): string
    {
        return preg_replace('/\D/', '', $codigo) ?? '';
    }

    /** Bytes → base32 (RFC 4648, sem preenchimento — é o que os autenticadores aceitam). */
    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $saida = '';
        foreach (str_split($bits, 5) as $bloco) {
            $saida .= self::ALFABETO[bindec(str_pad($bloco, 5, '0', STR_PAD_RIGHT))];
        }

        return $saida;
    }

    /** Base32 → bytes. Estoura em entrada inválida: o segredo só vem do nosso gerador. */
    private static function base32Decode(string $segredo): string
    {
        $segredo = strtoupper(str_replace([' ', '=', '-'], '', $segredo));

        $bits = '';
        foreach (str_split($segredo) as $caractere) {
            $indice = strpos(self::ALFABETO, $caractere);

            if ($indice === false) {
                throw new InvalidArgumentException('Segredo TOTP inválido: não é base32.');
            }

            $bits .= str_pad(decbin($indice), 5, '0', STR_PAD_LEFT);
        }

        $saida = '';
        // Sobra de bits que não fecha um byte é lixo de preenchimento — descartar.
        foreach (str_split($bits, 8) as $bloco) {
            if (strlen($bloco) === 8) {
                $saida .= chr(bindec($bloco));
            }
        }

        return $saida;
    }
}
