<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature "Faturas / Despesas": lançamento de despesas (à vista / parcelado /
 * recorrente), geração das linhas (1 transação por parcela), exclusão da
 * compra inteira (grupo) e escopo de família. Testamos as AÇÕES (redirect +
 * estado no banco) — a Blade é construída por outro agente.
 *
 * Decisão fechada: parcelamento = N linhas, uma datada por mês.
 */
class FaturaCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $card;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->card = Account::factory()->for($this->user)->creditCard()->create();
        $this->category = Category::factory()->expense()->for($this->user)->create();
    }

    /** Payload de lançamento à vista; sobrescreva só o campo em teste. */
    private function launch(array $overrides = []): array
    {
        return array_merge([
            'description' => 'Compra de teste',
            'amount' => '100,00',
            'date' => now()->toDateString(),
            'account_id' => $this->card->id,
            'category_id' => $this->category->id,
            'mode' => 'avista',
        ], $overrides);
    }

    // ----- Geração -----

    public function test_avista_creates_single_transaction(): void
    {
        $response = $this->actingAs($this->user)->post('/faturas/lancar', $this->launch([
            'amount' => '284,70',
            'mode' => 'avista',
        ]));

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('faturas.index'));

        $this->assertDatabaseCount('transactions', 1);

        $tx = Transaction::firstOrFail();
        $this->assertSame('expense', $tx->type);
        $this->assertSame('284.70', (string) $tx->amount);
        $this->assertSame($this->card->id, $tx->account_id);
        $this->assertNull($tx->group_id);
        $this->assertNull($tx->installments);
        $this->assertFalse((bool) $tx->recurring);
        // Autor padrão = usuário atual.
        $this->assertSame($this->user->id, $tx->made_by_user_id);
    }

    public function test_parcelado_creates_n_lines_summing_to_total_with_monthly_dates(): void
    {
        $base = now()->startOfMonth(); // data-base estável

        $this->actingAs($this->user)->post('/faturas/lancar', $this->launch([
            'description' => 'Notebook',
            'amount' => '1.000,00',
            'date' => $base->toDateString(),
            'mode' => 'parcelado',
            'installments' => 3,
        ]))->assertSessionHasNoErrors();

        $parcelas = Transaction::orderBy('installment_no')->get();

        // 3 linhas, todas do mesmo grupo, type expense.
        $this->assertCount(3, $parcelas);
        $this->assertCount(1, $parcelas->pluck('group_id')->unique()->filter());
        $this->assertTrue($parcelas->every(fn ($p) => $p->type === 'expense'));

        // Soma exata == total.
        $this->assertSame(1000.0, round((float) $parcelas->sum('amount'), 2));

        // installment_no 1..3 e installments=3 em todas.
        $this->assertSame([1, 2, 3], $parcelas->pluck('installment_no')->map(fn ($n) => (int) $n)->all());
        $this->assertTrue($parcelas->every(fn ($p) => (int) $p->installments === 3));

        // Última parcela absorve o arredondamento: 1000/3 = 333,33 x2 + 333,34.
        $this->assertSame('333.33', (string) $parcelas[0]->amount);
        $this->assertSame('333.33', (string) $parcelas[1]->amount);
        $this->assertSame('333.34', (string) $parcelas[2]->amount);

        // Datas mensais: base, base+1mês, base+2meses.
        $this->assertSame($base->toDateString(), $parcelas[0]->date->toDateString());
        $this->assertSame($base->copy()->addMonths(1)->toDateString(), $parcelas[1]->date->toDateString());
        $this->assertSame($base->copy()->addMonths(2)->toDateString(), $parcelas[2]->date->toDateString());
    }

    public function test_recorrente_creates_single_open_occurrence(): void
    {
        // Recorrência "infinita": cria UMA ocorrência em aberto (não 12), datada
        // no próximo vencimento do cartão. Pagá-la gera a próxima.
        $this->card->update(['closing_day' => 8, 'due_day' => 15]);

        $this->actingAs($this->user)->post('/faturas/lancar', $this->launch([
            'description' => 'Streaming',
            'amount' => '49,90',
            'mode' => 'recorrente',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('transactions', 1);

        $tx = Transaction::firstOrFail();
        $this->assertTrue((bool) $tx->recurring);
        $this->assertNull($tx->paid_at);                 // em aberto
        $this->assertNull($tx->installments);
        $this->assertNotNull($tx->group_id);
        $this->assertSame('49.90', (string) $tx->amount);
        // Datada no vencimento do cartão.
        $this->assertSame($this->card->dueDate->toDateString(), $tx->date->toDateString());
    }

    public function test_paying_recurrence_marks_paid_and_generates_next(): void
    {
        $this->card->update(['closing_day' => 8, 'due_day' => 15]);

        $this->actingAs($this->user)->post('/faturas/lancar', $this->launch([
            'description' => 'Streaming',
            'amount' => '49,90',
            'mode' => 'recorrente',
        ]))->assertSessionHasNoErrors();

        $atual = Transaction::firstOrFail();

        $this->actingAs($this->user)
            ->post("/faturas/recorrente/{$atual->id}/pagar")
            ->assertRedirect(route('faturas.index'));

        // A atual ficou paga; nasceu a próxima (em aberto), +1 mês, mesmo grupo.
        $atual->refresh();
        $this->assertNotNull($atual->paid_at);

        $this->assertDatabaseCount('transactions', 2);

        $proxima = Transaction::whereNull('paid_at')->where('recurring', true)->firstOrFail();
        $this->assertSame($atual->group_id, $proxima->group_id);
        $this->assertSame('49.90', (string) $proxima->amount);
        $this->assertSame(
            \Carbon\CarbonImmutable::parse($atual->date)->addMonth()->toDateString(),
            $proxima->date->toDateString(),
        );
    }

    public function test_paying_already_paid_recurrence_does_not_duplicate(): void
    {
        $this->card->update(['closing_day' => 8, 'due_day' => 15]);

        $this->actingAs($this->user)->post('/faturas/lancar', $this->launch([
            'amount' => '49,90',
            'mode' => 'recorrente',
        ]))->assertSessionHasNoErrors();

        $atual = Transaction::firstOrFail();

        // Paga a MESMA ocorrência duas vezes: só gera UMA próxima (idempotente).
        $this->actingAs($this->user)->post("/faturas/recorrente/{$atual->id}/pagar");
        $this->actingAs($this->user)->post("/faturas/recorrente/{$atual->id}/pagar");

        $this->assertDatabaseCount('transactions', 2);
    }

    // ----- Parcelado/recorrente só em cartão -----

    public function test_parcelado_rejected_on_non_card_account(): void
    {
        $conta = Account::factory()->for($this->user)->create(['type' => 'bank', 'initial_balance' => 0]);

        $this->actingAs($this->user)->post('/faturas/lancar', $this->launch([
            'account_id' => $conta->id,
            'mode' => 'parcelado',
            'installments' => 3,
        ]))->assertSessionHasErrors('mode');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_recorrente_rejected_on_non_card_account(): void
    {
        $conta = Account::factory()->for($this->user)->create(['type' => 'wallet', 'initial_balance' => 0]);

        $this->actingAs($this->user)->post('/faturas/lancar', $this->launch([
            'account_id' => $conta->id,
            'mode' => 'recorrente',
        ]))->assertSessionHasErrors('mode');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_avista_allowed_on_non_card_account(): void
    {
        $conta = Account::factory()->for($this->user)->create(['type' => 'bank', 'initial_balance' => 0]);

        $this->actingAs($this->user)->post('/faturas/lancar', $this->launch([
            'account_id' => $conta->id,
            'mode' => 'avista',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_parcelado_requires_installments(): void
    {
        $this->actingAs($this->user)->post('/faturas/lancar', $this->launch([
            'mode' => 'parcelado',
            // sem installments
        ]))->assertSessionHasErrors('installments');

        $this->assertDatabaseCount('transactions', 0);
    }

    // ----- Exclusão da compra (grupo) -----

    public function test_destroy_removes_whole_installment_group(): void
    {
        $this->actingAs($this->user)->post('/faturas/lancar', $this->launch([
            'amount' => '900,00',
            'date' => now()->startOfMonth()->toDateString(),
            'mode' => 'parcelado',
            'installments' => 3,
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('transactions', 3);

        // Apagar UMA parcela apaga a compra inteira (mesmo group_id).
        $parcela = Transaction::firstOrFail();
        $this->actingAs($this->user)->delete("/faturas/compra/{$parcela->id}")
            ->assertRedirect(route('faturas.index'));

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_destroy_avista_removes_only_that_transaction(): void
    {
        $this->actingAs($this->user)->post('/faturas/lancar', $this->launch())->assertSessionHasNoErrors();
        $outra = Transaction::factory()->for($this->user)->for($this->card)->expense()->create();

        $compra = Transaction::where('description', 'Compra de teste')->firstOrFail();
        $this->actingAs($this->user)->delete("/faturas/compra/{$compra->id}")
            ->assertRedirect(route('faturas.index'));

        $this->assertDatabaseMissing('transactions', ['id' => $compra->id]);
        $this->assertDatabaseHas('transactions', ['id' => $outra->id]);
    }

    // ----- Escopo de família -----

    public function test_dependent_can_launch_for_family(): void
    {
        $dependent = User::factory()->create(['account_owner_id' => $this->user->id]);

        $this->actingAs($dependent)->post('/faturas/lancar', $this->launch([
            'amount' => '50,00',
        ]))->assertSessionHasNoErrors();

        // Dono = titular; autor = dependente.
        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->user->id,
            'made_by_user_id' => $dependent->id,
            'amount' => '50.00',
        ]);
    }

    public function test_cannot_launch_on_other_family_account(): void
    {
        $stranger = User::factory()->create();
        $strangerCard = Account::factory()->for($stranger)->creditCard()->create();

        $this->actingAs($this->user)->post('/faturas/lancar', $this->launch([
            'account_id' => $strangerCard->id,
        ]))->assertSessionHasErrors('account_id');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_cannot_destroy_other_family_purchase(): void
    {
        $stranger = User::factory()->create();
        $strangerCard = Account::factory()->for($stranger)->creditCard()->create();
        $tx = Transaction::factory()->for($stranger)->for($strangerCard)->expense()->create();

        $this->actingAs($this->user)->delete("/faturas/compra/{$tx->id}")->assertForbidden();

        $this->assertDatabaseHas('transactions', ['id' => $tx->id]);
    }

    public function test_category_must_be_expense(): void
    {
        $income = Category::factory()->income()->for($this->user)->create();

        $this->actingAs($this->user)->post('/faturas/lancar', $this->launch([
            'category_id' => $income->id,
        ]))->assertSessionHasErrors('category_id');

        $this->assertDatabaseCount('transactions', 0);
    }

    // ----- Índice -----

    public function test_index_renders(): void
    {
        Transaction::factory()->for($this->user)->for($this->card)->expense()->create([
            'date' => now()->toDateString(),
        ]);

        $this->actingAs($this->user)->get('/faturas')->assertOk();
    }
}
