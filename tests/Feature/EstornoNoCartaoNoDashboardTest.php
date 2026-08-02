<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Estorno lançado num CARTÃO DE CRÉDITO é devolução de compra, não dinheiro
 * entrando. Antes desta correção ele entrava como "receita" nas três séries do
 * dashboard (semana, mês, ano) e na "economia" — o usuário devolvia uma compra
 * de R$ 1.000 e o app dizia que ele tinha recebido R$ 1.000.
 *
 * A regra passa a ser a MESMA que `committed`/`currentInvoice` já usavam na
 * fatura: soma com sinal, o estorno abate a despesa, com piso em zero.
 */
class EstornoNoCartaoNoDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    private Account $cartao;

    protected function setUp(): void
    {
        parent::setUp();
        // Quarta-feira: a semana (Seg..Dom) contém a data e o mês não vira no meio.
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

    private function periodos(): array
    {
        return app(DashboardService::class)->build($this->user->id)['payload']['periods'];
    }

    /** Compra de 1.000 no cartão e estorno de 400 no mesmo dia. */
    private function compraComEstorno(): void
    {
        Transaction::factory()->for($this->user)->for($this->cartao)->expense()
            ->create(['amount' => 1000, 'date' => '2026-08-12', 'description' => 'Notebook']);

        Transaction::factory()->for($this->user)->for($this->cartao)->income()
            ->create(['amount' => 400, 'date' => '2026-08-12', 'description' => 'Estorno parcial']);
    }

    public function test_estorno_no_cartao_nao_vira_receita_e_abate_a_despesa(): void
    {
        $this->compraComEstorno();

        foreach (['semana', 'mes', 'ano'] as $periodo) {
            $stats = $this->periodos()[$periodo]['stats'];

            // Antes: receitas = 400 e despesas = 1.000.
            $this->assertSame(0.0, $stats['receitas'], "receitas do período {$periodo}");
            $this->assertSame(600.0, $stats['despesas'], "despesas do período {$periodo}");
            // E a economia deixa de mostrar lucro que não existiu.
            $this->assertSame(-600.0, $stats['economia'], "economia do período {$periodo}");
        }
    }

    public function test_receita_de_verdade_em_conta_continua_contando(): void
    {
        Transaction::factory()->for($this->user)->for($this->conta)->income()
            ->create(['amount' => 3000, 'date' => '2026-08-12', 'description' => 'Salário']);

        Transaction::factory()->for($this->user)->for($this->cartao)->expense()
            ->create(['amount' => 500, 'date' => '2026-08-12']);

        foreach (['semana', 'mes', 'ano'] as $periodo) {
            $stats = $this->periodos()[$periodo]['stats'];
            $this->assertSame(3000.0, $stats['receitas'], "receitas do período {$periodo}");
            $this->assertSame(500.0, $stats['despesas'], "despesas do período {$periodo}");
        }
    }

    public function test_estorno_maior_que_a_compra_zera_a_despesa_sem_ficar_negativa(): void
    {
        Transaction::factory()->for($this->user)->for($this->cartao)->expense()
            ->create(['amount' => 100, 'date' => '2026-08-12']);

        Transaction::factory()->for($this->user)->for($this->cartao)->income()
            ->create(['amount' => 900, 'date' => '2026-08-12']);

        foreach (['semana', 'mes', 'ano'] as $periodo) {
            $stats = $this->periodos()[$periodo]['stats'];
            $this->assertSame(0.0, $stats['receitas'], "receitas do período {$periodo}");
            $this->assertSame(0.0, $stats['despesas'], "despesas do período {$periodo}");
        }
    }

    public function test_series_do_grafico_tambem_abatem_o_estorno(): void
    {
        $this->compraComEstorno();

        $semana = $this->periodos()['semana'];
        // 2026-08-12 é quarta: índice 2 na série Seg..Dom.
        $this->assertSame(600.0, $semana['despesas'][2]);
        $this->assertSame(0.0, $semana['receitas'][2]);
        $this->assertSame(0.0, array_sum($semana['receitas']));

        $ano = $this->periodos()['ano'];
        $this->assertSame(600.0, $ano['despesas'][7], 'agosto = índice 7');
        $this->assertSame(0.0, $ano['receitas'][7]);
    }

    public function test_sparkline_de_receitas_ignora_o_estorno_do_cartao(): void
    {
        $this->compraComEstorno();

        $sparks = app(DashboardService::class)->build($this->user->id)['payload']['sparks'];

        $this->assertSame(0.0, array_sum($sparks['receitas']), 'estorno não é receita na spark');
        $this->assertSame(600.0, array_sum($sparks['despesas']));
    }

    public function test_familia_sem_cartao_nenhum_continua_funcionando(): void
    {
        // Garante que a condição SQL "1 = 0" (nenhum cartão) não quebra a query.
        $solo = User::factory()->create();
        $contaSolo = Account::factory()->for($solo)->create([
            'type' => 'checking', 'initial_balance' => 500,
        ]);

        Transaction::factory()->for($solo)->for($contaSolo)->income()
            ->create(['amount' => 200, 'date' => '2026-08-12']);
        Transaction::factory()->for($solo)->for($contaSolo)->expense()
            ->create(['amount' => 50, 'date' => '2026-08-12']);

        $stats = app(DashboardService::class)->build($solo->id)['payload']['periods']['mes']['stats'];

        $this->assertSame(200.0, $stats['receitas']);
        $this->assertSame(50.0, $stats['despesas']);
    }
}
