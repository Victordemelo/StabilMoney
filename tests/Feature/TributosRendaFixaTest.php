<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TributosRendaFixa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * IOF e IR de renda fixa pelas regras brasileiras.
 *
 * As bordas são o que importa aqui: 29→30 dias (IOF some) e 180/360/720 (IR desce de
 * faixa). Errar um dia numa dessas fronteiras muda o imposto exibido.
 */
class TributosRendaFixaTest extends TestCase
{
    use RefreshDatabase;

    /** A tabela oficial do IOF, dia a dia. Se algum valor mudar, este teste acusa. */
    public static function tabelaOficialDoIof(): array
    {
        return [
            '1 dia' => [1, 96.0],
            '2 dias' => [2, 93.0],
            '3 dias' => [3, 90.0],
            '5 dias' => [5, 83.0],
            '10 dias' => [10, 66.0],
            '15 dias' => [15, 50.0],
            '20 dias' => [20, 33.0],
            '25 dias' => [25, 16.0],
            '28 dias' => [28, 6.0],
            '29 dias (último com IOF)' => [29, 3.0],
            '30 dias (isento)' => [30, 0.0],
            '31 dias' => [31, 0.0],
            '365 dias' => [365, 0.0],
        ];
    }

    #[DataProvider('tabelaOficialDoIof')]
    public function test_aliquota_de_iof_segue_a_tabela_oficial(int $dias, float $esperado): void
    {
        $this->assertSame(
            $esperado,
            TributosRendaFixa::aliquotaIof($dias),
            "IOF de {$dias} dia(s) deveria ser {$esperado}%.",
        );
    }

    /** O IOF zera no 30º dia — a fronteira que o usuário mais sente. */
    public function test_iof_desaparece_no_trigesimo_dia(): void
    {
        $this->assertSame(3.0, TributosRendaFixa::aliquotaIof(29), 'No 29º dia ainda há IOF.');
        $this->assertSame(0.0, TributosRendaFixa::aliquotaIof(30), 'No 30º dia o IOF some.');
    }

    public static function bordasDoIr(): array
    {
        return [
            '1 dia' => [1, 22.5],
            '180 dias (último da 1ª faixa)' => [180, 22.5],
            '181 dias' => [181, 20.0],
            '360 dias' => [360, 20.0],
            '361 dias' => [361, 17.5],
            '365 dias (12 meses)' => [365, 17.5],
            '720 dias' => [720, 17.5],
            '721 dias' => [721, 15.0],
            '5 anos' => [1825, 15.0],
        ];
    }

    #[DataProvider('bordasDoIr')]
    public function test_aliquota_de_ir_segue_a_tabela_regressiva(int $dias, float $esperado): void
    {
        $this->assertSame(
            $esperado,
            TributosRendaFixa::aliquotaIr($dias),
            "IR de {$dias} dia(s) deveria ser {$esperado}%.",
        );
    }

    /**
     * A ORDEM importa: o IOF sai primeiro, e o IR incide sobre o que sobrou. Aplicar os
     * dois sobre o rendimento cheio cobraria imposto a mais do usuário.
     */
    public function test_ir_incide_sobre_o_rendimento_ja_descontado_do_iof(): void
    {
        // R$ 100 de rendimento, resgate em 10 dias: IOF 66%, IR 22,5%.
        $r = TributosRendaFixa::decompor(100.00, 10);

        $this->assertSame(66.00, $r['iof'], 'IOF = 66% de 100.');
        // IR sobre 34 (100 − 66), não sobre 100.
        $this->assertSame(7.65, $r['ir'], 'IR = 22,5% de 34,00 = 7,65 (não 22,50).');
        $this->assertSame(26.35, $r['liquido'], '100 − 66 − 7,65 = 26,35.');
    }

    /** Em 12 meses não há IOF, e o IR é 17,5% — o caso mais comum da tela. */
    public function test_doze_meses_nao_tem_iof_e_o_ir_e_17_e_meio(): void
    {
        $r = TributosRendaFixa::decompor(1000.00, 365);

        $this->assertSame(0.0, $r['aliquotaIof']);
        $this->assertSame(0.0, $r['iof'], 'IOF em 12 meses tem de ser zero.');
        $this->assertSame(17.5, $r['aliquotaIr']);
        $this->assertSame(175.00, $r['ir']);
        $this->assertSame(825.00, $r['liquido']);
    }

    /** Resgate no dia seguinte: o IOF come quase tudo. */
    public function test_resgate_em_um_dia_e_devorado_pelo_iof(): void
    {
        $r = TributosRendaFixa::decompor(100.00, 1);

        $this->assertSame(96.00, $r['iof']);
        $this->assertSame(0.90, $r['ir'], 'IR = 22,5% de 4,00.');
        $this->assertSame(3.10, $r['liquido'], 'Sobram R$ 3,10 de R$ 100 de rendimento.');
    }

