<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\FaturaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Modelo de dinheiro do cartão de crédito:
 * - cartão NÃO é caixa: fica fora do patrimônio (sidebar) e do saldo (dashboard);
 * - ciclo/fatura/limite (currentInvoice, committed, availableLimit, dueDate);
 * - criação de cartão exige limite + dias de fechamento/vencimento.
 */
class CreditCardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // limpa o relógio congelado
        parent::tearDown();
    }

    // ----- Cartão fora do patrimônio -----

    public function test_credit_card_is_excluded_from_patrimonio_sidebar(): void
    {
        $user = User::factory()->create();
        // Conta com 1.000 (entra no patrimônio).
        Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 1000]);
        // Cartão: limite alto e uma despesa — NÃO deve mexer no patrimônio.
        $card = Account::factory()->for($user)->creditCard()->create();
        Transaction::factory()->for($user)->for($card)->expense()->create([
            'amount' => 500,
            'date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        // Patrimônio = só a conta (1.000), a despesa no cartão não derruba o saldo.
        $response->assertSee('R$ 1.000');
        $response->assertSee('Disponível para gastar: R$ 1.000,00');
    }

    public function test_credit_card_balance_is_excluded_from_dashboard_saldo_stat(): void
    {
        $user = User::factory()->create();
        Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 2000]);
        $card = Account::factory()->for($user)->creditCard()->create();
        // Despesa no cartão não afeta o stat "saldo".
        Transaction::factory()->for($user)->for($card)->expense()->create([
            'amount' => 800,
            'date' => now()->toDateString(),
        ]);

        $dashboard = app(DashboardService::class)->build($user->id);

        // saldo (stat) = só a conta (2.000), sem o cartão.
        $this->assertSame(2000.0, $dashboard['monthStats']['saldo']);
        $this->assertSame(2000.0, $dashboard['totalBalance']);
    }

    public function test_non_card_expense_still_reduces_saldo(): void
    {
        $user = User::factory()->create();
        $conta = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 2000]);
        Transaction::factory()->for($user)->for($conta)->expense()->create([
            'amount' => 300,
            'date' => now()->toDateString(),
        ]);

        $dashboard = app(DashboardService::class)->build($user->id);

        // Despesa em conta (não-cartão) baixa o saldo normalmente.
        $this->assertSame(1700.0, $dashboard['totalBalance']);
    }

    // ----- Ciclo / fatura / limite -----

    public function test_available_limit_is_credit_limit_minus_committed(): void
    {
        // Congela hoje em 18/06 (dia > closing_day=10 ⇒ ciclo aberto = (10/06, 10/07]).
        Carbon::setTestNow('2026-06-18');

        $user = User::factory()->create();
        $card = Account::factory()->for($user)->create([
            'type' => 'credit_card',
            'credit_limit' => 5000,
            'closing_day' => 10,
            'due_day' => 20,
            'initial_balance' => 0,
        ]);

        // Despesa de hoje (dentro do ciclo aberto) → comprometido E fatura atual.
        Transaction::factory()->for($user)->for($card)->expense()->create([
            'amount' => 1200,
            'date' => '2026-06-18',
        ]);
        // Parcela FUTURA além do fim do ciclo (> 10/07) → comprometido, mas NÃO
        // na fatura atual (ainda não fecha neste ciclo).
        Transaction::factory()->for($user)->for($card)->expense()->create([
            'amount' => 300,
            'date' => '2026-08-05',
        ]);
        // Despesa do ciclo ANTERIOR (antes do fechamento 10/06) → fora de ambos.
        Transaction::factory()->for($user)->for($card)->expense()->create([
            'amount' => 999,
            'date' => '2026-06-05',
        ]);

        $card->refresh();

        // Comprometido = TUDO que ainda não foi pago, independente do ciclo:
        // 1200 (18/06) + 300 (parcela futura) + 999 (ciclo anterior, NÃO paga).
        // A liberação do limite é dirigida pelo pagamento (paid_at), não pela
        // passagem do tempo — antes o 999 saía da conta só porque o ciclo virou,
        // devolvendo limite a quem nunca pagou.
        $this->assertSame(2499.0, $card->committed);
        // Limite disponível = 5000 − 2499.
        $this->assertSame(2501.0, $card->availableLimit);
        // Fatura atual = despesas no ciclo aberto (10/06, 10/07]: só a de 18/06 (1200).
        $this->assertSame(1200.0, $card->currentInvoice);
        // Vencimento DERIVADO do ciclo: a fatura que fecha em 10/07 vence em
        // 20/07. Antes o accessor devolvia "próximo dia 20 ≥ hoje" = 20/06, que
        // é o vencimento da fatura ANTERIOR — não batia com o currentInvoice
        // exibido ao lado.
        $this->assertSame('2026-07-20', $card->dueDate->toDateString());
    }

    public function test_cycle_before_closing_day_uses_previous_window(): void
    {
        // Hoje 05/06 (dia < closing_day=10 ⇒ ciclo aberto = (10/05, 10/06]).
        Carbon::setTestNow('2026-06-05');

        $user = User::factory()->create();
        $card = Account::factory()->for($user)->create([
            'type' => 'credit_card',
            'credit_limit' => 4000,
            'closing_day' => 10,
            'due_day' => 20,
            'initial_balance' => 0,
        ]);

        // Despesa de 20/05 está no ciclo aberto (10/05, 10/06].
        Transaction::factory()->for($user)->for($card)->expense()->create([
            'amount' => 250,
            'date' => '2026-05-20',
        ]);
        // Despesa de 02/05 (antes de 10/05) está fora.
        Transaction::factory()->for($user)->for($card)->expense()->create([
            'amount' => 700,
            'date' => '2026-05-02',
        ]);

        $card->refresh();

        $this->assertSame(250.0, $card->currentInvoice);
        // Comprometido = tudo em aberto (250 + 700), inclusive a despesa de 02/05
        // que está fora do ciclo mas continua sem pagamento.
        $this->assertSame(950.0, $card->committed);
        // Vencimento: hoje 05/06 ≤ dia 20 ⇒ 20/06.
        $this->assertSame('2026-06-20', $card->dueDate->toDateString());
    }

    public function test_faturas_index_stats_reflect_card(): void
    {
        Carbon::setTestNow('2026-06-18');

        $user = User::factory()->create();
        $card = Account::factory()->for($user)->create([
            'type' => 'credit_card',
            'credit_limit' => 5000,
            'closing_day' => 10,
            'due_day' => 20,
            'initial_balance' => 0,
        ]);
        Transaction::factory()->for($user)->for($card)->expense()->create([
            'amount' => 1000,
            'date' => '2026-06-18',
        ]);

        $data = app(FaturaService::class)->build($user->id);

        $this->assertSame(1, $data['stats']['numCartoes']);
        $this->assertSame(1000.0, $data['stats']['totalFaturas']);
        // `limiteDisponivel` saiu dos stats em 06/08/2026: o limite já aparece no
        // card de cada cartão, e o terceiro stat do topo passou a mostrar o que
        // faltava na tela — o total das CONTAS FIXAS ainda por pagar.
        $this->assertArrayNotHasKey('limiteDisponivel', $data['stats']);
        $this->assertSame(0.0, $data['stats']['totalContas'], 'sem conta fixa cadastrada');
        $this->assertSame(0, $data['stats']['numContas']);
        // O limite continua vivo POR CARTÃO, que é onde ele importa.
        $this->assertSame(4000.0, $data['cards']->first()['availableLimit']);
        $this->assertCount(1, $data['cards']);
        $this->assertSame(1000.0, $data['cards']->first()['currentInvoice']);
    }

    // ----- Criação de cartão exige campos -----

    public function test_creating_credit_card_requires_limit_and_days(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/accounts', [
            'name' => 'Cartão Black',
            'type' => 'credit_card',
            'initial_balance' => '0,00',
            // sem credit_limit / closing_day / due_day
        ])->assertSessionHasErrors(['credit_limit', 'closing_day', 'due_day']);

        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_creating_credit_card_persists_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/accounts', [
            'name' => 'Cartão Black',
            'type' => 'credit_card',
            'bank' => 'nubank',
            'credit_limit' => '5.000,00',
            'closing_day' => 10,
            'due_day' => 20,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('accounts', [
            'user_id' => $user->id,
            'name' => 'Cartão Black',
            'type' => 'credit_card',
            'credit_limit' => '5000.00',
            'closing_day' => 10,
            'due_day' => 20,
        ]);
    }

    public function test_closing_day_out_of_range_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/accounts', [
            'name' => 'Cartão',
            'type' => 'credit_card',
            'bank' => 'nubank',
            'credit_limit' => '1.000,00',
            'closing_day' => 31, // fora de 1..28
            'due_day' => 20,
        ])->assertSessionHasErrors('closing_day');
    }

    public function test_non_card_account_ignores_card_fields(): void
    {
        $user = User::factory()->create();

        // Mesmo enviando campos de cartão, uma conta normal os ignora (vira null).
        $this->actingAs($user)->post('/accounts', [
            'name' => 'Conta Comum',
            'type' => 'checking',
            'bank' => 'itau',
            'initial_balance' => '100,00',
            'credit_limit' => '9.999,00',
            'closing_day' => 5,
            'due_day' => 9,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('accounts', [
            'name' => 'Conta Comum',
            'type' => 'checking',
            'credit_limit' => null,
            'closing_day' => null,
            'due_day' => null,
        ]);
    }
}
