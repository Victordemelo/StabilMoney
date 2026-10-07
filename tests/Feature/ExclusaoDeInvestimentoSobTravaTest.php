<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\SimulaRequisicaoConcorrente;
use Tests\TestCase;

/**
 * Excluir investimento é decidido e feito sob trava (out/2026 — auditoria de concorrência,
 * pendência 5).
 *
 * A trava de exclusão (investimento com dinheiro + conta de origem no vermelho = resgate
 * primeiro) consultava o aplicado e os saldos e só depois apagava, sem travar nada: uma
 * despesa que levasse a conta ao vermelho logo depois da conferência deixava excluir sem o
 * resgate que a regra exige, e um aporte concorrente era confirmado e apagado pelo cascade.
 * Agora as contas da família (por id) e o investimento são travados antes da decisão — a
 * mesma ordem do `FundingService` e do `HandlesContributions`.
 */
class ExclusaoDeInvestimentoSobTravaTest extends TestCase
{
    use RefreshDatabase;
    use SimulaRequisicaoConcorrente;

    private User $user;

    private Account $conta;

    private Investment $inv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Corrente', 'initial_balance' => 1000, 'overdraft_limit' => 2000,
        ]);
        $this->inv = Investment::create([
            'user_id' => $this->user->id, 'name' => 'CDB', 'classe' => 'renda_fixa', 'indexador' => 'cdi', 'taxa' => 100,
        ]);
        $this->actingAs($this->user)->post(route('investimentos.aportes.store', $this->inv), [
            'account_id' => $this->conta->id, 'amount' => '500,00', 'date' => CarbonImmutable::today()->toDateString(),
        ])->assertSessionHasNoErrors();
    }

    public function test_a_decisao_e_a_exclusao_acontecem_na_mesma_transacao(): void
    {
        $base = DB::transactionLevel();
        $gramatica = DB::getQueryGrammar();

        $consultas = $this->consultasDe(fn () => $this->actingAs($this->user)
            ->delete(route('investimentos.destroy', $this->inv))->assertSessionHasNoErrors());

        $aplicado = collect($consultas)->first(fn ($c) => str_contains($c['sql'], 'sum(')
            && str_contains($c['sql'], $gramatica->wrapTable('investment_contributions')));
        $apagou = collect($consultas)->first(fn ($c) => str_starts_with($c['sql'], 'delete from '.$gramatica->wrapTable('investments')));

        $this->assertNotNull($aplicado);
        $this->assertNotNull($apagou);
        $this->assertGreaterThan($base, $aplicado['nivel'], 'O aplicado foi lido fora da transação da exclusão.');
        $this->assertGreaterThan($base, $apagou['nivel']);
        $this->assertModelMissing($this->inv);

        if (DB::getDriverName() === 'mysql') {
            $trava = fn (string $tabela) => collect($consultas)->search(fn ($c) => str_contains($c['sql'], 'for update')
                && str_contains($c['sql'], 'from '.$gramatica->wrapTable($tabela)));
            $this->assertNotFalse($trava('accounts'));
            $this->assertNotFalse($trava('investments'));
            $this->assertLessThan($trava('investments'), $trava('accounts'), 'Ordem da trava: contas antes do investimento.');
        }
    }

    public function test_a_conta_que_ficou_no_vermelho_depois_da_autorizacao_ainda_barra_a_exclusao(): void
    {
        // Uma despesa entra depois que a exclusão foi autorizada: a decisão (que vem depois,
        // sob a trava) enxerga a conta no vermelho.
        $rodou = false;
        Gate::after(function () use (&$rodou) {
            if (! $rodou) {
                $rodou = true;
                Transaction::factory()->for($this->user)->for($this->conta)->expense()->create(['amount' => 900]);
            }
        });

        $this->actingAs($this->user)->from(route('investimentos.index'))
            ->delete(route('investimentos.destroy', $this->inv))
            ->assertSessionHasErrors('investimento');

        $this->assertModelExists($this->inv);
    }

    public function test_investimento_ja_excluido_por_outra_pessoa_nao_vira_erro(): void
    {
        $rodou = false;
        Gate::after(function () use (&$rodou) {
            if (! $rodou) {
                $rodou = true;
                Investment::whereKey($this->inv->id)->delete();
            }
        });

        $this->actingAs($this->user)->delete(route('investimentos.destroy', $this->inv))
            ->assertRedirect(route('investimentos.index'))
            ->assertSessionHasNoErrors();
    }
}
