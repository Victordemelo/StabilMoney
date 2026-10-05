<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Caminho feliz do CRUD de transações (criar, listar, editar, excluir).
 */
class TransactionCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = Account::factory()->for($this->user)->create();
    }

    public function test_transaction_can_be_created_with_category(): void
    {
        $category = Category::factory()->income()->for($this->user)->create();

        $response = $this->actingAs($this->user)->post('/transactions', [
            'type' => 'income',
            'amount' => '3.500,00',
            'account_id' => $this->account->id,
            'category_id' => $category->id,
            'description' => 'Salário de junho',
            'date' => now()->toDateString(),
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('transactions.index'));

        $transaction = Transaction::where('user_id', $this->user->id)->firstOrFail();
        $this->assertSame('income', $transaction->type);
        $this->assertSame('3500.00', (string) $transaction->amount);
        $this->assertSame($category->id, $transaction->category_id);
        $this->assertSame($this->account->id, $transaction->account_id);
    }

    public function test_transactions_index_lists_user_transactions(): void
    {
        Transaction::factory()->for($this->user)->for($this->account)->expense()->create([
            'description' => 'Conta de luz',
            'date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->user)->get('/transactions');

        $response->assertOk();
        $response->assertSee('Conta de luz');
    }

    public function test_transaction_create_and_edit_pages_render(): void
    {
        $transaction = Transaction::factory()->for($this->user)->for($this->account)->create();

        $this->actingAs($this->user)->get('/transactions/create')->assertOk();
        $this->actingAs($this->user)->get("/transactions/{$transaction->id}/edit")->assertOk();
    }

    /**
     * Lançamento AGENDADO (data futura): desde out/2026 o valor de movimentação já
     * paga/recebida não muda — `MovimentacaoPagaNaoMudaDeValorTest`. Agendada, ela
     * segue editável por inteiro.
     */
    public function test_transaction_can_be_updated(): void
    {
        $agendada = now()->addDays(5)->toDateString();
        $transaction = Transaction::factory()->for($this->user)->for($this->account)->expense()->create([
            'description' => 'Descrição antiga',
            'amount' => 10,
            'date' => $agendada,
        ]);

        $response = $this->actingAs($this->user)->put("/transactions/{$transaction->id}", [
            'type' => 'expense',
            'amount' => '99,90',
            'account_id' => $this->account->id,
            'description' => 'Descrição nova',
            'date' => $agendada,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('transactions.index'));

        $transaction->refresh();
        $this->assertSame('Descrição nova', $transaction->description);
        $this->assertSame('99.90', (string) $transaction->amount);
    }

    /** Já paga (data de hoje): o valor é recusado; a descrição continua editável. */
    public function test_paid_transaction_keeps_its_amount_but_description_can_be_updated(): void
    {
        $transaction = Transaction::factory()->for($this->user)->for($this->account)->expense()->create([
            'description' => 'Descrição antiga',
            'amount' => 10,
            'date' => now()->toDateString(),
        ]);

        $payload = [
            'type' => 'expense',
            'amount' => '99,90',
            'account_id' => $this->account->id,
            'description' => 'Descrição nova',
            'date' => now()->toDateString(),
        ];

        $this->actingAs($this->user)->from(route('transactions.edit', $transaction))
            ->put("/transactions/{$transaction->id}", $payload)
            ->assertSessionHasErrors('amount');

        $transaction->refresh();
        $this->assertSame('Descrição antiga', $transaction->description, 'A edição recusada não grava nada.');
        $this->assertSame('10.00', (string) $transaction->amount);

        $this->actingAs($this->user)
            ->put("/transactions/{$transaction->id}", ['amount' => '10,00'] + $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('transactions.index'));

        $transaction->refresh();
        $this->assertSame('Descrição nova', $transaction->description);
        $this->assertSame('10.00', (string) $transaction->amount);
    }

    public function test_transaction_can_be_deleted(): void
    {
        $transaction = Transaction::factory()->for($this->user)->for($this->account)->create();

        $response = $this->actingAs($this->user)->delete("/transactions/{$transaction->id}");

        $response->assertRedirect(route('transactions.index'));
        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
    }
}
