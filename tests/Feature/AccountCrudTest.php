<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CRUD de contas + regra de negócio: conta com transações não pode ser
 * excluída (a FK é cascadeOnDelete e apagaria o histórico junto).
 */
class AccountCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_account_can_be_created(): void
    {
        $response = $this->actingAs($this->user)->post('/accounts', [
            'name' => 'Banco Azul',
            'type' => 'checking',
            'bank' => 'nubank',
            'initial_balance' => '1.500,00', // vírgula pt-BR também é aceita aqui
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('accounts.index'));

        $account = Account::where('user_id', $this->user->id)->firstOrFail();
        $this->assertSame('Banco Azul', $account->name);
        $this->assertSame('checking', $account->type);
        $this->assertSame('nubank', $account->bank);
        $this->assertSame('1500.00', (string) $account->initial_balance);
    }

    public function test_negative_initial_balance_is_rejected(): void
    {
        $this->actingAs($this->user)->post('/accounts', [
            'name' => 'Conta Negativa',
            'type' => 'checking',
            'bank' => 'nubank',
            'initial_balance' => '-100,00',
        ])->assertSessionHasErrors('initial_balance');

        $this->assertDatabaseMissing('accounts', ['name' => 'Conta Negativa']);
    }

    public function test_account_create_and_edit_pages_render(): void
    {
        $account = Account::factory()->for($this->user)->create();

        $this->actingAs($this->user)->get('/accounts/create')->assertOk();
        $this->actingAs($this->user)->get("/accounts/{$account->id}/edit")->assertOk();
    }

    public function test_account_can_be_updated(): void
    {
        $account = Account::factory()->for($this->user)->create(['name' => 'Nome Antigo']);

        $response = $this->actingAs($this->user)->put("/accounts/{$account->id}", [
            'name' => 'Nome Novo',
            'type' => 'checking',
            'bank' => 'itau',
            'initial_balance' => '200,00',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('accounts.index'));

        $account->refresh();
        $this->assertSame('Nome Novo', $account->name);
        $this->assertSame('200.00', (string) $account->initial_balance);
    }

    public function test_account_without_transactions_can_be_deleted(): void
    {
        $account = Account::factory()->for($this->user)->create();

        $response = $this->actingAs($this->user)->delete("/accounts/{$account->id}");

        $response->assertRedirect(route('accounts.index'));
        $this->assertDatabaseMissing('accounts', ['id' => $account->id]);
    }

    public function test_account_with_transactions_cannot_be_deleted(): void
    {
        $account = Account::factory()->for($this->user)->create();
        $transaction = Transaction::factory()->for($this->user)->for($account)->create();

        $response = $this->actingAs($this->user)
            ->from('/accounts')
            ->delete("/accounts/{$account->id}");

        // Bloqueado com erro explicativo; conta e histórico permanecem
        $response->assertRedirect('/accounts');
        $response->assertSessionHasErrors('account');
        $this->assertDatabaseHas('accounts', ['id' => $account->id]);
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
    }
}
