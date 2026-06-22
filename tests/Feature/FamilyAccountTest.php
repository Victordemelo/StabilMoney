<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

    public function test_dependent_sees_family_patrimonio_in_sidebar(): void
    {
        $titular = User::factory()->create();
        Account::factory()->for($titular)->create(['initial_balance' => 1234]);
        $dependent = User::factory()->create(['account_owner_id' => $titular->id]);

        // O card "Patrimônio total" da sidebar (View Composer) deve usar ownerId,
        // mostrando o total da família — não R$ 0,00 do próprio dependente.
        $this->actingAs($dependent)->get('/accounts')
            ->assertOk()
            ->assertSee('1.234');
    }

    public function test_titular_adds_dependent_with_photo(): void
    {
        Storage::fake('public');
        $titular = User::factory()->create();

        $this->actingAs($titular)->post('/dependentes', [
            '_form' => 'store',
            'name' => 'Bia', 'email' => 'bia@familia.test', 'password' => 'senha-forte-123',
            'avatar' => UploadedFile::fake()->create('bia.jpg', 100, 'image/jpeg'),
        ])->assertRedirect();

        $dependent = User::where('email', 'bia@familia.test')->first();
        $this->assertNotNull($dependent);
        $this->assertNotNull($dependent->avatar_path);
        Storage::disk('public')->assertExists($dependent->avatar_path);
    }

    public function test_dependent_relationship_is_saved_and_shown(): void
    {
        $titular = User::factory()->create();

        $this->actingAs($titular)->post('/dependentes', [
            '_form' => 'store',
            'name' => 'Ana', 'email' => 'ana@familia.test', 'password' => 'senha-forte-123',
            'relationship' => 'filho',
        ])->assertRedirect();

        $dep = User::where('email', 'ana@familia.test')->first();
        $this->assertSame('filho', $dep->relationship);
        $this->assertSame('Filho(a)', $dep->relationshipLabel());

        // O card mostra o parentesco no lugar de "Dependente".
        $this->actingAs($titular)->get('/dependentes')->assertOk()->assertSee('Filho(a)');
    }

    public function test_dependent_relationship_must_be_valid(): void
    {
        $titular = User::factory()->create();

        $this->actingAs($titular)->post('/dependentes', [
            '_form' => 'store',
            'name' => 'Zé', 'email' => 'ze@familia.test', 'password' => 'senha-forte-123',
            'relationship' => 'sogro-invalido',
        ])->assertSessionHasErrors('relationship');
    }

    public function test_titular_updates_dependent_without_changing_password(): void
    {
        $titular = User::factory()->create();
        $dependent = User::factory()->create([
            'account_owner_id' => $titular->id,
            'name' => 'Antigo', 'email' => 'antigo@familia.test',
            'password' => Hash::make('senha-original-123'),
        ]);
        $hashOriginal = $dependent->password;

        $this->actingAs($titular)->patch("/dependentes/{$dependent->id}", [
            '_form' => 'edit-' . $dependent->id,
            'name' => 'Novo Nome', 'email' => 'novo@familia.test',
        ])->assertRedirect();

        $dependent->refresh();
        $this->assertSame('Novo Nome', $dependent->name);
        $this->assertSame('novo@familia.test', $dependent->email);
        $this->assertSame($hashOriginal, $dependent->password); // senha intacta
    }

    public function test_titular_updates_dependent_password_when_provided(): void
    {
        $titular = User::factory()->create();
        $dependent = User::factory()->create([
            'account_owner_id' => $titular->id,
            'password' => Hash::make('senha-original-123'),
        ]);

        $this->actingAs($titular)->patch("/dependentes/{$dependent->id}", [
            '_form' => 'edit-' . $dependent->id,
            'name' => $dependent->name, 'email' => $dependent->email,
            'password' => 'nova-senha-forte-456',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('nova-senha-forte-456', $dependent->refresh()->password));
    }

    public function test_titular_cannot_update_another_familys_dependent(): void
    {
        $titularA = User::factory()->create();
        $titularB = User::factory()->create();
        $depDeB = User::factory()->create(['account_owner_id' => $titularB->id]);

        $this->actingAs($titularA)->patch("/dependentes/{$depDeB->id}", [
            '_form' => 'edit-' . $depDeB->id,
            'name' => 'Invadido', 'email' => 'invadido@x.test',
        ])->assertForbidden();
    }

    public function test_dependent_cannot_update_dependent(): void
    {
        $titular = User::factory()->create();
        $dependent = User::factory()->create(['account_owner_id' => $titular->id]);

        $this->actingAs($dependent)->patch("/dependentes/{$dependent->id}", [
            '_form' => 'edit-' . $dependent->id,
            'name' => 'X', 'email' => 'x@familia.test',
        ])->assertForbidden();
    }

    public function test_dependents_page_shows_amount_spent(): void
    {
        $titular = User::factory()->create();
        $account = Account::factory()->for($titular)->create();
        $dependent = User::factory()->create([
            'account_owner_id' => $titular->id, 'name' => 'Gastador',
        ]);
        // Despesa de R$ 50 lançada pelo dependente: o card mostra quanto ele gastou.
        Transaction::factory()->for($titular)->for($account)->expense()->create([
            'made_by_user_id' => $dependent->id, 'amount' => 50,
        ]);

        $this->actingAs($titular)->get('/dependentes')
            ->assertOk()
            ->assertSee('Já gastou')
            ->assertSee('50,00');
    }

    public function test_launch_form_preselects_dependent_author(): void
    {
        $titular = User::factory()->create();
        Account::factory()->for($titular)->create();
        $dependent = User::factory()->create(['account_owner_id' => $titular->id, 'name' => 'Filho']);

        // O card linka para o lançamento já com ?autor=ID; o select deve vir marcado.
        $content = $this->actingAs($titular)->get(route('transactions.create', ['autor' => $dependent->id]))
            ->assertOk()
            ->assertSee('Quem fez a compra')
            ->getContent();

        // O <option> do dependente vem com "selected" (espaços/quebras de linha variam).
        $this->assertMatchesRegularExpression('/value="' . $dependent->id . '"\s*selected/', $content);
    }

    public function test_dashboard_recents_show_author(): void
    {
        $titular = User::factory()->create();
        $account = Account::factory()->for($titular)->create();
        $dependent = User::factory()->create(['account_owner_id' => $titular->id, 'name' => 'Carlos Filho']);
        Transaction::factory()->for($titular)->for($account)->expense()->create([
            'made_by_user_id' => $dependent->id,
            'date' => now()->toDateString(),
        ]);

        $this->actingAs($titular)->get('/')->assertOk()->assertSee('Carlos Filho');
    }
}
