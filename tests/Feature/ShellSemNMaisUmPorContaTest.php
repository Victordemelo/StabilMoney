<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Goal;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SidebarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O cheque especial da sidebar e os selects de conta de /metas e /investimentos não
 * custam queries por conta — e mostram os mesmos números de antes.
 *
 * - A sidebar está em TODA página. O laço do cheque especial lia `overdraftUsed` de cada
 *   conta corrente, e cada leitura eram 6 queries (2 SUMs sobre o histórico da conta e 4
 *   sobre metas e investimentos) — V-1 da auditoria de volume de 06/09/2026.
 * - /metas e /investimentos imprimem o disponível de cada conta nos selects de aporte e
 *   resgate, sem `preloadMoney` no controller: mais 6 queries por conta (V-5).
 *
 * As duas pontas agora passam por `Account::preloadMoney()`, e o accessor continua sendo
 * a única fórmula do dinheiro — por isso os números são conferidos contra o accessor
 * lido do zero, e não contra um valor fixo apenas.
 */
class ShellSemNMaisUmPorContaTest extends TestCase
{
    use RefreshDatabase;

    /** Família com N contas correntes com cheque especial, histórico e dinheiro reservado. */
    private function familiaComCorrentes(int $quantas): User
    {
        $titular = User::factory()->create(['is_admin' => true]);
        $meta = Goal::factory()->for($titular)->create();
        $investimento = Investment::factory()->for($titular)->create();
        $hoje = now()->toDateString();

        for ($i = 0; $i < $quantas; $i++) {
            $conta = Account::factory()->for($titular)->create([
                'type' => $i % 2 === 0 ? 'checking' : 'savings',
                'initial_balance' => 1000,
                'overdraft_limit' => $i % 2 === 0 ? 500 : 0,
            ]);
            Transaction::factory()->for($titular)->create(['account_id' => $conta->id, 'type' => 'expense', 'amount' => 900 + $i, 'date' => $hoje]);
            Transaction::factory()->for($titular)->create(['account_id' => $conta->id, 'type' => 'income', 'amount' => 40, 'date' => $hoje]);
            $meta->contributions()->create(['account_id' => $conta->id, 'type' => 'aporte', 'amount' => 20, 'date' => $hoje]);
            $investimento->contributions()->create(['account_id' => $conta->id, 'type' => 'aporte', 'amount' => 30, 'date' => $hoje]);
        }

        return $titular;
    }

    private function contarQueries(callable $acao): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $acao();
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    }

    public function test_o_cheque_especial_da_sidebar_nao_custa_queries_por_conta(): void
    {
        $uma = $this->familiaComCorrentes(1);
        $cinco = $this->familiaComCorrentes(9); // 5 correntes com limite + 4 poupanças

        $comUma = $this->contarQueries(fn () => app(SidebarService::class)->build($uma->id));
        $comCinco = $this->contarQueries(fn () => app(SidebarService::class)->build($cinco->id));

        $this->assertSame($comUma, $comCinco, "A sidebar voltou a consultar o banco por conta corrente: {$comUma} queries com 1, {$comCinco} com 5.");
    }

    /**
     * Os números do cheque especial são os do accessor. Inclui uma corrente SEM limite
     * que ficou no vermelho por uma obrigação vencida (o modelo permite): ela entra no
     * "usado", como sempre entrou — o ganho de desempenho não pode mudar quem é somado.
     */
    public function test_o_cheque_especial_da_sidebar_mostra_os_numeros_do_accessor(): void
    {
        $titular = User::factory()->create(['is_admin' => true]);
        $hoje = now()->toDateString();
        $meta = Goal::factory()->for($titular)->create();

        $noVermelho = Account::factory()->for($titular)->create(['type' => 'checking', 'initial_balance' => 1000, 'overdraft_limit' => 500]);
        Transaction::factory()->for($titular)->create(['account_id' => $noVermelho->id, 'type' => 'expense', 'amount' => 1100, 'date' => $hoje]);
        $meta->contributions()->create(['account_id' => $noVermelho->id, 'type' => 'aporte', 'amount' => 50, 'date' => $hoje]);

        $noAzul = Account::factory()->for($titular)->create(['type' => 'checking', 'initial_balance' => 2000, 'overdraft_limit' => 1000]);
        Transaction::factory()->for($titular)->create(['account_id' => $noAzul->id, 'type' => 'expense', 'amount' => 100, 'date' => $hoje]);

        $semLimite = Account::factory()->for($titular)->create(['type' => 'checking', 'initial_balance' => 100, 'overdraft_limit' => 0]);
        Transaction::factory()->for($titular)->create(['account_id' => $semLimite->id, 'type' => 'expense', 'amount' => 180, 'date' => $hoje]);

        $patrimonio = app(SidebarService::class)->build($titular->id);

        $usadoPeloAccessor = round(collect([$noVermelho, $noAzul, $semLimite])
            ->sum(fn (Account $conta) => Account::find($conta->id)->overdraftUsed), 2);

        $this->assertSame(1500.0, $patrimonio['chequeLimite']);
        $this->assertSame($usadoPeloAccessor, $patrimonio['chequeUsado']);
        $this->assertSame(230.0, $patrimonio['chequeUsado'], '150 da conta no vermelho + 80 da sem limite.');
        $this->assertSame(1270.0, $patrimonio['chequeDisponivel']);
    }

    public function test_metas_nao_custa_queries_por_conta(): void
    {
        $this->assertTelaNaoCrescePorConta('metas.index');
    }

    public function test_investimentos_nao_custa_queries_por_conta(): void
    {
        $this->assertTelaNaoCrescePorConta('investimentos.index');
    }

    /** O disponível que as telas recebem é o do accessor, lido do zero. */
    public function test_os_selects_de_metas_e_investimentos_recebem_o_disponivel_do_accessor(): void
    {
        $titular = $this->familiaComCorrentes(3);

        foreach (['metas.index', 'investimentos.index'] as $rota) {
            $contas = $this->actingAs($titular)->get(route($rota))->assertOk()->viewData('accounts');

            $this->assertCount(3, $contas);
            foreach ($contas as $conta) {
                $this->assertSame(Account::find($conta->id)->available, $conta->available, "{$rota}: conta {$conta->name}.");
            }
        }
    }

    private function assertTelaNaoCrescePorConta(string $rota): void
    {
        $uma = $this->familiaComCorrentes(1);
        $quatro = $this->familiaComCorrentes(4);

        $comUma = $this->contarQueries(fn () => $this->actingAs($uma)->get(route($rota))->assertOk());
        $comQuatro = $this->contarQueries(fn () => $this->actingAs($quatro)->get(route($rota))->assertOk());

        $this->assertSame($comUma, $comQuatro, "{$rota} voltou a consultar o banco por conta: {$comUma} queries com 1 conta, {$comQuatro} com 4.");
    }
}
