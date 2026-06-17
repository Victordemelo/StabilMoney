<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Conta-família (Dependentes): titular e dependentes compartilham a mesma visão
 * financeira; o escopo das queries é por família (ownerId), não por usuário.
 */
class FamilyAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_titular_owner_id_is_self(): void
    {
        $titular = User::factory()->create();

        $this->assertSame($titular->id, $titular->ownerId());
        $this->assertTrue($titular->isTitular());
    }

    public function test_dependent_points_to_titular(): void
    {
        $titular = User::factory()->create();
        $dependent = User::factory()->create(['account_owner_id' => $titular->id]);

        $this->assertSame($titular->id, $dependent->ownerId());
        $this->assertFalse($dependent->isTitular());
        $this->assertTrue($titular->dependents->contains($dependent));
        $this->assertTrue($dependent->titular->is($titular));
    }

    public function test_transaction_records_author(): void
    {
        $titular = User::factory()->create();
        $account = Account::factory()->for($titular)->create();
        $tx = Transaction::factory()->for($titular)->for($account)->expense()
            ->create(['made_by_user_id' => $titular->id]);

        $this->assertTrue($tx->madeBy->is($titular));
    }

    public function test_dependent_sees_titular_data(): void
    {
        $titular = User::factory()->create();
        Account::factory()->for($titular)->create(['name' => 'Conta da Familia']);
        Category::factory()->expense()->for($titular)->create(['name' => 'Mercado Familia']);
        $dependent = User::factory()->create(['account_owner_id' => $titular->id]);

        $this->actingAs($dependent)->get('/accounts')->assertOk()->assertSee('Conta da Familia');
        $this->actingAs($dependent)->get('/categories')->assertOk()->assertSee('Mercado Familia');
        $this->actingAs($dependent)->get('/')->assertOk();
    }

    public function test_dependent_can_create_with_family_account(): void
    {
        $titular = User::factory()->create();
        $account = Account::factory()->for($titular)->create();
        $dependent = User::factory()->create(['account_owner_id' => $titular->id]);

        $this->actingAs($dependent)->post('/transactions', [
            'type' => 'expense', 'amount' => '30,00',
            'account_id' => $account->id, 'date' => now()->toDateString(),
            'description' => 'Compra do dependente',
        ])->assertRedirect();

        $this->assertDatabaseHas('transactions', [
            'description' => 'Compra do dependente',
            'user_id' => $titular->id,
        ]);
    }

    public function test_dependent_can_edit_family_transaction(): void
    {
        $titular = User::factory()->create();
        $account = Account::factory()->for($titular)->create();
        $tx = Transaction::factory()->for($titular)->for($account)->expense()->create();
        $dependent = User::factory()->create(['account_owner_id' => $titular->id]);

        $this->actingAs($dependent)->get("/transactions/{$tx->id}/edit")->assertOk();
    }

    public function test_titular_adds_dependent_without_seeding_categories(): void
    {
        $titular = User::factory()->create();

        $this->actingAs($titular)->post('/dependentes', [
            'name' => 'Joao', 'email' => 'joao@familia.test', 'password' => 'senha-forte-123',
        ])->assertRedirect();

        $dependent = User::where('email', 'joao@familia.test')->first();
        $this->assertNotNull($dependent);
        $this->assertSame($titular->id, $dependent->account_owner_id);
        $this->assertFalse((bool) $dependent->is_admin);
        $this->assertDatabaseMissing('categories', ['user_id' => $dependent->id]);
    }

    public function test_dependent_cannot_manage_dependents(): void
    {
        $titular = User::factory()->create();
        $dependent = User::factory()->create(['account_owner_id' => $titular->id]);

        $this->actingAs($dependent)->get('/dependentes')->assertForbidden();
    }

    public function test_titular_removes_dependent(): void
    {
        $titular = User::factory()->create();
        $dependent = User::factory()->create(['account_owner_id' => $titular->id]);

        $this->actingAs($titular)->delete("/dependentes/{$dependent->id}")->assertRedirect();
        $this->assertDatabaseMissing('users', ['id' => $dependent->id]);
    }

    public function test_author_defaults_to_current_user(): void
    {
        $titular = User::factory()->create();
        $account = Account::factory()->for($titular)->create();
        $dependent = User::factory()->create(['account_owner_id' => $titular->id]);

        $this->actingAs($dependent)->post('/transactions', [
            'type' => 'expense', 'amount' => '10,00',
            'account_id' => $account->id, 'date' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('transactions', [
            'user_id' => $titular->id, 'made_by_user_id' => $dependent->id,
        ]);
    }

    public function test_author_must_be_in_family(): void
    {
        $titular = User::factory()->create();
        $account = Account::factory()->for($titular)->create();
        $stranger = User::factory()->create();

        $this->actingAs($titular)->post('/transactions', [
            'type' => 'expense', 'amount' => '10,00',
            'account_id' => $account->id, 'date' => now()->toDateString(),
            'made_by_user_id' => $stranger->id,
        ])->assertSessionHasErrors('made_by_user_id');
    }

    public function test_titular_sees_dependents_page_with_member(): void
    {
        $titular = User::factory()->create();
        User::factory()->create(['account_owner_id' => $titular->id, 'name' => 'Maria Dependente']);

        $this->actingAs($titular)->get('/dependentes')
            ->assertOk()
            ->assertSee('Maria Dependente')
            ->assertSee('Adicionar dependente');
    }

    public function test_dependent_does_not_see_sidebar_dependents_card(): void
    {
        $titular = User::factory()->create();
        $dependent = User::factory()->create(['account_owner_id' => $titular->id]);

        $this->actingAs($dependent)->get('/accounts')
            ->assertOk()
            ->assertDontSee('>Dependentes<', false);
    }
}