    /** Renda variável e cripto são ganho de capital: 15% e nenhum IOF. */
    public function test_ganho_de_capital_nao_tem_iof_e_usa_quinze_por_cento(): void
    {
        foreach (['renda_variavel', 'cripto'] as $classe) {
            $r = TributosRendaFixa::decompor(1000.00, 5, $classe);

            $this->assertSame(0.0, $r['iof'], "{$classe} não paga IOF de renda fixa.");
            $this->assertSame(15.0, $r['aliquotaIr'], "{$classe} usa 15% de ganho de capital.");
            $this->assertSame(150.00, $r['ir']);
            $this->assertSame(850.00, $r['liquido']);
        }
    }

    /** Renda fixa e fundos seguem a tabela regressiva normalmente. */
    public function test_renda_fixa_e_fundos_seguem_a_tabela_regressiva(): void
    {
        foreach (['renda_fixa', 'fundos'] as $classe) {
            $this->assertSame(22.5, TributosRendaFixa::aliquotaIr(90, $classe));
            $this->assertSame(17.5, TributosRendaFixa::aliquotaIr(365, $classe));
            $this->assertSame(66.0, TributosRendaFixa::aliquotaIof(10, $classe));
        }
    }

    /** Imposto nunca incide sobre prejuízo (nem sobre rendimento zero). */
    public function test_prejuizo_nao_paga_imposto(): void
    {
        $r = TributosRendaFixa::decompor(-50.00, 10);

        $this->assertSame(0.0, $r['iof']);
        $this->assertSame(0.0, $r['ir']);
        $this->assertSame(-50.00, $r['liquido'], 'O prejuízo passa intacto.');

        $zero = TributosRendaFixa::decompor(0.0, 10);
        $this->assertSame(0.0, $zero['iof']);
        $this->assertSame(0.0, $zero['ir']);
    }

    /** O imposto nunca pode passar do rendimento — nem no pior dia. */
    public function test_imposto_nunca_supera_o_rendimento(): void
    {
        foreach (range(1, 40) as $dias) {
            $r = TributosRendaFixa::decompor(1000.00, $dias);
            $total = round($r['iof'] + $r['ir'], 2);

            $this->assertLessThanOrEqual(
                1000.00,
                $total,
                "Em {$dias} dias o imposto somou {$total} sobre R$ 1.000 de rendimento.",
            );
            $this->assertGreaterThanOrEqual(0.0, $r['liquido'], "Líquido negativo em {$dias} dias.");
        }
    }

    /** As tabelas expostas ao front precisam bater com as do PHP (fonte única). */
    public function test_tabelas_para_o_front_espelham_as_constantes(): void
    {
        $t = TributosRendaFixa::tabelasParaOFront();

        $this->assertSame(TributosRendaFixa::IOF_POR_DIA, $t['iofPorDia']);
        $this->assertSame(30, $t['iofDiasIsencao']);
        $this->assertCount(4, $t['irFaixas']);
        // A última faixa é aberta (sem teto) — vira null para o JSON.
        $this->assertNull($t['irFaixas'][3]['ate']);
        $this->assertSame(15.0, $t['irFaixas'][3]['aliquota']);
    }

    /**
     * A tela precisa RECEBER as tabelas do PHP: é o que impede a prévia no cliente de
     * divergir do servidor (esta tela já teve card e prévia discordando).
     */
    public function test_tela_de_investimentos_entrega_as_tabelas_ao_front(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get(route('investimentos.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-tributos', $html, 'As tabelas não chegam ao JS.');

        // Extrai e decodifica o atributo, em vez de casar string escapada — o Blade usa
        // flags HEX no @json, e assertar o escape amarraria o teste ao formato.
        $this->assertMatchesRegularExpression("/data-tributos='([^']+)'/", $html);
        preg_match("/data-tributos='([^']+)'/", $html, $m);
        $tabelas = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);

        $this->assertIsArray($tabelas, 'O atributo não contém JSON válido.');
        $this->assertSame(3, $tabelas['iofPorDia'][29] ?? null, 'A tabela de IOF não veio completa.');
        $this->assertSame(96, $tabelas['iofPorDia'][1] ?? null);
        $this->assertSame(30, $tabelas['iofDiasIsencao'] ?? null);
        $this->assertSame(17.5, $tabelas['irFaixas'][2]['aliquota'] ?? null, 'A faixa de 17,5% não veio.');
    }

    /** O seletor de prazo existe — sem ele o IOF nunca seria exercitado na tela. */
    public function test_tela_oferece_simulacao_de_prazo_curto(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get(route('investimentos.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-inv-prazo', $html);
        $this->assertStringContainsString('29 dias', $html, 'Falta o prazo que mostra o último dia com IOF.');
        $this->assertStringContainsString('30 dias', $html, 'Falta o prazo em que o IOF zera.');
    }
}
