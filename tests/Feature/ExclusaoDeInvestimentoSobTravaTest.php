<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Investment;
use App\Models\InvestmentContribution;
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
 * A decisão ("ainda tem dinheiro aplicado?" — desde out/2026 o investimento só sai zerado,
 * `ExclusaoDeInvestimentoSoZeradoTest`) e o DELETE rodam na mesma transação, com o
 * investimento travado. Antes eram passos soltos: um aporte concorrente podia ser confirmado
 * entre a conferência e a exclusão e era apagado junto pelo cascade.
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
            $this->assertTrue(collect($consultas)->contains(fn ($c) => str_contains($c['sql'], 'for update')
                && str_contains($c['sql'], 'from '.$gramatica->wrapTable('investments'))));
        }
    }

    public function test_aporte_que_entra_depois_da_autorizacao_ainda_barra_a_exclusao(): void
    {
        // O aporte de outra pessoa entra depois que a exclusão foi autorizada: a decisão (que
        // vem depois, sob a trava) enxerga o dinheiro aplicado.
        $rodou = false;
        Gate::after(function () use (&$rodou) {
            if (! $rodou) {
                $rodou = true;
                InvestmentContribution::factory()->for($this->inv)->for($this->conta)->aporte()
                    ->create(['amount' => 200, 'date' => CarbonImmutable::today()->toDateString()]);
            }
        });

        $this->actingAs($this->user)->from(route('investimentos.index'))
            ->delete(route('investimentos.destroy', $this->inv))
            ->assertSessionHasErrors('investimento');

        $this->assertModelExists($this->inv);
        $this->assertSame(200.0, (float) $this->inv->fresh()->aplicado);
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
