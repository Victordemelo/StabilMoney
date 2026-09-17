<?php

namespace App\Support;

/**
 * Texto MONTADO pelo app (parte fixa + dado do usuário) que vai para uma coluna de
 * tamanho limitado.
 *
 * ## O defeito que isto fecha (M-1 e M-2 da auditoria de MySQL de 05/09/2026)
 *
 * Cada pedaço respeita o próprio `max:255`, mas a SOMA não: um cartão pode se chamar
 * com 255 caracteres, e "Pagamento da fatura — " + nome passa disso. As colunas de
 * destino são `varchar(255)` (`transactions.description`, `admin_audit_logs.alvo_descricao`)
 * e o MySQL em modo estrito responde erro 1406 ("Data too long") — HTTP 500 no meio de um
 * pagamento de fatura ou de um banimento. A suíte roda em sqlite, que não aplica tamanho de
 * coluna, então ficava verde: é a mesma armadilha das colunas `encrypted`, agora com texto.
 *
 * ## A régua é a do MySQL: caracteres, não bytes
 *
 * Numa coluna utf8mb4, `varchar(255)` são 255 CARACTERES (code points): "ç" conta 1 (2 bytes)
 * e "💳" conta 1 (4 bytes). É o que `mb_strlen` mede. Medir em bytes (`strlen`) cortaria cedo
 * demais, e cortar em bytes (`substr`) partiria um caractere ao meio — UTF-8 inválido, que o
 * MySQL recusa com OUTRO erro (1366).
 *
 * ## O corte nunca parte um grafema
 *
 * O que a pessoa enxerga como UM caractere pode ter vários code points: 👨‍👩‍👧 são 5, 🇧🇷 são 2,
 * e "ç" colado de um nome de arquivo do macOS (forma decomposta) são 2. Cortar no meio deixa
 * um emoji quebrado, uma bandeira que vira letra, uma letra sem cedilha. O corte anda de
 * grafema em grafema (`\X` do PCRE — o container não tem a extensão intl) e para ANTES do
 * primeiro que não cabe inteiro.
 *
 * ## Por que não `Str::limit()`
 *
 * Medido no container (Laravel 12.62): `Str::limit($texto, 255)` devolve **258** caracteres —
 * as reticências entram DEPOIS do limite, e o estouro continua. Além disso ele mede largura
 * de exibição (`mb_strwidth`, em que emoji vale 2), não a régua da coluna, e parte o 👨‍👩‍👧
 * deixando um ZWJ solto antes das reticências.
 */
final class Texto
{
    /**
     * Tamanho de `$table->string('coluna')` sem comprimento explícito — o de todas as
     * colunas em que o app grava texto montado hoje.
     */
    public const VARCHAR = 255;

    /** Um caractere só (U+2026), e não "...": aqui cada caractere conta. */
    public const RETICENCIAS = '…';

    /**
     * Monta `$antes . $variavel . $depois` sem passar de `$maximo` caracteres, encurtando
     * SÓ a parte variável — o dado do usuário.
     *
     * A parte fixa é o que dá sentido à linha e sobrevive inteira: o "Pagamento da fatura —"
     * que explica a saída de caixa, o "— setembro/2026" que diz qual competência foi paga,
     * o "<e-mail>" que identifica quem foi banido. Cortar o conjunto pelo fim perderia
     * justamente isso.
     *
     * Só quando nem as partes fixas cabem (um e-mail absurdo, por exemplo) o conjunto
     * inteiro é cortado pelo fim — ainda dentro do limite.
     */
    public static function paraColuna(
        string $variavel,
        string $antes = '',
        string $depois = '',
        int $maximo = self::VARCHAR,
    ): string {
        // Byte inválido faria o `\X` falhar em silêncio — e o MySQL recusaria a linha
        // (1366). Em texto válido, `mb_scrub` devolve a string intacta.
        $variavel = mb_scrub($variavel, 'UTF-8');
        $antes = mb_scrub($antes, 'UTF-8');
        $depois = mb_scrub($depois, 'UTF-8');

        $completo = $antes.$variavel.$depois;

        if (mb_strlen($completo, 'UTF-8') <= $maximo) {
            return $completo;
        }

        $reticencias = mb_strlen(self::RETICENCIAS, 'UTF-8');
        $espaco = $maximo - mb_strlen($antes.$depois, 'UTF-8') - $reticencias;

        if ($espaco > 0) {
            return $antes.self::cortar($variavel, $espaco).self::RETICENCIAS.$depois;
        }

        return self::cortar($completo, $maximo - $reticencias).self::RETICENCIAS;
    }

    /** Os grafemas iniciais de `$texto` que, somados, têm no máximo `$caracteres` code points. */
    private static function cortar(string $texto, int $caracteres): string
    {
        preg_match_all('/\X/u', $texto, $grafemas);

        $saida = '';
        $usados = 0;

        foreach ($grafemas[0] as $grafema) {
            $tamanho = mb_strlen($grafema, 'UTF-8');

            if ($usados + $tamanho > $caracteres) {
                break;
            }

            $saida .= $grafema;
            $usados += $tamanho;
        }

        // "Cartão da família …" → "Cartão da família…": espaço antes das reticências é ruído.
        return rtrim($saida);
    }
}
