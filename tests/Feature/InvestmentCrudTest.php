<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalContribution;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Investimentos (modelo "cofrinho", igual à meta): criar/editar/excluir e
 * movimentar (aporte/resgate). Testamos só as AÇÕES (redirect + estado no
 * banco) — a Blade de listagem é construída por outro agente (não fazemos
 * GET /investimentos aqui).
 *
 * Regra-chave: aporte NÃO cria transação. Ele reserva dinheiro — o disponível
 * da conta de origem cai, o aplicado do investimento sobe e o saldo cru
 * (patrimônio total) não muda.
 */
class InvestmentCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        // Conta com saldo inicial conhecido para checar o disponível.
        $this->account = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'initial_balance' => 1000,
        ]);
    }

    public function test_investment_can_be_created_without_initial_amount(): void
    {
        $response = $this->actingAs($this->user)->post('/investimentos', [
            'name' => 'Tesouro Selic 2029',
            'classe' => 'renda_fixa',
            'indexador' => 'Selic',
            'taxa' => '100,00',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('investimentos.index'));

        $this->assertDatabaseHas('investments', [
            'user_id' => $this->user->id,
            'made_by_user_id' => $this->user->id,
            'name' => 'Tesouro Selic 2029',
            'classe' => 'renda_fixa',
            'indexador' => 'Selic',
            'taxa' => '100.00',
        ]);

        // Sem valor inicial => nenhuma movimentação criada.
        $this->assertDatabaseCount('investment_contributions', 0);
    }

    public function test_investment_can_be_created_with_initial_amount(): void
    {
        $saldoAntes = $this->account->balance;

        $response = $this->actingAs($this->user)->post('/investimentos', [
            'name' => 'CDB 110% CDI',
            'classe' => 'renda_fixa',
            'indexador' => 'CDI',
            'taxa' => '110,00',
            'valor_inicial' => '400,00',
            'account_id' => $this->account->id,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('investimentos.index'));

        $investment = Investment::where('name', 'CDB 110% CDI')->firstOrFail();

        // Aporte inicial criado a partir da conta escolhida.
        $this->assertDatabaseHas('investment_contributions', [
            'investment_id' => $investment->id,
            'account_id' => $this->account->id,
            'made_by_user_id' => $this->user->id,
            'type' => 'aporte',
            'amount' => '400.00',
        ]);

        // Aporte inicial NÃO cria transação.
        $this->assertDatabaseCount('transactions', 0);

        $this->account->refresh();

        // Aplicado sobe; saldo cru não muda; disponível cai.
        $this->assertSame(400.0, $investment->aplicado);
        $this->assertSame($saldoAntes, $this->account->balance);
        $this->assertSame(round($saldoAntes - 400, 2), $this->account->available);
    }

    public function test_initial_amount_above_available_is_rejected(): void
    {
        // Conta tem 1000 disponível; valor inicial de 1.500 deve falhar.
        $this->actingAs($this->user)->post('/investimentos', [
            'name' => 'Estouro',
            'classe' => 'renda_fixa',
            'indexador' => 'CDI',
            'taxa' => '110,00',
            'valor_inicial' => '1.500,00',
            'account_id' => $this->account->id,
        ])->assertSessionHasErrors('account_id');

        $this->assertDatabaseMissing('investments', ['name' => 'Estouro']);
        $this->assertDatabaseCount('investment_contributions', 0);
    }

    public function test_initial_amount_requires_account(): void
    {
        // Valor inicial sem conta de origem deve falhar.
        $this->actingAs($this->user)->post('/investimentos', [
            'name' => 'Sem conta',
            'classe' => 'renda_fixa',
            'valor_inicial' => '100,00',
        ])->assertSessionHasErrors('account_id');

        $this->assertDatabaseMissing('investments', ['name' => 'Sem conta']);
    }

    public function test_investment_creation_validates_required_fields(): void
    {
        $this->actingAs($this->user)->post('/investimentos', [
            'name' => '',
            'classe' => 'inexistente',
        ])->assertSessionHasErrors(['name', 'classe']);
    }

    public function test_investment_can_be_updated_without_touching_aplicado(): void
    {
        $investment = Investment::factory()->for($this->user)->create(['name' => 'Nome antigo']);
        InvestmentContribution::factory()->for($investment)->for($this->account)->aporte()
            ->create(['amount' => 300]);

        $response = $this->actingAs($this->user)->patch("/investimentos/{$investment->id}", [
            'name' => 'Nome novo',
            'classe' => 'fundos',
            'indexador' => 'IPCA+',
            'taxa' => '6,00',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('investimentos.index'));

        $investment->refresh();
        $this->assertSame('Nome novo', $investment->name);
        $this->assertSame('fundos', $investment->classe);
        // Editar metadados não mexe no aplicado.
        $this->assertSame(300.0, $investment->aplicado);
    }

    public function test_investment_can_be_deleted(): void
    {
        $investment = Investment::factory()->for($this->user)->create();

        $this->actingAs($this->user)->delete("/investimentos/{$investment->id}")
            ->assertRedirect(route('investimentos.index'));

        $this->assertDatabaseMissing('investments', ['id' => $investment->id]);
    }

    public function test_contribution_reserves_money_without_changing_patrimonio(): void
    {
        $investment = Investment::factory()->for($this->user)->create();

        $saldoAntes = $this->account->balance; // saldo cru = patrimônio total

        $response = $this->actingAs($this->user)->post("/investimentos/{$investment->id}/aportes", [
            'amount' => '300,00',
            'account_id' => $this->account->id,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('investimentos.index'));

        $this->assertDatabaseHas('investment_contributions', [
            'investment_id' => $investment->id,
            'account_id' => $this->account->id,
            'made_by_user_id' => $this->user->id,
            'type' => 'aporte',
            'amount' => '300.00',
        ]);

        // Aporte NÃO cria transação.
        $this->assertDatabaseCount('transactions', 0);

        $investment->refresh();
        $this->account->refresh();

        // Aplicado sobe; disponível da conta cai; saldo cru não muda.
        $this->assertSame(300.0, $investment->aplicado);
        $this->assertSame($saldoAntes, $this->account->balance);
        $this->assertSame(round($saldoAntes - 300, 2), $this->account->available);
    }

    public function test_contribution_above_available_is_rejected(): void
    {
        $investment = Investment::factory()->for($this->user)->create();

        // Conta tem 1000 disponível; tentar aportar 1.500 deve falhar.
        $this->actingAs($this->user)->post("/investimentos/{$investment->id}/aportes", [
            'amount' => '1.500,00',
            'account_id' => $this->account->id,
        ])->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('investment_contributions', 0);
    }

    public function test_contribution_from_credit_card_is_rejected(): void
    {
        $card = Account::factory()->for($this->user)->create([
            'type' => 'credit_card',
            'initial_balance' => 5000,
        ]);
        $investment = Investment::factory()->for($this->user)->create();

        $this->actingAs($this->user)->post("/investimentos/{$investment->id}/aportes", [
            'amount' => '100,00',
            'account_id' => $card->id,
        ])->assertSessionHasErrors('account_id');

        $this->assertDatabaseCount('investment_contributions', 0);
    }

    public function test_withdraw_returns_money_to_account(): void
    {
        $investment = Investment::factory()->for($this->user)->create();
        InvestmentContribution::factory()->for($investment)->for($this->account)->aporte()
            ->create(['amount' => 500]);

        $this->assertSame(500.0, $investment->fresh()->aplicado);

        $response = $this->actingAs($this->user)->post("/investimentos/{$investment->id}/resgates", [
            'amount' => '200,00',
            'account_id' => $this->account->id,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('investimentos.index'));

        $this->assertDatabaseHas('investment_contributions', [
            'investment_id' => $investment->id,
            'account_id' => $this->account->id,
            'type' => 'resgate',
            'amount' => '200.00',
        ]);

        $investment->refresh();
        $this->account->refresh();

        // Aplicado cai (500 - 200); disponível da conta volta a subir.
        $this->assertSame(300.0, $investment->aplicado);
        // Reservado = 500 aporte - 200 resgate = 300; disponível = 1000 - 300.
        $this->assertSame(700.0, $this->account->available);
    }

    public function test_withdraw_above_aplicado_is_rejected(): void
    {
        $investment = Investment::factory()->for($this->user)->create();
        InvestmentContribution::factory()->for($investment)->for($this->account)->aporte()
            ->create(['amount' => 100]);

        // Só há 100 aplicado; resgatar 250 deve falhar.
        $this->actingAs($this->user)->post("/investimentos/{$investment->id}/resgates", [
            'amount' => '250,00',
            'account_id' => $this->account->id,
        ])->assertSessionHasErrors('amount');

        $this->assertSame(100.0, $investment->fresh()->aplicado);
    }

    public function test_gross_rate_accessor_matches_finance_prototype(): void
    {
        // CDI/Selic → base × taxa/100.
        $cdi = Investment::factory()->for($this->user)->create(['indexador' => 'CDI', 'taxa' => 110]);
        $this->assertSame(round(10.65 * 110 / 100, 2), $cdi->grossRate);

        // IPCA+ → 4.5 + taxa.
        $ipca = Investment::factory()->for($this->user)->create(['indexador' => 'IPCA+', 'taxa' => 6]);
        $this->assertSame(10.5, $ipca->grossRate);

        // Prefixado → taxa.
        $pre = Investment::factory()->for($this->user)->create(['indexador' => 'Prefixado', 'taxa' => 12]);
        $this->assertSame(12.0, $pre->grossRate);

        // Sem indexador → a PRÓPRIA taxa informada (renda variável/cripto/fundos),
        // igual ao grossRate do investimentos.js. Antes devolvia 0 aqui enquanto
        // o JS já usava a taxa: o card mostrava 0,0% e a prévia, 30% (auditoria M-1).
        $rv = Investment::factory()->for($this->user)->rendaVariavel()->create(['taxa' => 30]);
        $this->assertSame(30.0, $rv->grossRate);

        // Sem indexador E sem taxa → não há o que estimar.
        $semTaxa = Investment::factory()->for($this->user)->rendaVariavel()->create();
        $this->assertSame(0.0, $semTaxa->grossRate);
    }

    public function test_account_with_investment_contribution_cannot_be_deleted(): void
    {
        $investment = Investment::factory()->for($this->user)->create();
        InvestmentContribution::factory()->for($investment)->for($this->account)->aporte()
            ->create(['amount' => 100]);

        $this->actingAs($this->user)->delete("/accounts/{$this->account->id}")
            ->assertSessionHasErrors('account');

        $this->assertDatabaseHas('accounts', ['id' => $this->account->id]);
    }

    public function test_reserved_sums_goals_and_investments_together(): void
    {
        // 200 reservado em meta + 300 aplicado em investimento, mesma conta.
        $goal = Goal::factory()->for($this->user)->create();
        GoalContribution::factory()->for($goal)->for($this->account)->aporte()
            ->create(['amount' => 200]);

        $investment = Investment::factory()->for($this->user)->create();
        InvestmentContribution::factory()->for($investment)->for($this->account)->aporte()
            ->create(['amount' => 300]);

        $this->account->refresh();

        // Reservado = 200 (meta) + 300 (investimento) = 500.
        $this->assertSame(500.0, $this->account->reserved);
        // Disponível = 1000 - 500; saldo cru intacto.
        $this->assertSame(500.0, $this->account->available);
        $this->assertSame(1000.0, $this->account->balance);
    }

    // ----- Isolamento de família -----

    public function test_dependent_can_manage_family_investments(): void
    {
        $titular = User::factory()->create();
        $account = Account::factory()->for($titular)->create(['type' => 'checking', 'initial_balance' => 1000]);
        $investment = Investment::factory()->for($titular)->create(['name' => 'Invest da familia']);
        $dependent = User::factory()->create(['account_owner_id' => $titular->id]);

        // Dependente edita o investimento da família (mesma visão do titular).
        $this->actingAs($dependent)->patch("/investimentos/{$investment->id}", [
            'name' => 'Editado pelo dependente',
            'classe' => 'renda_fixa',
            'indexador' => 'CDI',
            'taxa' => '105,00',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Editado pelo dependente', $investment->fresh()->name);

        // E aporta numa conta da família — autor = o dependente.
        $this->actingAs($dependent)->post("/investimentos/{$investment->id}/aportes", [
            'amount' => '100,00',
            'account_id' => $account->id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('investment_contributions', [
            'investment_id' => $investment->id,
            'made_by_user_id' => $dependent->id,
        ]);
    }

    public function test_user_cannot_update_other_family_investment(): void
    {
        $other = User::factory()->create();
        $investment = Investment::factory()->for($other)->create();

        $this->actingAs($this->user)->patch("/investimentos/{$investment->id}", [
            'name' => 'Invasão',
            'classe' => 'cripto',
        ])->assertForbidden();

        $this->assertDatabaseMissing('investments', ['name' => 'Invasão']);
    }

    public function test_user_cannot_delete_other_family_investment(): void
    {
        $other = User::factory()->create();
        $investment = Investment::factory()->for($other)->create();

        $this->actingAs($this->user)->delete("/investimentos/{$investment->id}")->assertForbidden();

        $this->assertDatabaseHas('investments', ['id' => $investment->id]);
    }

    public function test_user_cannot_contribute_to_other_family_investment(): void
    {
        $other = User::factory()->create();
        $investment = Investment::factory()->for($other)->create();

        $this->actingAs($this->user)->post("/investimentos/{$investment->id}/aportes", [
            'amount' => '10,00',
            'account_id' => $this->account->id,
        ])->assertForbidden();

        $this->assertDatabaseCount('investment_contributions', 0);
    }

    public function test_contribution_account_must_belong_to_family(): void
    {
        $investment = Investment::factory()->for($this->user)->create();
        // Conta de outra família.
        $stranger = User::factory()->create();
        $strangerAccount = Account::factory()->for($stranger)->create(['type' => 'checking', 'initial_balance' => 1000]);

        $this->actingAs($this->user)->post("/investimentos/{$investment->id}/aportes", [
            'amount' => '10,00',
            'account_id' => $strangerAccount->id,
        ])->assertSessionHasErrors('account_id');
    }
}
