<?php

namespace App\Support;

/**
 * IOF e IR de renda fixa, pelas regras brasileiras.
 *
 * As duas tabelas são regressivas, mas em escalas MUITO diferentes — é a confusão mais
 * comum sobre o assunto:
 *
 * - **IOF** incide só nos primeiros **29 dias** e some no 30º. Escala de DIAS.
 * - **IR** vai de 22,5% a 15% ao longo de **dois anos**. Escala de MESES/ANOS.
 *
 * Ou seja: numa aplicação de 12 meses o IOF é sempre ZERO, e o que muda é o IR (17,5%).
 * O IOF só aparece em resgate de curtíssimo prazo.
 *
 * ORDEM DE INCIDÊNCIA (importa, e nesta ordem): o IOF é cobrado sobre o rendimento, e o
 * IR incide sobre o que SOBRA depois do IOF. Aplicar os dois sobre o rendimento cheio
 * superestimaria o imposto.
 *
 * Ambos incidem só sobre o RENDIMENTO — nunca sobre o principal.
 *
 * Fonte: tabela regressiva do IOF (Decreto 6.306/2007, anexo) e tabela regressiva do IR
 * sobre renda fixa (Lei 11.033/2004).
 */
class TributosRendaFixa
{
    /**
     * IOF por dia corrido de aplicação: % do RENDIMENTO retido.
     *
     * Tabela oficial, escrita por extenso de propósito — é o texto legal, não uma
     * fórmula. (Ela equivale a `floor((30 - dias) / 30 * 100)`, mas transcrever a
     * tabela evita discussão sobre arredondamento.)
     */
    public const IOF_POR_DIA = [
        1 => 96, 2 => 93, 3 => 90, 4 => 86, 5 => 83, 6 => 80, 7 => 76, 8 => 73,
        9 => 70, 10 => 66, 11 => 63, 12 => 60, 13 => 56, 14 => 53, 15 => 50,
        16 => 46, 17 => 43, 18 => 40, 19 => 36, 20 => 33, 21 => 30, 22 => 26,
        23 => 23, 24 => 20, 25 => 16, 26 => 13, 27 => 10, 28 => 6, 29 => 3,
    ];

    /** A partir deste prazo o IOF não incide mais. */
    public const IOF_DIAS_ISENCAO = 30;

    /**
     * Tabela regressiva do IR sobre o rendimento (renda fixa e fundos não isentos).
     *
     * @var list<array{ate: int|float, aliquota: float}>
     */
    public const IR_FAIXAS = [
        ['ate' => 180, 'aliquota' => 22.5],
        ['ate' => 360, 'aliquota' => 20.0],
        ['ate' => 720, 'aliquota' => 17.5],
        ['ate' => PHP_INT_MAX, 'aliquota' => 15.0],
    ];

    /**
     * Classes cujo ganho NÃO segue a tabela regressiva de renda fixa.
     * Renda variável e cripto são ganho de capital: 15% (faixa inicial), sem IOF.
     */
    public const CLASSES_GANHO_DE_CAPITAL = ['renda_variavel', 'cripto'];

    /** % do rendimento retido como IOF para um resgate em `$dias` corridos. */
    public static function aliquotaIof(int $dias, ?string $classe = null): float
    {
        // Ganho de capital (ações, cripto) não tem IOF de renda fixa.
        if ($classe !== null && in_array($classe, self::CLASSES_GANHO_DE_CAPITAL, true)) {
            return 0.0;
        }

        if ($dias < 1) {
            return 0.0;
        }

        return (float) (self::IOF_POR_DIA[$dias] ?? 0);
    }

    /** % do rendimento retido como IR para um resgate em `$dias` corridos. */
    public static function aliquotaIr(int $dias, ?string $classe = null): float
    {
        // Ganho de capital: 15% independente do prazo.
        if ($classe !== null && in_array($classe, self::CLASSES_GANHO_DE_CAPITAL, true)) {
            return 15.0;
        }

        foreach (self::IR_FAIXAS as $faixa) {
            if ($dias <= $faixa['ate']) {
                return $faixa['aliquota'];
            }
        }

        return 15.0;
    }

    /**
     * Decompõe o resultado de um resgate.
     *
     * @param  float  $rendimento  o ganho bruto (só o que rendeu, sem o principal)
     * @param  int  $dias  dias corridos entre a aplicação e o resgate
     * @return array{iof: float, ir: float, aliquotaIof: float, aliquotaIr: float, liquido: float}
     *                                                         `liquido` = rendimento após IOF e IR
     */
    public static function decompor(float $rendimento, int $dias, ?string $classe = null): array
    {
        $aliquotaIof = self::aliquotaIof($dias, $classe);
        $aliquotaIr = self::aliquotaIr($dias, $classe);

        // Prejuízo (ou zero) não gera imposto.
        if ($rendimento <= 0) {
            return [
                'iof' => 0.0,
                'ir' => 0.0,
                'aliquotaIof' => $aliquotaIof,
                'aliquotaIr' => $aliquotaIr,
                'liquido' => round($rendimento, 2),
            ];
        }

        $iof = round($rendimento * $aliquotaIof / 100, 2);

        // O IR incide sobre o rendimento JÁ DESCONTADO do IOF — nesta ordem.
        $baseIr = round($rendimento - $iof, 2);
        $ir = round($baseIr * $aliquotaIr / 100, 2);

        return [
            'iof' => $iof,
            'ir' => $ir,
            'aliquotaIof' => $aliquotaIof,
            'aliquotaIr' => $aliquotaIr,
            'liquido' => round($rendimento - $iof - $ir, 2),
        ];
    }

    /**
     * Tabelas em formato pronto para o front, para que JS e PHP nunca divirjam.
     *
     * O projeto já teve esse problema nesta mesma tela (o card mostrava 0,0% e a prévia
     * 30%, porque cada lado tinha sua regra). Expor daqui é a garantia de fonte única.
     *
     * @return array<string, mixed>
     */
    public static function tabelasParaOFront(): array
    {
        return [
            'iofPorDia' => self::IOF_POR_DIA,
            'iofDiasIsencao' => self::IOF_DIAS_ISENCAO,
            'irFaixas' => array_map(
                fn (array $f) => [
                    'ate' => $f['ate'] === PHP_INT_MAX ? null : $f['ate'],
                    'aliquota' => $f['aliquota'],
                ],
                self::IR_FAIXAS,
            ),
            'classesGanhoDeCapital' => self::CLASSES_GANHO_DE_CAPITAL,
        ];
    }
}
