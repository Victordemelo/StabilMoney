<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Isolamento multiusuário: o usuário A nunca vê, edita, atualiza, exclui
 * ou referencia (em transações novas) os dados do usuário B.
 */
class MultiUserIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;

    private User $userB;

    private Account $accountA;

    private Account $accountB;

    private Category $categoryA;

    private Category $categoryB;

    private Transaction $transactionB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();

        $this->accountA = Account::factory()->for($this->userA)->create(['name' => 'Conta do A']);
        $this->accountB = Account::factory()->for($this->userB)->create(['name' => 'Conta Secreta do B']);

        $this->categoryA = Category::factory()->expense()->for($this->userA)->create(['name' => 'Despesas do A']);
        $this->categoryB = Category::factory()->expense()->for($this->userB)->create(['name' => 'Categoria Secreta do B']);

        $this->transactionB = Transaction::factory()
            ->for($this->userB)
            ->for($this->accountB)
            ->expense()
            ->create([
                'category_id' => $this->categoryB->id,
                'description' => 'Compra secreta do B',
                'amount' => 123.45,
                'date' => now()->toDateString(),
            ]);
    }

    // ----- Listagens não vazam dados de outro usuário -----

    public function test_transactions_index_does_not_show_other_users_transactions(): void
    {
        $response = $this->actingAs($this->userA)->get('/transactions');

        $response->assertOk();
        $response->assertDontSee('Compra secreta do B');
        $response->assertDontSee('Conta Secreta do B');
    }

    public function test_accounts_index_does_not_show_other_users_accounts(): void
    {
        $response = $this->actingAs($this->userA)->get('/accounts');

        $response->assertOk();
        $response->assertSee('Conta do A');
        $response->assertDontSee('Conta Secreta do B');
    }

    public function test_categories_index_does_not_show_other_users_categories(): void
    {
        $response = $this->actingAs($this->userA)->get('/categories');

        $response->assertOk();
        $response->assertSee('Despesas do A');
        $response->assertDontSee('Categoria Secreta do B');
    }

    public function test_dashboard_does_not_show_other_users_data(): void
    {
        $response = $this->actingAs($this->userA)->get('/');

        $response->assertOk();
        $response->assertDontSee('Compra secreta do B');
        $response->assertDontSee('Conta Secreta do B');
        // A não tem transações: o payload dele deve indicar hasData false
        $response->assertSee('"hasData":false', false);
    }

    // ----- GET edit de recurso alheio => 403 -----

    public function test_user_cannot_view_edit_form_of_others_transaction(): void
    {
        $this->actingAs($this->userA)
            ->get("/transactions/{$this->transactionB->id}/edit")
            ->assertForbidden();
    }

    public function test_user_cannot_view_edit_form_of_others_account(): void
    {
        $this->actingAs($this->userA)
            ->get("/accounts/{$this->accountB->id}/edit")
            ->assertForbidden();
    }

    public function test_user_cannot_view_edit_form_of_others_category(): void
    {
        $this->actingAs($this->userA)
            ->get("/categories/{$this->categoryB->id}/edit")
            ->assertForbidden();
    }

    // ----- PUT em recurso alheio => 403 (payload válido para passar da validação) -----

    public function test_user_cannot_update_others_transaction(): void
    {
        $response = $this->actingAs($this->userA)->put("/transactions/{$this->transactionB->id}", [
            'type' => 'expense',
            'amount' => '10,00',
            'account_id' => $this->accountA->id,
            'category_id' => $this->categoryA->id,
            'description' => 'Tentativa de invasão',
            'date' => now()->toDateString(),
        ]);

        $response->assertForbidden();
        $this->assertSame('Compra secreta do B', $this->transactionB->fresh()->description);
    }

    public function test_user_cannot_update_others_account(): void
    {
        $response = $this->actingAs($this->userA)->put("/accounts/{$this->accountB->id}", [
            'name' => 'Conta Hackeada',
            'type' => 'bank',
            'initial_balance' => '0,00',
        ]);

        $response->assertForbidden();
        $this->assertSame('Conta Secreta do B', $this->accountB->fresh()->name);
    }

    public function test_user_cannot_update_others_category(): void
    {
        $response = $this->actingAs($this->userA)->put("/categories/{$this->categoryB->id}", [
            'name' => 'Categoria Hackeada',
            'type' => 'expense',
        ]);

        $response->assertForbidden();
        $this->assertSame('Categoria Secreta do B', $this->categoryB->fresh()->name);
    }

    public function test_user_cannot_move_others_category_via_json_patch(): void
    {
        // Mesmo fluxo do drag & drop (PATCH JSON trocando o type): 403 + nada muda
        $response = $this->actingAs($this->userA)->patchJson("/categories/{$this->categoryB->id}", [
            'name' => $this->categoryB->name,
            'type' => 'income',
            'color' => $this->categoryB->color,
            'icon' => $this->categoryB->icon,
        ]);

        $response->assertForbidden();
        $this->assertSame('expense', $this->categoryB->fresh()->type);
    }

    // ----- DELETE em recurso alheio => 403 -----

    public function test_user_cannot_delete_others_transaction(): void
    {
        $this->actingAs($this->userA)
            ->delete("/transactions/{$this->transactionB->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('transactions', ['id' => $this->transactionB->id]);
    }

    public function test_user_cannot_delete_others_account(): void
    {
        $this->actingAs($this->userA)
            ->delete("/accounts/{$this->accountB->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('accounts', ['id' => $this->accountB->id]);
    }

    public function test_user_cannot_delete_others_category(): void
    {
        $this->actingAs($this->userA)
            ->delete("/categories/{$this->categoryB->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('categories', ['id' => $this->categoryB->id]);
    }

    // ----- Criar transação apontando para conta/categoria de outro usuário => erro de validação -----

    public function test_user_cannot_create_transaction_with_others_account(): void
    {
        $response = $this->actingAs($this->userA)->post('/transactions', [
            'type' => 'expense',
            'amount' => '50,00',
            'account_id' => $this->accountB->id,
            'description' => 'Tentando usar conta alheia',
            'date' => now()->toDateString(),
        ]);

        $response->assertSessionHasErrors('account_id');
        $this->assertDatabaseMissing('transactions', ['description' => 'Tentando usar conta alheia']);
    }

    public function test_user_cannot_create_transaction_with_others_category(): void
    {
        $response = $this->actingAs($this->userA)->post('/transactions', [
            'type' => 'expense',
            'amount' => '50,00',
            'account_id' => $this->accountA->id,
            'category_id' => $this->categoryB->id, // mesma natureza (expense), mas dona é B
            'description' => 'Tentando usar categoria alheia',
            'date' => now()->toDateString(),
        ]);

        $response->assertSessionHasErrors('category_id');
        $this->assertDatabaseMissing('transactions', ['description' => 'Tentando usar categoria alheia']);
    }
}
