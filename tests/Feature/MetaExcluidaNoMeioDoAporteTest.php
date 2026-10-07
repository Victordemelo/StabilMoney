<?php

namespace Tests\Feature;

use App\Http\Requests\StoreGoalContributionRequest;
use App\Http\Requests\StoreInvestmentContributionRequest;
use App\Models\Account;
use App\Models\Goal;
use App\Models\Investment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\SimulaRequisicaoConcorrente;
use Tests\TestCase;

/**
 * Excluir a meta ao mesmo tempo que alguém aporta nela (out/2026 — auditoria de
 * concorrência, pendência 3).
 *
 * Dois defeitos do mesmo instante:
 *  - a exclusão lia "está zerada" e apagava depois, sem trava: um aporte que entrasse no meio
 *    era apagado junto pelo cascade, com a tela dele dizendo que entrou;
 *  - o aporte que chegava DEPOIS da exclusão caía no `lockParent`, que devolvia a meta já
 *    apagada (`?? $parent`): a movimentação ia para um id inexistente — erro 500 da chave
 *    estrangeira.
 */
class MetaExcluidaNoMeioDoAporteTest extends TestCase
{
    use RefreshDatabase;
    use SimulaRequisicaoConcorrente;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Corrente', 'initial_balance' => 1000, 'overdraft_limit' => 0,
        ]);
    }

    private function aporte(): array
    {
        return ['account_id' => $this->conta->id, 'amount' => '200,00', 'date' => CarbonImmutable::today()->toDateString()];
    }

    public function test_aporte_numa_meta_excluida_no_meio_nao_grava_nem_quebra(): void
    {
        $meta = Goal::factory()->for($this->user)->create(['name' => 'Viagem', 'target_amount' => 5000]);
        // Outra pessoa da família exclui a meta (zerada) depois que o aporte foi validado.
        $this->depoisDaValidacaoDe(StoreGoalContributionRequest::class, fn () => $meta->delete());

        $this->actingAs($this->user)->post(route('metas.aportes.store', $meta), $this->aporte())
            ->assertRedirect(route('metas.index'))
            ->assertSessionHas('erro', 'A meta “Viagem” foi excluída enquanto você fazia esta movimentação. Nada foi gravado.');

        $this->assertDatabaseCount('goal_contributions', 0);
        $this->assertEqualsWithDelta(1000, $this->conta->fresh()->available, 0.001);
    }

    public function test_aporte_num_investimento_excluido_no_meio_tambem(): void
    {
        $inv = Investment::create([
            'user_id' => $this->user->id, 'name' => 'CDB', 'classe' => 'renda_fixa', 'indexador' => 'cdi', 'taxa' => 100,
        ]);
        $this->depoisDaValidacaoDe(StoreInvestmentContributionRequest::class, fn () => $inv->delete());

        $this->actingAs($this->user)->post(route('investimentos.aportes.store', $inv), $this->aporte())
            ->assertRedirect(route('investimentos.index'))
            ->assertSessionHas('erro', 'O investimento “CDB” foi excluído enquanto você fazia esta movimentação. Nada foi gravado.');

        $this->assertDatabaseCount('investment_contributions', 0);
    }

    public function test_a_exclusao_confere_o_saldo_e_apaga_na_mesma_transacao_com_a_meta_travada(): void
    {
        $meta = Goal::factory()->for($this->user)->create(['name' => 'Viagem']);
        $base = DB::transactionLevel();
        $gramatica = DB::getQueryGrammar();

        $consultas = $this->consultasDe(fn () => $this->actingAs($this->user)
            ->delete(route('metas.destroy', $meta))->assertSessionHasNoErrors());

        $soma = collect($consultas)->first(fn ($c) => str_contains($c['sql'], 'sum(')
            && str_contains($c['sql'], $gramatica->wrapTable('goal_contributions')));
        $apagou = collect($consultas)->first(fn ($c) => str_starts_with($c['sql'], 'delete from '.$gramatica->wrapTable('goals')));

        $this->assertNotNull($soma);
        $this->assertNotNull($apagou);
        $this->assertGreaterThan($base, $soma['nivel'], 'O "está zerada?" foi lido fora da transação da exclusão.');
        $this->assertGreaterThan($base, $apagou['nivel']);
        $this->assertModelMissing($meta);

        if (DB::getDriverName() === 'mysql') {
            $this->assertTrue(collect($consultas)->contains(fn ($c) => str_contains($c['sql'], 'for update')
                && str_contains($c['sql'], $gramatica->wrapTable('goals'))));
        }
    }

    public function test_meta_ja_excluida_por_outra_pessoa_nao_vira_erro(): void
    {
        $meta = Goal::factory()->for($this->user)->create(['name' => 'Viagem']);

        // Outra aba exclui a meta depois que esta requisição a carregou e autorizou.
        $rodou = false;
        Gate::after(function () use (&$rodou, $meta) {
            if (! $rodou) {
                $rodou = true;
                Goal::whereKey($meta->id)->delete();
            }
        });

        $this->actingAs($this->user)->delete(route('metas.destroy', $meta))
            ->assertRedirect(route('metas.index'))
            ->assertSessionHasNoErrors();
        $this->assertTrue($rodou);
    }
}
