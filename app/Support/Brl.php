<?php

namespace App\Support;

/**
 * Formatação monetária no padrão brasileiro — fonte única do app.
 *
 * O sinal vem ANTES do símbolo ("−R$ 1.234,56"), como se escreve em português;
 * `number_format` sozinho produzia "R$ -1.234,56". Usa o traço de menos
 * tipográfico (U+2212), que alinha melhor que o hífen em números.
 *
 * Na view, prefira a directive @brl($valor).
 */
final class Brl
{
    /** "R$ 1.234,56" · negativo: "−R$ 1.234,56". */
    public static function format(float|string|null $valor, int $decimais = 2): string
    {
        $n = round((float) $valor, $decimais);

        // -0,00 existe em float; não faz sentido exibir.
        if (abs($n) < 0.005) {
            $n = 0.0;
        }

        return ($n < 0 ? '−' : '') . 'R$ ' . number_format(abs($n), $decimais, ',', '.');
    }

    /** Só o número, sem "R$": "1.234,56" · negativo: "−1.234,56". */
    public static function number(float|string|null $valor, int $decimais = 2): string
    {
        $n = round((float) $valor, $decimais);

        if (abs($n) < 0.005) {
            $n = 0.0;
        }

        return ($n < 0 ? '−' : '') . number_format(abs($n), $decimais, ',', '.');
    }
}
