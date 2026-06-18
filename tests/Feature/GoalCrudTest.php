<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalContribution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Metas (modelo "cofrinho"): criar/editar/excluir metas e movimentá-las
 * (aporte/resgate). Testamos só as AÇÕES (redirect + estado no banco) — a
 * Blade de listagem é construída por outro agente.
 *
 * Regra-chave: aporte NÃO cria transação. Ele reserva dinheiro — o disponível
 * da conta de origem cai, o guardado da meta sobe e o saldo cru (patrimônio
 * total) não muda.
 */
class GoalCrudTest extends TestCase
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
            'type' => 'bank',
            'initial_balance' => 1000,
        ]);
    }

    public function test_goal_can_be_created(): void
    {
        $response = $this->actingAs($this->user)->post('/metas', [
            'name' => 'Viagem para a praia',
            'target_amount' => '5.000,00',
            'target_date' => now()->addYear()->toDateString(),
            'emoji' => '✈️',
            'color' => '#1FA06E',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('metas.index'));

        $this->assertDatabaseHas('goals', [
            'user_id' => $this->user->id,
            'made_by_user_id' => $this->user->id,
            'name' => 'Viagem para a praia',
            'target_amount' => '5000.00',
            'emoji' => '✈️',
            'color' => '#1FA06E',
        ]);
    }

    public function test_goal_target_date_accepts_month_input(): void
    {
        // O <input type="month"> envia "AAAA-MM"; deve virar o 1º dia do mês.
        $this->actingAs($this->user)->post('/metas', [
            'name' => 'Entrada do apê',
            'target_amount' => '60.000,00',
            'target_date' => now()->addYear()->format('Y-m'),
            'emoji' => '🏠',
            'color' => '#0F6B47',
        ])->assertSessionHasNoErrors();

        $goal = Goal::where('name', 'Entrada do apê')->firstOrFail();
        $this->assertSame(now()->addYear()->format('Y-m') . '-01', $goal->target_date->format('Y-m-d'));
    }

    public function test_goal_creation_validates_required_fields(): void
    {
        $this->actingAs($this->user)->post('/metas', [
            'name' => '',
            'target_amount' => '0',
            'emoji' => '',
            'color' => 'azul',
        ])->assertSessionHasErrors(['name', 'target_amount', 'emoji', 'color']);
    }

    public function test_goal_can_be_updated(): void
    {
        $goal = Goal::factory()->for($this->user)->create(['name' => 'Nome antigo']);

        $response = $this->actingAs($this->user)->patch("/metas/{$goal->id}", [
            'name' => 'Nome novo',
            'target_amount' => '7.500,50',
            'emoji' => '🎯',
            'color' => '#0F6B47',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('metas.index'));

        $goal->refresh();
        $this->assertSame('Nome novo', $goal->name);
        $this->assertSame('7500.50', (string) $goal->target_amount);
    }

    public function test_goal_can_be_deleted(): void
    {
        $goal = Goal::factory()->for($this->user)->create();

        $this->actingAs($this->user)->delete("/metas/{$goal->id}")
            ->assertRedirect(route('metas.index'));

        $this->assertDatabaseMissing('goals', ['id' => $goal->id]);
    }

    public function test_contribution_reserves_money_without_changing_patrimonio(): void
    {
        $goal = Goal::factory()->for($this->user)->create(['target_amount' => 2000]);

        $saldoAntes = $this->account->balance; // saldo cru = patrimônio total

        $response = $this->actingAs($this->user)->post("/metas/{$goal->id}/aportes", [
            'amount' => '300,00',
            'account_id' => $this->account->id,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('metas.index'));

        $this->assertDatabaseHas('goal_contributions', [
            'goal_id' => $goal->id,
            'account_id' => $this->account->id,
            'made_by_user_id' => $this->user->id,
            'type' => 'aporte',
            'amount' => '300.00',
        ]);

        // Aporte NÃO cria transação.
        $this->assertDatabaseCount('transactions', 0);

        $goal->refresh();
        $this->account->refresh();

        // Guardado da meta sobe; disponível da conta cai; saldo cru não muda.
        $this->assertSame(300.0, $goal->saved);
        $this->assertSame($saldoAntes, $this->account->balance);
        $this->assertSame(round($saldoAntes - 300, 2), $this->account->available);
    }

    public function test_contribution_above_available_is_rejected(): void
    {
        $goal = Goal::factory()->for($this->user)->create();

        // Conta tem 1000 disponível; tentar aportar 1.500 deve falhar.
        $this->actingAs($this->user)->post("/metas/{$goal->id}/aportes", [
            'amount' => '1.500,00',
            'account_id' => $this->account->id,
        ])->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('goal_contributions', 0);
    }

    public function test_contribution_already_reserved_reduces_available(): void
    {
        $goal = Goal::factory()->for($this->user)->create();

        // Já reservou 800 desta conta (disponível agora = 200).
        GoalContribution::factory()->for($goal)->for($this->account)->aporte()
            ->create(['amount' => 800]);

        // Aportar mais 300 estoura o disponível (200) e deve falhar.
        $this->actingAs($this->user)->post("/metas/{$goal->id}/aportes", [
            'amount' => '300,00',
            'account_id' => $this->account->id,
        ])->assertSessionHasErrors('amount');

        // Aportar 150 (dentro do disponível) passa.
        $this->actingAs($this->user)->post("/metas/{$goal->id}/aportes", [
            'amount' => '150,00',
            'account_id' => $this->account->id,
        ])->assertSessionHasNoErrors();
    }

    public function test_contribution_from_credit_card_is_rejected(): void
    {
        $card = Account::factory()->for($this->user)->create([
            'type' => 'credit_card',
            'initial_balance' => 5000,
        ]);
        $goal = Goal::factory()->for($this->user)->create();

        $this->actingAs($this->user)->post("/metas/{$goal->id}/aportes", [
            'amount' => '100,00',
            'account_id' => $card->id,
        ])->assertSessionHasErrors('account_id');

        $this->assertDatabaseCount('goal_contributions', 0);
    }

    public function test_withdraw_returns_money_to_account(): void
    {
        $goal = Goal::factory()->for($this->user)->create();
        GoalContribution::factory()->for($goal)->for($this->account)->aporte()
            ->create(['amount' => 500]);

        $this->assertSame(500.0, $goal->fresh()->saved);

        $response = $this->actingAs($this->user)->post("/metas/{$goal->id}/resgates", [
            'amount' => '200,00',
            'account_id' => $this->account->id,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('metas.index'));

        $this->assertDatabaseHas('goal_contributions', [
            'goal_id' => $goal->id,
            'account_id' => $this->account->id,
            'type' => 'resgate',
            'amount' => '200.00',
        ]);

        $goal->refresh();
        $this->account->refresh();

        // Guardado cai (500 - 200); disponível da conta volta a subir.
        $this->assertSame(300.0, $goal->saved);
        // Reservado = 500 aporte - 200 resgate = 300; disponível = 1000 - 300.
        $this->assertSame(700.0, $this->account->available);
    }

    public function test_withdraw_above_saved_is_rejected(): void
    {
        $goal = Goal::factory()->for($this->user)->create();
        GoalContribution::factory()->for($goal)->for($this->account)->aporte()
            ->create(['amount' => 100]);

        // Só há 100 guardado; resgatar 250 deve falhar.
        $this->actingAs($this->user)->post("/metas/{$goal->id}/resgates", [
            'amount' => '250,00',
            'account_id' => $this->account->id,
        ])->assertSessionHasErrors('amount');

        $this->assertSame(100.0, $goal->fresh()->saved);
    }

    public function test_contribution_author_defaults_to_current_user(): void
    {
        $titular = User::factory()->create();
        $account = Account::factory()->for($titular)->create(['type' => 'bank', 'initial_balance' => 500]);
        $goal = Goal::factory()->for($titular)->create();
        $dependent = User::factory()->create(['account_owner_id' => $titular->id]);

        $this->actingAs($dependent)->post("/metas/{$goal->id}/aportes", [
            'amount' => '50,00',
            'account_id' => $account->id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('goal_contributions', [
            'goal_id' => $goal->id,
            'made_by_user_id' => $dependent->id,
        ]);
    }

    public function test_account_with_contribution_cannot_be_deleted(): void
    {
        $goal = Goal::factory()->for($this->user)->create();
        GoalContribution::factory()->for($goal)->for($this->account)->aporte()
            ->create(['amount' => 100]);

        $this->actingAs($this->user)->delete("/accounts/{$this->account->id}")
            ->assertSessionHasErrors('account');

        $this->assertDatabaseHas('accounts', ['id' => $this->account->id]);
    }

    // ----- Isolamento de família -----

    public function test_dependent_can_manage_family_goals(): void
    {
        $titular = User::factory()->create();
        $account = Account::factory()->for($titular)->create(['type' => 'bank', 'initial_balance' => 1000]);
        $goal = Goal::factory()->for($titular)->create(['name' => 'Meta da familia']);
        $dependent = User::factory()->create(['account_owner_id' => $titular->id]);

        // Dependente edita a meta da família (mesma visão do titular).
        $this->actingAs($dependent)->patch("/metas/{$goal->id}", [
            'name' => 'Meta editada pelo dependente',
            'target_amount' => '1.000,00',
            'emoji' => '🎯',
            'color' => '#1FA06E',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Meta editada pelo dependente', $goal->fresh()->name);

        // E aporta numa conta da família.
        $this->actingAs($dependent)->post("/metas/{$goal->id}/aportes", [
            'amount' => '100,00',
            'account_id' => $account->id,
        ])->assertSessionHasNoErrors();
    }

    public function test_user_cannot_update_other_family_goal(): void
    {
        $other = User::factory()->create();
        $goal = Goal::factory()->for($other)->create();

        $this->actingAs($this->user)->patch("/metas/{$goal->id}", [
            'name' => 'Invasão',
            'target_amount' => '10,00',
            'emoji' => '🎯',
            'color' => '#1FA06E',
        ])->assertForbidden();

        $this->assertDatabaseMissing('goals', ['name' => 'Invasão']);
    }

    public function test_user_cannot_delete_other_family_goal(): void
    {
        $other = User::factory()->create();
        $goal = Goal::factory()->for($other)->create();

        $this->actingAs($this->user)->delete("/metas/{$goal->id}")->assertForbidden();

        $this->assertDatabaseHas('goals', ['id' => $goal->id]);
    }

    public function test_user_cannot_contribute_to_other_family_goal(): void
    {
        $other = User::factory()->create();
        $goal = Goal::factory()->for($other)->create();

        $this->actingAs($this->user)->post("/metas/{$goal->id}/aportes", [
            'amount' => '10,00',
            'account_id' => $this->account->id,
        ])->assertForbidden();

        $this->assertDatabaseCount('goal_contributions', 0);
    }

    public function test_contribution_account_must_belong_to_family(): void
    {
        $goal = Goal::factory()->for($this->user)->create();
        // Conta de outra família.
        $stranger = User::factory()->create();
        $strangerAccount = Account::factory()->for($stranger)->create(['type' => 'bank', 'initial_balance' => 1000]);

        $this->actingAs($this->user)->post("/metas/{$goal->id}/aportes", [
            'amount' => '10,00',
            'account_id' => $strangerAccount->id,
        ])->assertSessionHasErrors('account_id');
    }
}
