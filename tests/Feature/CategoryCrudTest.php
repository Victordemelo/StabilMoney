<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\DefaultCategories;
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

    /**
     * Drag & drop entre colunas (categories.js): PATCH via fetch com Accept
     * JSON trocando o type — responde {ok: true} sem redirect.
     */
    public function test_category_type_can_be_changed_via_json_patch(): void
    {
        $category = Category::factory()->expense()->for($this->user)->create([
            'name' => 'Freelas',
            'color' => '#1C9A70',
            'icon' => '💼',
        ]);

        $response = $this->actingAs($this->user)->patchJson("/categories/{$category->id}", [
            'name' => 'Freelas',
            'type' => 'income',
            'color' => '#1C9A70',
            'icon' => '💼',
        ]);

        $response->assertOk();
        $response->assertExactJson(['ok' => true]);
        $this->assertSame('income', $category->fresh()->type);
    }

    public function test_category_json_patch_with_invalid_type_is_rejected(): void
    {
        $category = Category::factory()->expense()->for($this->user)->create();

        $response = $this->actingAs($this->user)->patchJson("/categories/{$category->id}", [
            'name' => $category->name,
            'type' => 'invalido',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('type');
        $this->assertSame('expense', $category->fresh()->type);
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

    /* ----- Categorias fixas (is_locked) ----- */

    public function test_locked_category_cannot_be_deleted(): void
    {
        $category = Category::factory()->expense()->for($this->user)->create([
            'name' => 'Alimentação',
            'is_locked' => true,
        ]);

        $response = $this->actingAs($this->user)->delete("/categories/{$category->id}");

        $response->assertSessionHasErrors('category');
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_categories_index_hides_delete_button_of_locked_category(): void
    {
        $fixa = Category::factory()->expense()->for($this->user)->create([
            'name' => 'Transporte',
            'is_locked' => true,
        ]);
        $livre = Category::factory()->expense()->for($this->user)->create(['name' => 'Lazer']);

        $response = $this->actingAs($this->user)->get('/categories');

        $response->assertOk();
        // A rota destroy compartilha a URL com update/edit, então a checagem
        // é pelo botão de excluir em si (aria-label de cada chip).
        $response->assertDontSee('Excluir '.$fixa->name);
        $response->assertSee('Excluir '.$livre->name);
        $response->assertSee('Categoria fixa', false);
        // Chip fixo não é arrastável (não pode mudar de tipo)
        $response->assertSee('draggable="false"', false);
    }

    public function test_locked_category_can_be_renamed(): void
    {
        $category = Category::factory()->expense()->for($this->user)->create([
            'name' => 'Alimentação',
            'is_locked' => true,
        ]);

        $response = $this->actingAs($this->user)->put("/categories/{$category->id}", [
            'name' => 'Mercado e comida',
            'type' => 'expense',
            'color' => '#F0A93B',
            'icon' => '🍽️',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('categories.index'));

        $category->refresh();
        $this->assertSame('Mercado e comida', $category->name);
        // Continua fixa depois da edição
        $this->assertTrue($category->isLocked());
    }

    /**
     * Drag & drop tentando levar uma categoria fixa para a coluna de receitas:
     * o servidor rejeita com 422 (o JS faz rollback do chip).
     */
    public function test_locked_category_type_cannot_be_changed(): void
    {
        $category = Category::factory()->expense()->for($this->user)->create([
            'name' => 'Saúde',
            'is_locked' => true,
        ]);

        $response = $this->actingAs($this->user)->patchJson("/categories/{$category->id}", [
            'name' => 'Saúde',
            'type' => 'income',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('type');
        $this->assertSame('expense', $category->fresh()->type);
    }

    public function test_default_categories_seed_the_five_locked_ones(): void
    {
        $novo = User::factory()->create();

        DefaultCategories::seedFor($novo);

        $fixas = Category::where('user_id', $novo->id)->where('is_locked', true)->pluck('name');

        $this->assertEqualsCanonicalizing(
            ['Alimentação', 'Moradia', 'Saúde', 'Transporte', 'Contas'],
            $fixas->all(),
        );
        $this->assertEqualsCanonicalizing(
            DefaultCategories::lockedExpenseNames(),
            $fixas->all(),
        );

        // As demais (inclusive todas as receitas) continuam livres
        $this->assertFalse(
            Category::where('user_id', $novo->id)->where('type', 'income')->where('is_locked', true)->exists(),
        );
        $this->assertFalse(
            Category::where('user_id', $novo->id)->where('name', 'Lazer')->value('is_locked'),
        );
    }

    /**
     * Idempotência: rodar o seed de novo marca como fixa uma categoria que
     * já existia sem o cadeado (bancos criados antes da coluna is_locked).
     */
    public function test_seed_locks_existing_default_category(): void
    {
        $novo = User::factory()->create();
        $antiga = Category::factory()->expense()->for($novo)->create([
            'name' => 'Moradia',
            'is_locked' => false,
        ]);

        DefaultCategories::seedFor($novo);

        $this->assertTrue($antiga->fresh()->isLocked());
        // Não duplicou a categoria
        $this->assertSame(1, Category::where('user_id', $novo->id)->where('name', 'Moradia')->count());
    }
}
