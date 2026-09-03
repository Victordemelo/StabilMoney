<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Goal;
use App\Models\GoalContribution;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Auditoria financeira de 02/09/2026 — itens T-1, T-2 e T-5 do dashboard.
 *
 * `EstornoNoCartaoNoDashboardTest` só cobria estorno NO MESMO DIA da compra. Com o
 * estorno em outro dia, cada fonte aplicava o piso 0 numa granularidade (por dia
 * em `dailySums`, por mês no ano, por período em `totals`) e o mesmo agosto
 * mostrava três números — e um estorno de compra de julho engolia despesa de
 * DÉBITO lançada no mesmo dia. A regra agora é uma só, em `seriesDeFluxo()`:
 *
 *   despesas(período) = despesas de CAIXA + max(0, compras − estornos no CARTÃO)
 *
 * com a soma dos buckets do gráfico igual ao stat. O donut abate o estorno na
 * categoria dele (T-2) e a spark do saldo desconta as reservas (T-5).
 */
class EstornoEmDiaDiferenteNoDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    private Account $cartao;

    protected function setUp(): void
    {
        parent::setUp();
        // Quarta-feira: a semana (Seg 10 .. Dom 16) contém a compra E o estorno do
        // dia seguinte, e o mês não vira no meio.
        Carbon::setTestNow('2026-08-12');

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Corrente', 'initial_balance' => 10000,
        ]);
        $this->cartao = Account::factory()->for($this->user)->create([
            'type' => 'credit_card', 'name' => 'Nubank',
            'credit_limit' => 8000, 'closing_day' => 10, 'due_day' => 20,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function build(): array
    {
        return app(DashboardService::class)->build($this->user->id);
    }

    private function periodos(): array
    {
        return $this->build()['payload']['periods'];
    }

    /** Compra de 1.000 no cartão na quarta (12/08) e estorno de 400 na QUINTA (13/08). */
    private function compraComEstornoNoDiaSeguinte(): void
    {
        Transaction::factory()->for($this->user)->for($this->cartao)->expense()
            ->create(['amount' => 1000, 'date' => '2026-08-12', 'description' => 'Notebook']);

        Transaction::factory()->for($this->user)->for($this->cartao)->income()
            ->create(['amount' => 400, 'date' => '2026-08-13', 'description' => 'Estorno parcial']);
    }

    public function test_estorno_em_dia_diferente_da_compra_da_o_mesmo_numero_na_semana_no_mes_e_no_ano(): void
    {
        $this->compraComEstornoNoDiaSeguinte();

        foreach (['semana', 'mes', 'ano'] as $periodo) {
            $stats = $this->periodos()[$periodo]['stats'];

            // Antes: semana = 1.000, mês = 1.000 (o −400 do dia 13 virava 0 e sumia)
            // e a barra de agosto no ano = 600. Três números para o mesmo agosto.
            $this->assertSame(600.0, $stats['despesas'], "despesas do período {$periodo}");
            $this->assertSame(0.0, $stats['receitas'], "receitas do período {$periodo}");
            $this->assertSame(-600.0, $stats['economia'], "economia do período {$periodo}");
        }
    }

    public function test_a_soma_dos_buckets_do_fluxo_de_caixa_e_o_stat_e_nenhuma_barra_fica_negativa(): void
    {
        $this->compraComEstornoNoDiaSeguinte();
        // Uma despesa de caixa no meio, para a soma não fechar por coincidência.
        Transaction::factory()->for($this->user)->for($this->conta)->expense()
            ->create(['amount' => 250, 'date' => '2026-08-14']);

        foreach ($this->periodos() as $nome => $periodo) {
            $this->assertSame(
                $periodo['stats']['despesas'],
                round(array_sum($periodo['despesas']), 2),
                "Σ buckets de despesas ≠ stat no período {$nome}",
            );
            $this->assertSame(
                $periodo['stats']['receitas'],
                round(array_sum($periodo['receitas']), 2),
                "Σ buckets de receitas ≠ stat no período {$nome}",
            );
            foreach ($periodo['despesas'] as $i => $valor) {
                $this->assertGreaterThanOrEqual(0.0, $valor, "bucket {$i} negativo no período {$nome}");
            }
        }

        // Na semana: o estorno da quinta abate a compra da quarta (é devolução de algo
        // comprado antes) — quarta 600, quinta 0, sexta só o caixa.
        $semana = $this->periodos()['semana']['despesas'];
        $this->assertSame([0.0, 0.0, 600.0, 0.0, 250.0, 0.0, 0.0], $semana);
    }

    public function test_estorno_de_compra_de_julho_nao_engole_a_despesa_de_debito_de_agosto(): void
    {
        // Compra em julho (ciclo passado), estorno em agosto, mercado no débito no
        // MESMO dia do estorno.
        Transaction::factory()->for($this->user)->for($this->cartao)->expense()
            ->create(['amount' => 1000, 'date' => '2026-07-15', 'description' => 'Compra de julho']);
        Transaction::factory()->for($this->user)->for($this->cartao)->income()
            ->create(['amount' => 400, 'date' => '2026-08-12', 'description' => 'Estorno da compra de julho']);
        Transaction::factory()->for($this->user)->for($this->conta)->expense()
            ->create(['amount' => 300, 'date' => '2026-08-12', 'description' => 'Mercado no débito']);

        $periodos = $this->periodos();

        // Antes: 300 − 400 → piso 0, e agosto ficava com despesa ZERO.
        $this->assertSame(300.0, $periodos['semana']['stats']['despesas']);
        $this->assertSame(300.0, $periodos['mes']['stats']['despesas']);
        // A quarta (índice 2) mostra o mercado inteiro: o estorno só abate cartão.
        $this->assertSame(300.0, $periodos['semana']['despesas'][2]);

        // No ano, julho e agosto são buckets do MESMO período: o estorno abate a
        // compra de julho, e agosto fica só com o caixa.
        $ano = $periodos['ano'];
        $this->assertSame(600.0, $ano['despesas'][6], 'julho = compra − estorno');
        $this->assertSame(300.0, $ano['despesas'][7], 'agosto = só o mercado');
        $this->assertSame(900.0, $ano['stats']['despesas']);
        $this->assertSame(0.0, array_sum($ano['receitas']), 'estorno nunca é receita');
    }

    public function test_estorno_sem_compra_no_periodo_e_descartado_e_nao_apaga_o_caixa(): void
    {
        // Só o estorno cai no mês (a compra foi no ano passado): piso 0 no cartão,
        // e a despesa de caixa da semana continua inteira.
        Transaction::factory()->for($this->user)->for($this->cartao)->expense()
            ->create(['amount' => 1000, 'date' => '2025-12-20']);
        Transaction::factory()->for($this->user)->for($this->cartao)->income()
            ->create(['amount' => 1000, 'date' => '2026-08-11']);
        Transaction::factory()->for($this->user)->for($this->conta)->expense()
            ->create(['amount' => 80, 'date' => '2026-08-11']);

        foreach (['semana', 'mes', 'ano'] as $periodo) {
            $stats = $this->periodos()[$periodo]['stats'];
            $this->assertSame(80.0, $stats['despesas'], "despesas do período {$periodo}");
            $this->assertSame(0.0, $stats['receitas'], "receitas do período {$periodo}");
        }
    }

    public function test_trend_de_despesas_usa_o_mes_anterior_pela_mesma_regra(): void
    {
        // Julho: compra 1.000 dia 12 e estorno 400 dia 13 → base 600.
        Transaction::factory()->for($this->user)->for($this->cartao)->expense()
            ->create(['amount' => 1000, 'date' => '2026-07-12']);
        Transaction::factory()->for($this->user)->for($this->cartao)->income()
            ->create(['amount' => 400, 'date' => '2026-07-13']);
        // Agosto: 300 de caixa → −50% sobre 600 (sobre 1.000 seria −70%).
        Transaction::factory()->for($this->user)->for($this->conta)->expense()
            ->create(['amount' => 300, 'date' => '2026-08-12']);

        $this->assertSame(-50.0, $this->periodos()['mes']['trends']['despesas']);
    }

    public function test_sparks_de_despesas_seguem_a_mesma_regra(): void
    {
        // Janela da spark: 06..12/08. Compra dia 10, estorno dia 11, caixa dia 12.
        Transaction::factory()->for($this->user)->for($this->cartao)->expense()
            ->create(['amount' => 1000, 'date' => '2026-08-10']);
        Transaction::factory()->for($this->user)->for($this->cartao)->income()
            ->create(['amount' => 400, 'date' => '2026-08-11']);
        Transaction::factory()->for($this->user)->for($this->conta)->expense()
            ->create(['amount' => 300, 'date' => '2026-08-12']);

        $sparks = $this->build()['payload']['sparks'];

        $this->assertSame([0.0, 0.0, 0.0, 0.0, 600.0, 0.0, 300.0], $sparks['despesas']);
        $this->assertSame(0.0, array_sum($sparks['receitas']));
        $this->assertSame(-900.0, end($sparks['economia']));
    }

    public function test_donut_abate_o_estorno_na_categoria_e_fecha_com_o_stat_do_mes(): void
    {
        $eletronicos = Category::factory()->for($this->user)->expense()->create(['name' => 'Eletrônicos']);
        $mercado = Category::factory()->for($this->user)->expense()->create(['name' => 'Mercado']);

        Transaction::factory()->for($this->user)->for($this->cartao)->expense()
            ->create(['amount' => 1000, 'date' => '2026-08-12', 'category_id' => $eletronicos->id]);
        Transaction::factory()->for($this->user)->for($this->cartao)->income()
            ->create(['amount' => 400, 'date' => '2026-08-13', 'category_id' => $eletronicos->id]);
        Transaction::factory()->for($this->user)->for($this->conta)->expense()
            ->create(['amount' => 300, 'date' => '2026-08-12', 'category_id' => $mercado->id]);
        // Receita de caixa NÃO entra no donut (nem abate nada).
        Transaction::factory()->for($this->user)->for($this->conta)->income()
            ->create(['amount' => 5000, 'date' => '2026-08-12', 'category_id' => null]);

        $dados = $this->build();
        $cats = collect($dados['cats']);

        // Antes: Eletrônicos = 1.000 ao lado de um stat de despesas de 900.
        $this->assertSame(600.0, $cats->firstWhere('name', 'Eletrônicos')['value']);
        $this->assertSame(300.0, $cats->firstWhere('name', 'Mercado')['value']);
        $this->assertSame(
            $dados['payload']['periods']['mes']['stats']['despesas'],
            round($cats->sum('value'), 2),
            'a soma do donut é o stat de despesas do mês',
        );
    }

    public function test_estorno_maior_que_a_categoria_tira_a_fatia_sem_ficar_negativa(): void
    {
        $eletronicos = Category::factory()->for($this->user)->expense()->create(['name' => 'Eletrônicos']);

        Transaction::factory()->for($this->user)->for($this->cartao)->expense()
            ->create(['amount' => 100, 'date' => '2026-08-12', 'category_id' => $eletronicos->id]);
        Transaction::factory()->for($this->user)->for($this->cartao)->income()
            ->create(['amount' => 900, 'date' => '2026-08-13', 'category_id' => $eletronicos->id]);
        // Estorno SEM categoria e sem compra em "Sem categoria": abate só o que houver
        // ali (nada) — não vira fatia negativa nem "Sem categoria" de −400.
        Transaction::factory()->for($this->user)->for($this->cartao)->income()
            ->create(['amount' => 400, 'date' => '2026-08-13', 'category_id' => null]);
        Transaction::factory()->for($this->user)->for($this->conta)->expense()
            ->create(['amount' => 50, 'date' => '2026-08-12', 'category_id' => null]);

        $cats = collect($this->build()['cats']);

        $this->assertFalse($cats->contains('name', 'Eletrônicos'));
        $this->assertSame(50.0, $cats->firstWhere('name', 'Sem categoria')['value']);
        foreach ($cats as $cat) {
            $this->assertGreaterThan(0.0, $cat['value'], "fatia {$cat['name']} não pode ser ≤ 0");
        }
    }

    public function test_spark_do_saldo_desconta_as_reservas_e_termina_no_stat(): void
    {
        $meta = Goal::factory()->for($this->user)->create();

        // Reserva ANTES da janela da spark (06..12/08): sai do ponto de partida.
        GoalContribution::factory()->for($meta)->for($this->conta)->aporte()
            ->create(['amount' => 500, 'date' => '2026-07-20']);
        // Aporte DENTRO da janela, no dia 10: a linha cai 2.000 nesse dia.
        GoalContribution::factory()->for($meta)->for($this->conta)->aporte()
            ->create(['amount' => 2000, 'date' => '2026-08-10']);
        // Resgate no dia 11 devolve 300 à conta.
        GoalContribution::factory()->for($meta)->for($this->conta)->resgate()
            ->create(['amount' => 300, 'date' => '2026-08-11']);
        Transaction::factory()->for($this->user)->for($this->conta)->expense()
            ->create(['amount' => 100, 'date' => '2026-08-12']);

        $dados = $this->build();
        $spark = $dados['payload']['sparks']['saldo'];
        $stat = $dados['payload']['periods']['mes']['stats']['saldo'];

        // Disponível hoje = 10.000 − 100 − (500 + 2.000 − 300) = 7.700.
        $this->assertSame(7700.0, $stat);
        // Antes: a linha terminava em 9.900 (bruto) e o card dizia 7.700.
        $this->assertSame($stat, end($spark), 'último ponto da spark = stat saldo');
        // Pontos 06..09 = 9.500 (só a reserva antiga); dia 10 cai o aporte; dia 11 volta o resgate.
        $this->assertSame([9500.0, 9500.0, 9500.0, 9500.0, 7500.0, 7800.0, 7700.0], $spark);
    }
}
