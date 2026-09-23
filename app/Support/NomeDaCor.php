<?php

namespace App\Support;

/**
 * O nome FALADO de uma cor dos pickers (hex → PT-BR), para o leitor de tela.
 *
 * Os pickers de cor são bolinhas sem texto: o radio de cada uma não tinha nome
 * acessível (regra `label` do axe, impacto crítico, no modal "Nova meta" — auditoria de
 * acessibilidade de 07/09/2026), e onde havia um `title` ele trazia o hex, que o leitor
 * soletra ("cerquilha, um, F, A, zero..."). Quem enxerga escolhe pela cor; quem ouve
 * precisa do nome dela.
 *
 * As cores das paletas do app têm nome escolhido à mão, distinto dentro de cada paleta.
 * Uma cor fora delas (a de uma meta ou categoria antiga, gravada antes de a paleta mudar)
 * ganha um nome pelo matiz e pela luminosidade — "Azul-claro", "Verde-escuro" —, nunca o
 * hex.
 */
final class NomeDaCor
{
    /**
     * Paletas de categorias (`CategoryController::CORES`) e de metas (`metas/index`).
     *
     * @var array<string, string>
     */
    private const CONHECIDAS = [
        '#0F6B47' => 'Verde-escuro',
        '#1FA06E' => 'Verde',
        '#59C497' => 'Verde-menta',
        '#18B6BE' => 'Turquesa',
        '#0EA5B5' => 'Ciano',
        '#3B82C4' => 'Azul',
        '#3E84D8' => 'Azul',
        '#6366F1' => 'Índigo',
        '#8B5CF6' => 'Violeta',
        '#9078D8' => 'Lilás',
        '#EC4899' => 'Rosa',
        '#E5604D' => 'Coral',
        '#F0A93B' => 'Âmbar',
        '#C77F2A' => 'Caramelo',
        '#64748B' => 'Cinza-azulado',
        '#78716C' => 'Cinza-pedra',
        '#7C8C84' => 'Cinza-esverdeado',
    ];

    /** Nome de quem não é cor que se reconheça (vazio, "red", "#12"). */
    public const DESCONHECIDA = 'Cor personalizada';

    public static function de(?string $hex): string
    {
        $hex = strtoupper(trim((string) $hex));

        if (isset(self::CONHECIDAS[$hex])) {
            return self::CONHECIDAS[$hex];
        }

        if (preg_match('/^#[0-9A-F]{6}$/', $hex) !== 1) {
            return self::DESCONHECIDA;
        }

        return self::pelaAparencia($hex);
    }

    /**
     * Nome aproximado pelo HSL: o matiz dá a família ("Azul"), a luminosidade o
     * "-claro"/"-escuro", e pouca saturação vira a escala de cinza.
     */
    private static function pelaAparencia(string $hex): string
    {
        [$r, $g, $b] = array_map(
            fn (string $par) => hexdec($par) / 255,
            str_split(substr($hex, 1), 2),
        );

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $luz = ($max + $min) / 2;
        $delta = $max - $min;
        $saturacao = $delta == 0.0 ? 0.0 : $delta / (1 - abs(2 * $luz - 1));

        if ($luz <= 0.1) {
            return 'Preto';
        }
        if ($luz >= 0.93) {
            return 'Branco';
        }

        if ($saturacao < 0.15) {
            $familia = 'Cinza';
        } else {
            if ($max === $r) {
                $matiz = fmod(($g - $b) / $delta, 6);
            } elseif ($max === $g) {
                $matiz = ($b - $r) / $delta + 2;
            } else {
                $matiz = ($r - $g) / $delta + 4;
            }
            $matiz = fmod($matiz * 60 + 360, 360);

            $familia = match (true) {
                $matiz < 15, $matiz >= 345 => 'Vermelho',
                $matiz < 40 => 'Laranja',
                $matiz < 65 => 'Amarelo',
                $matiz < 170 => 'Verde',
                $matiz < 200 => 'Ciano',
                $matiz < 250 => 'Azul',
                $matiz < 290 => 'Roxo',
                default => 'Rosa',
            };
        }

        return match (true) {
            $luz < 0.3 => $familia.'-escuro',
            $luz > 0.72 => $familia.'-claro',
            default => $familia,
        };
    }
}
