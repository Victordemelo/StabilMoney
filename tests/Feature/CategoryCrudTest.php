<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CRUD de categorias. Excluir categoria é permitido: a FK de transações é
 * nullOnDelete, então as transações associadas ficam "Sem categoria".
 */
class CategoryCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_category_can_be_created(): void
    {
        $response = $this->actingAs($this->user)->post('/categories', [
            'name' => 'Assinaturas',
            'type' => 'expense',
            'color' => '#F0A93B',
            'icon' => '📺',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('categories.index'));

        $this->assertDatabaseHas('categories', [
            'user_id' => $this->user->id,
            'name' => 'Assinaturas',
            'type' => 'expense',
        ]);
    }

    public function test_category_create_and_edit_pages_render(): void
    {
        $category = Category::factory()->expense()->for($this->user)->create();

        $this->actingAs($this->user)->get('/categories/create')->assertOk();
        $this->actingAs($this->user)->get("/categories/{$category->id}/edit")->assertOk();
    }

    public function test_category_can_be_updated(): void
    {
        $category = Category::factory()->expense()->for($this->user)->create(['name' => 'Nome Antigo']);

        $response = $this->actingAs($this->user)->put("/categories/{$category->id}", [
            'name' => 'Nome Novo',
            'type' => 'expense',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('categories.index'));
        $this->assertSame('Nome Novo', $category->fresh()->name);
    }

    public function test_category_can_be_deleted_and_transactions_become_uncategorized(): void
    {
        $account = Account::factory()->for($this->user)->create();
        $category = Category::factory()->expense()->for($this->user)->create();
        $transaction = Transaction::factory()
            ->for($this->user)
            ->for($account)
            ->expense()
            ->create(['category_id' => $category->id]);

        $response = $this->actingAs($this->user)->delete("/categories/{$category->id}");

        $response->assertRedirect(route('categories.index'));
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);

        // A transação sobrevive, agora "Sem categoria" (FK nullOnDelete)
        $transaction->refresh();
        $this->assertNull($transaction->category_id);
    }
}
