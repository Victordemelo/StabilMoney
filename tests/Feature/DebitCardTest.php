<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cartão de débito: não tem saldo próprio — espelha a Conta Corrente e/ou
 * Poupança vinculadas (saldos mostrados separados, total somado).
 */
class DebitCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_debit_card_mirrors_linked_accounts(): void
    {
        $user = User::factory()->create();
        $corrente = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 5000]);
        $poupanca = Account::factory()->for($user)->create(['type' => 'savings', 'initial_balance' => 1000]);

        $this->actingAs($user)->post('/accounts', [
            'name' => 'Débito Nubank',
            'type' => 'debit_card',
            'bank' => 'nubank',
            'checking_account_id' => $corrente->id,
            'savings_account_id' => $poupanca->id,
        ])->assertSessionHasNoErrors();

        $debito = Account::where('type', 'debit_card')->firstOrFail();
        $this->assertSame(5000.0, $debito->checkingBalance);
        $this->assertSame(1000.0, $debito->savingsBalance);
        $this->assertSame(6000.0, $debito->balance); // corrente + poupança

        // A tela mostra os dois saldos separados e o total.
        $this->actingAs($user)->get('/accounts')->assertOk()
            ->assertSee('5.000,00')
            ->assertSee('1.000,00')
            ->assertSee('6.000,00');
    }

    public function test_debit_card_requires_at_least_one_linked_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/accounts', [
            'name' => 'Débito Solto',
            'type' => 'debit_card',
            'bank' => 'nubank',
        ])->assertSessionHasErrors('checking_account_id');
    }

    public function test_debit_card_link_must_be_correct_type(): void
    {
        $user = User::factory()->create();
        // Tentar vincular uma POUPANÇA no campo de corrente deve falhar.
        $poupanca = Account::factory()->for($user)->create(['type' => 'savings']);

        $this->actingAs($user)->post('/accounts', [
            'name' => 'Débito',
            'type' => 'debit_card',
            'bank' => 'nubank',
            'checking_account_id' => $poupanca->id,
        ])->assertSessionHasErrors('checking_account_id');
    }
}
