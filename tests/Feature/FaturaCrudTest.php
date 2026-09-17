<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FaturaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

        // O centavo que sobra vai nas PRIMEIRAS parcelas: 1000/3 = 333,34 + 333,33 x2.
        // Antes a última absorvia a sobra, o que produzia parcela NEGATIVA quando o
        // arredondamento subia (R$ 0,36 em 24x dava uma parcela de −R$ 0,10). Ver
        // AuditoriaCorrecoesTest::test_achado_m1_*.
        $this->assertSame('333.34', (string) $parcelas[0]->amount);
        $this->assertSame('333.33', (string) $parcelas[1]->amount);
        $this->assertSame('333.33', (string) $parcelas[2]->amount);

        // Datas mensais: base, base+1mês, base+2meses.
        $this->assertSame($base->toDateString(), $parcelas[0]->date->toDateString());
        $this->assertSame($base->copy()->addMonths(1)->toDateString(), $parcelas[1]->date->toDateString());
        $this->assertSame($base->copy()->addMonths(2)->toDateString(), $parcelas[2]->date->toDateString());
    }

    public function test_recorrente_creates_single_open_occurrence(): void
    {
        // Recorrência "infinita": cria UMA ocorrência em aberto (não 12), datada
        // no DIA DA COMPRA (antes era no vencimento do cartão — fora do ciclo
        // aberto, invisível na lista até o ciclo virar). A próxima nasce por
        // clique, só depois que o ciclo desta fechar.
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
        // Datada no dia da compra.
        $this->assertSame(now()->toDateString(), $tx->date->toDateString());
    }

    public function test_paying_recurrence_marks_paid_and_generates_next(): void
    {
        // Data CONGELADA num dia <= 8, o fechamento do cartão abaixo. O cenário exige a
        // compra exatamente UM ciclo atrás, para a próxima ocorrência cair no ciclo aberto.
        // Com `now()` solto, "dia 1 do mês passado" só é um ciclo atrás entre os dias 1 e 8;
        // do dia 9 em diante vira DOIS ciclos, a próxima nasce num ciclo já fechado (o
        // catch-up de um ciclo por clique, correto) e a última asserção falhava — o teste
        // passou no dia em que foi escrito (02/09) e quebrou sozinho no dia 9.
        Carbon::setTestNow('2026-09-02');

        $this->card->update(['closing_day' => 8, 'due_day' => 15]);

        // A ocorrência é lançada num ciclo que JÁ FECHOU: só então a próxima
        // pode nascer (ver RecorrenciaDeCartaoNoCicloTest para o ciclo aberto).
        $dataDaCompra = now()->subMonthNoOverflow()->day(1)->toDateString();

        $this->actingAs($this->user)->post('/faturas/lancar', $this->launch([
            'description' => 'Streaming',
            'amount' => '49,90',
            'date' => $dataDaCompra,
            'mode' => 'recorrente',
        ]))->assertSessionHasNoErrors();

        $atual = Transaction::firstOrFail();

        $this->actingAs($this->user)
            ->post("/faturas/recorrente/{$atual->id}/pagar")
            ->assertRedirect(route('faturas.index'));

        // A recorrência é NO CARTÃO, então a atual continua EM ABERTO: quem a quita é a
        // fatura. Marcar `paid_at` aqui tirava a despesa da fatura e devolvia o limite sem
        // nenhum dinheiro sair do caixa (três cliques "quitavam" R$ 149,70 de graça).
        // Ver AuditoriaCorrecoesTest::test_achado_c2_*.
        $atual->refresh();
        $this->assertNull(
            $atual->paid_at,
            'Despesa de cartão não pode ser declarada paga fora do pagamento da fatura.',
        );

        // Mas a próxima ocorrência nasce, para a recorrência seguir andando.
        $this->assertDatabaseCount('transactions', 2);

        // As duas estão em aberto agora, então a "próxima" é identificada por exclusão
        // da atual (antes bastava filtrar por paid_at null).
        $proxima = Transaction::where('recurring', true)
            ->whereKeyNot($atual->id)
            ->firstOrFail();
        $this->assertSame($atual->group_id, $proxima->group_id);
        $this->assertSame('49.90', (string) $proxima->amount);
        // Mesmo dia da compra, um mês depois — e dentro do ciclo aberto.
        $this->assertSame(
            CarbonImmutable::parse($atual->date)->addMonthNoOverflow()->toDateString(),
            $proxima->date->toDateString(),
        );
        [$inicio, $fim] = $this->card->fresh()->billingCycle();
        $this->assertTrue($proxima->date->greaterThan($inicio) && $proxima->date->lessThanOrEqualTo($fim));

        Carbon::setTestNow();
    }

    public function test_paying_already_paid_recurrence_does_not_duplicate(): void
    {
        $this->card->update(['closing_day' => 8, 'due_day' => 15]);

        $this->actingAs($this->user)->post('/faturas/lancar', $this->launch([
            'amount' => '49,90',
            'date' => now()->subMonthNoOverflow()->day(1)->toDateString(), // ciclo já fechado
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
        $conta = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 0]);

        $this->actingAs($this->user)->post('/faturas/lancar', $this->launch([
            'account_id' => $conta->id,
            'mode' => 'parcelado',
            'installments' => 3,
        ]))->assertSessionHasErrors('mode');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_recorrente_rejected_on_non_card_account(): void
    {
        $conta = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 0]);

        $this->actingAs($this->user)->post('/faturas/lancar', $this->launch([
            'account_id' => $conta->id,
            'mode' => 'recorrente',
        ]))->assertSessionHasErrors('mode');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_avista_allowed_on_non_card_account(): void
    {
        // Saldo suficiente: aqui o que se testa é o TIPO da conta aceitar "à
        // vista", não o limite de gasto (que tem testes próprios).
        $conta = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 500]);

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

    // ----- Pagar fatura (marcar como paga → desconta do caixa) -----

    public function test_pay_invoice_marks_paid_and_debits_cash_account(): void
    {
        // Despesa à vista no cartão, no ciclo atual → fatura em aberto de R$ 200.
        $this->actingAs($this->user)->post('/faturas/lancar', $this->launch(['amount' => '200,00']))
            ->assertRedirect();
        $this->assertGreaterThan(0, $this->card->fresh()->openInvoiceDue);

        $cash = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 1000]);

        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $this->card), [
            'pay_account_id' => $cash->id,
        ])->assertRedirect(route('faturas.index'));

        // Fatura zerada e saldo do caixa descontado (1000 - 200 = 800).
        $this->assertSame(0.0, $this->card->fresh()->openInvoiceDue);
        $this->assertSame(800.0, $cash->fresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'account_id' => $cash->id,
            'type' => 'expense',
            'amount' => '200.00',
            'description' => 'Pagamento da fatura — '.$this->card->name,
        ]);
    }

    public function test_cannot_pay_invoice_of_non_credit_card(): void
    {
        $checking = Account::factory()->for($this->user)->create(['type' => 'checking']);
        $cash = Account::factory()->for($this->user)->create(['type' => 'savings', 'initial_balance' => 500]);

        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $checking), [
            'pay_account_id' => $cash->id,
        ])->assertForbidden();
    }

    public function test_pay_account_must_be_a_cash_account_of_the_family(): void
    {
        $this->actingAs($this->user)->post('/faturas/lancar', $this->launch())->assertRedirect();

        // Debitar de OUTRO cartão (não é caixa) deve falhar na validação.
        $anotherCard = Account::factory()->for($this->user)->creditCard()->create();

        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $this->card), [
            'pay_account_id' => $anotherCard->id,
        ])->assertSessionHasErrors('pay_account_id');
    }

    public function test_upcoming_card_invoice_shows_in_notifications(): void
    {
        Carbon::setTestNow('2026-07-08');

        // Fecha dia 10, vence dia 12: hoje (08/07) o ciclo aberto é (10/06, 10/07],
        // que fecha em 10/07 e vence em 12/07 — 4 dias, dentro da janela do sino.
        $card = Account::factory()->for($this->user)->create([
            'type' => 'credit_card', 'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 12,
        ]);
        Transaction::factory()->for($this->user)->for($card)->expense()->create([
            'amount' => 300, 'date' => '2026-06-20',
        ]);

        $due = app(FaturaService::class)->upcomingDue($this->user->id, 7);

        $this->assertCount(1, $due);
        $this->assertSame('Fatura '.$card->name, $due->first()['nome']);
        $this->assertSame(300.0, $due->first()['valor']);

        Carbon::setTestNow();
    }
}
