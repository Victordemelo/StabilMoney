<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Validações de transação: valor positivo, tipo válido, categoria do mesmo
 * tipo da transação e aceitação de vírgula decimal (padrão pt-BR).
 */
class TransactionValidationTest extends TestCase
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

    /** Payload válido de base; sobrescreva só o campo em teste. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'expense',
            'amount' => '25,90',
            'account_id' => $this->account->id,
            'description' => 'Teste de validação',
            'date' => now()->toDateString(),
        ], $overrides);
    }

    public function test_amount_zero_is_rejected(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/transactions', $this->payload(['amount' => '0']));

        $response->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_negative_amount_is_rejected(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/transactions', $this->payload(['amount' => '-5,00']));

        $response->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_invalid_type_is_rejected(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/transactions', $this->payload(['type' => 'transfer']));

        $response->assertSessionHasErrors('type');
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_category_of_mismatched_type_is_rejected(): void
    {
        // Categoria de receita numa transação de despesa => inválido
        $incomeCategory = Category::factory()->income()->for($this->user)->create();

        $response = $this->actingAs($this->user)->post('/transactions', $this->payload([
            'type' => 'expense',
            'category_id' => $incomeCategory->id,
        ]));

        $response->assertSessionHasErrors('category_id');
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_comma_decimal_amount_is_accepted_and_normalized(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/transactions', $this->payload(['amount' => '12,50']));

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('transactions.index'));

        $transaction = Transaction::where('user_id', $this->user->id)->firstOrFail();
        $this->assertSame('12.50', (string) $transaction->amount);
    }

    public function test_thousand_separator_with_comma_is_normalized(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/transactions', $this->payload(['amount' => '1.234,56']));

        $response->assertSessionHasNoErrors();

        $transaction = Transaction::where('user_id', $this->user->id)->firstOrFail();
        $this->assertSame('1234.56', (string) $transaction->amount);
    }

    public function test_date_before_year_2000_is_rejected(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/transactions', $this->payload(['date' => '1999-12-31']));

        $response->assertSessionHasErrors('date');
        $this->assertDatabaseCount('transactions', 0);
    }
}
