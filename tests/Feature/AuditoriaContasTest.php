<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correções da auditoria de 27/28-07-2026 no domínio "métodos de pagamento"
 * (`Account` + tela /accounts):
 *
 * - C-3  trocar o tipo de uma conta com histórico fazia dinheiro sumir/dobrar;
 * - A-6  receita (estorno) em cartão de crédito era engolida;
 * - M-16 o `$ignore` da edição não chegava aos accessors de cheque especial;
 * - M-17 cartão de débito exibia saldo BRUTO em vez do disponível;
 * - BAIXO `refresh()` não limpava os caches de dinheiro do model.
 */
class AuditoriaContasTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    // ============ C-3 · troca de tipo com histórico ============

    /**
     * Corrente com histórico → cartão de débito: o `initial_balance` virava NULL
     * (irreversível), o saldo ia a zero e a transação ficava órfã.
     */
    public function test_corrente_com_historico_nao_pode_virar_cartao_de_debito(): void
    {
        $corrente = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Conta Principal',
            'initial_balance' => 2000,
        ]);
        Transaction::factory()->for($this->user)->for($corrente)->expense()->create(['amount' => 500]);

        $outra = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 0]);

        $saldoAntes = $corrente->fresh()->balance; // 1.500,00

        $this->actingAs($this->user)
            ->put("/accounts/{$corrente->id}", [
                'name' => 'Conta Principal',
                'type' => 'debit_card',
                'bank' => 'nubank',
                'checking_account_id' => $outra->id,
            ])
            ->assertSessionHasErrors('type');

        $corrente->refresh();
        $this->assertSame('checking', $corrente->type, 'o tipo não pode ter mudado');
        $this->assertNotNull($corrente->initial_balance, 'o saldo inicial não pode virar NULL');
        $this->assertSame($saldoAntes, $corrente->balance, 'o saldo não pode mudar por uma edição de cadastro');
    }

    /**
     * Cartão de crédito → corrente: as despesas do ex-cartão passavam a descontar
     * do patrimônio (e a fatura já paga era descontada duas vezes).
     */
    public function test_cartao_de_credito_com_historico_nao_pode_virar_conta_corrente(): void
    {
        $cartao = Account::factory()->for($this->user)->creditCard()->create(['name' => 'Cartão Roxo']);
        Transaction::factory()->for($this->user)->for($cartao)->expense()->create(['amount' => 500]);

        $this->actingAs($this->user)
            ->put("/accounts/{$cartao->id}", [
                'name' => 'Cartão Roxo',
                'type' => 'checking',
                'bank' => 'nubank',
                'initial_balance' => '0,00',
            ])
            ->assertSessionHasErrors('type');

        $this->assertSame('credit_card', $cartao->fresh()->type);
    }

    /** Conta com dinheiro guardado (aporte) também não troca de classe. */
    public function test_conta_com_aporte_de_investimento_nao_troca_de_classe(): void
    {
        $corrente = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 3000]);
        $investimento = Investment::factory()->for($this->user)->create();
        InvestmentContribution::factory()->for($investimento)->for($corrente)->aporte()->create(['amount' => 1000]);

        $this->actingAs($this->user)
            ->put("/accounts/{$corrente->id}", [
                'name' => $corrente->name,
                'type' => 'credit_card',
                'bank' => 'nubank',
                'credit_limit' => '5.000,00',
                'closing_day' => 10,
                'due_day' => 20,
            ])
            ->assertSessionHasErrors('type');

        $this->assertSame('checking', $corrente->fresh()->type);
    }

    /** Trocar entre corrente e poupança (mesma classe "caixa") continua liberado. */
    public function test_corrente_com_historico_pode_virar_poupanca(): void
    {
        $corrente = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 2000]);
        Transaction::factory()->for($this->user)->for($corrente)->expense()->create(['amount' => 500]);

        $this->actingAs($this->user)
            ->put("/accounts/{$corrente->id}", [
                'name' => $corrente->name,
                'type' => 'savings',
                'bank' => 'nubank',
                'initial_balance' => '2.000,00',
            ])
            ->assertSessionHasNoErrors();

        $corrente->refresh();
        $this->assertSame('savings', $corrente->type);
        $this->assertSame(1500.0, $corrente->balance);
    }

    /** Conta zerada e sem histórico pode, sim, trocar de tipo (não travar demais). */
    public function test_conta_vazia_pode_trocar_de_tipo(): void
    {
        $conta = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 0]);

        $this->actingAs($this->user)
            ->put("/accounts/{$conta->id}", [
                'name' => 'Virou Cartão',
                'type' => 'credit_card',
                'bank' => 'nubank',
                'credit_limit' => '5.000,00',
                'closing_day' => 10,
                'due_day' => 20,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('credit_card', $conta->fresh()->type);
    }

    /** Na tela de edição, o select de tipo aparece travado quando há histórico. */
    public function test_tela_de_edicao_trava_o_select_de_tipo_quando_ha_historico(): void
    {
        $corrente = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 100]);
        Transaction::factory()->for($this->user)->for($corrente)->expense()->create(['amount' => 10]);

        $this->actingAs($this->user)->get("/accounts/{$corrente->id}/edit")
            ->assertOk()
            ->assertSee('data-type-locked', false);

        // Conta vazia segue editável.
        $vazia = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 0]);
        $this->actingAs($this->user)->get("/accounts/{$vazia->id}/edit")
            ->assertOk()
            ->assertDontSee('data-type-locked', false);
    }

    // ============ A-6 · estorno no cartão de crédito ============

    public function test_estorno_no_cartao_devolve_limite_e_abate_a_fatura(): void
    {
        $cartao = Account::factory()->for($this->user)->creditCard()->create(); // limite 5.000, fecha 10, vence 20
        Transaction::factory()->for($this->user)->for($cartao)->expense()->create([
            'amount' => 1000,
            'date' => now()->toDateString(),
        ]);

        $cartao = $cartao->fresh();
        $this->assertSame(1000.0, $cartao->committed);
        $this->assertSame(1000.0, $cartao->currentInvoice);
        $this->assertSame(1000.0, $cartao->openInvoiceDue);

        // Estorno de R$ 300 da loja: volta limite e abate a fatura.
        Transaction::factory()->for($this->user)->for($cartao)->income()->create([
            'amount' => 300,
            'date' => now()->toDateString(),
        ]);

        $cartao = $cartao->fresh();
        $this->assertSame(700.0, $cartao->committed, 'o estorno devolve limite');
        $this->assertSame(4300.0, $cartao->availableLimit);
        $this->assertSame(700.0, $cartao->currentInvoice, 'o estorno abate a fatura do ciclo');
        $this->assertSame(700.0, $cartao->openInvoiceDue, 'o estorno abate o que há a pagar');
    }

    /** Estorno maior que a dívida não vira fatura negativa nem limite extra. */
    public function test_estorno_maior_que_a_divida_nao_deixa_fatura_negativa(): void
    {
        $cartao = Account::factory()->for($this->user)->creditCard()->create();
        Transaction::factory()->for($this->user)->for($cartao)->expense()->create([
            'amount' => 100,
            'date' => now()->toDateString(),
        ]);
        Transaction::factory()->for($this->user)->for($cartao)->income()->create([
            'amount' => 400,
            'date' => now()->toDateString(),
        ]);

        $cartao = $cartao->fresh();
        $this->assertSame(0.0, $cartao->committed);
        $this->assertSame(0.0, $cartao->currentInvoice);
        $this->assertSame(0.0, $cartao->openInvoiceDue);
        $this->assertSame(5000.0, $cartao->availableLimit, 'o limite não passa do teto do cartão');
    }

    // ============ M-16 · $ignore nos accessors de cheque especial ============

    public function test_accessors_de_cheque_especial_aceitam_o_valor_ignorado(): void
    {
        $conta = Account::factory()->for($this->user)->overdraft(2500)->create(['initial_balance' => 0]);
        Transaction::factory()->for($this->user)->for($conta)->expense()->create(['amount' => 2000]);

        $conta = $conta->fresh();

        // Sem ignorar nada: a conta está R$ 2.000 no vermelho.
        $this->assertSame(-2000.0, $conta->available);
        $this->assertSame(2000.0, $conta->overdraftUsed);
        $this->assertSame(500.0, $conta->overdraftAvailable);
        $this->assertSame(500.0, $conta->spendable);

        // Editando a PRÓPRIA despesa de R$ 2.000: ela não pode contar contra si mesma.
        $this->assertSame(0.0, $conta->availableWith(2000));
        $this->assertSame(0.0, $conta->overdraftUsedWith(2000));
        $this->assertSame(2500.0, $conta->overdraftAvailableWith(2000), 'o cheque especial inteiro volta a caber');
        $this->assertSame(2500.0, $conta->spendableWith(2000));
    }

    // ============ M-17 · cartão de débito mostra o DISPONÍVEL ============

    public function test_cartao_de_debito_mostra_o_disponivel_das_contas_vinculadas(): void
    {
        $corrente = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 5000]);
        $poupanca = Account::factory()->for($this->user)->create(['type' => 'savings', 'initial_balance' => 1000]);

        // R$ 2.000 da corrente estão aplicados: não são para gastar.
        $investimento = Investment::factory()->for($this->user)->create();
        InvestmentContribution::factory()->for($investimento)->for($corrente)->aporte()->create(['amount' => 2000]);

        $debito = Account::factory()->for($this->user)->debitCard($corrente->id)->create([
            'savings_account_id' => $poupanca->id,
        ]);

        $this->assertSame(3000.0, $debito->availableChecking);
        $this->assertSame(1000.0, $debito->availableSavings);
        $this->assertSame(4000.0, $debito->available, 'o cartão de débito espelha o DISPONÍVEL, não o bruto');

        $this->actingAs($this->user)->get('/accounts')
            ->assertOk()
            ->assertSee('4.000,00')
            ->assertDontSee('6.000,00');
    }

    // ============ N+1 · pré-carga em lote dá os MESMOS números ============

    public function test_preload_money_bate_com_os_accessors_conta_a_conta(): void
    {
        $corrente = Account::factory()->for($this->user)->overdraft(1000)->create(['initial_balance' => 5000]);
        $poupanca = Account::factory()->for($this->user)->create(['type' => 'savings', 'initial_balance' => 1200]);
        $cartao = Account::factory()->for($this->user)->creditCard()->create();
        $debito = Account::factory()->for($this->user)->debitCard($corrente->id)->create([
            'savings_account_id' => $poupanca->id,
        ]);

        Transaction::factory()->for($this->user)->for($corrente)->expense()->create(['amount' => 800]);
        Transaction::factory()->for($this->user)->for($corrente)->income()->create(['amount' => 300]);
        Transaction::factory()->for($this->user)->for($cartao)->expense()->create([
            'amount' => 400, 'date' => now()->toDateString(),
        ]);

        $investimento = Investment::factory()->for($this->user)->create();
        InvestmentContribution::factory()->for($investimento)->for($corrente)->aporte()->create(['amount' => 1000]);

        // Valores de referência, calculados um a um (caminho antigo).
        $esperado = [];
        foreach ([$corrente, $poupanca, $cartao, $debito] as $conta) {
            $fresca = Account::with(['linkedChecking', 'linkedSavings'])->findOrFail($conta->id);
            $esperado[$conta->id] = [
                'balance' => $fresca->balance,
                'reserved' => $fresca->reserved,
                'available' => $fresca->available,
                'committed' => $fresca->committed,
            ];
        }

        // Mesmos números, mas em lote.
        $lote = Account::with(['linkedChecking', 'linkedSavings'])
            ->where('user_id', $this->user->id)->orderBy('id')->get();
        Account::preloadMoney($lote);

        foreach ($lote as $conta) {
            $this->assertSame($esperado[$conta->id]['balance'], $conta->balance, "saldo da conta {$conta->name}");
            $this->assertSame($esperado[$conta->id]['reserved'], $conta->reserved, "reservado da conta {$conta->name}");
            $this->assertSame($esperado[$conta->id]['available'], $conta->available, "disponível da conta {$conta->name}");
            $this->assertSame($esperado[$conta->id]['committed'], $conta->committed, "comprometido da conta {$conta->name}");
        }

        // Confere um número absoluto, para o teste não passar por acidente.
        $this->assertSame(4500.0, $esperado[$corrente->id]['balance']); // 5000 − 800 + 300
        $this->assertSame(3500.0, $esperado[$corrente->id]['available']); // − 1000 aplicados
        $this->assertSame(400.0, $esperado[$cartao->id]['committed']);
        $this->assertSame(4700.0, $esperado[$debito->id]['available']); // 3500 + 1200
    }

    // ============ BAIXO · refresh() limpa os caches de dinheiro ============

    public function test_refresh_limpa_os_caches_de_dinheiro(): void
    {
        $conta = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 1000]);

        $this->assertSame(1000.0, $conta->balance); // memoiza

        Transaction::factory()->for($this->user)->for($conta)->expense()->create(['amount' => 250]);

        $conta->refresh();

        $this->assertSame(750.0, $conta->balance, 'refresh() precisa invalidar os caches de dinheiro');
    }
}
