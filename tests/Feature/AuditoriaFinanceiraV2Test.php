<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Correções da 2ª auditoria de integridade financeira (28/07/2026).
 *
 * Dois buracos por onde dinheiro sumia ou nascia:
 *
 * 1) Conta fixa paga com CARTÃO DE CRÉDITO nascia com `paid_at` preenchido —
 *    a dívida não consumia limite, não entrava na fatura e nenhum caixa era
 *    debitado. Pagava-se indefinidamente acima do limite.
 * 2) A linha que QUITA uma fatura podia ser excluída sozinha: o dinheiro voltava
 *    para a conta E as compras seguiam quitadas — dinheiro criado em dobro.
 */
class AuditoriaFinanceiraV2Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15');

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)
            ->create(['type' => 'checking', 'initial_balance' => 10000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cartao(): Account
    {
        return Account::factory()->for($this->user)->create([
            'type' => 'credit_card', 'name' => 'Nubank',
            'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 20,
        ]);
    }

    private function condominio(array $overrides = []): FixedBill
    {
        return FixedBill::create(array_merge([
            'user_id' => $this->user->id,
            'name' => 'Condomínio',
            'amount' => 500,
            'due_day' => 10,
            'account_id' => $this->conta->id,
            'starts_on' => '2026-05-01',
            'active' => true,
        ], $overrides));
    }

    // ---------- 1) Conta fixa paga no cartão vira dívida na fatura ----------

    public function test_conta_fixa_paga_no_cartao_nasce_em_aberto_e_consome_limite(): void
    {
        $cartao = $this->cartao();
        $bill = $this->condominio();

        $this->actingAs($this->user)
            ->post(route('contas-fixas.pagar', [$bill, '2026-07']), [
                'amount' => '500,00',
                'account_id' => $cartao->id,
                'paid_on' => '2026-07-15',
            ])->assertSessionHasNoErrors();

        $lancamento = Transaction::where('fixed_bill_id', $bill->id)->firstOrFail();

        // Num cartão a competência é DÍVIDA: nasce em aberto para entrar na fatura.
        $this->assertNull($lancamento->paid_at, 'no cartão a conta fixa não pode nascer quitada');
        $this->assertSame($cartao->id, $lancamento->account_id);

        $cartao->refresh();
        // Antes da correção: committed = 0 e limite intacto em 5.000.
        $this->assertSame(500.0, $cartao->committed, 'a dívida precisa consumir limite');
        $this->assertSame(4500.0, $cartao->availableLimit);
        $this->assertSame(500.0, $cartao->openInvoiceDue, 'precisa entrar na fatura a pagar');

        // E nenhum dinheiro saiu da conta corrente.
        $this->assertSame(10000.0, $this->conta->fresh()->balance);
    }

    public function test_conta_fixa_paga_em_conta_de_caixa_continua_nascendo_quitada(): void
    {
        $bill = $this->condominio();

        $this->actingAs($this->user)
            ->post(route('contas-fixas.pagar', [$bill, '2026-07']), [
                'amount' => '500,00',
                'account_id' => $this->conta->id,
                'paid_on' => '2026-07-15',
            ])->assertSessionHasNoErrors();

        $lancamento = Transaction::where('fixed_bill_id', $bill->id)->firstOrFail();

        // Em caixa o dinheiro sai na hora: segue quitada (não regrediu).
        $this->assertNotNull($lancamento->paid_at);
        $this->assertSame(9500.0, $this->conta->fresh()->balance);
    }

    // ---------- 2) A quitação da fatura não se apaga sozinha ----------

    /** Paga a fatura do cartão e devolve a transação de quitação criada. */
    private function pagarFatura(Account $cartao): Transaction
    {
        Transaction::factory()->for($this->user)->for($cartao)->expense()
            ->create(['amount' => 1200, 'date' => '2026-07-15']);

        $this->actingAs($this->user)
            ->post(route('faturas.fatura.pagar', $cartao), ['pay_account_id' => $this->conta->id])
            ->assertSessionHasNoErrors();

        return Transaction::whereNotNull('settles_account_id')->firstOrFail();
    }

    public function test_nao_da_para_excluir_a_quitacao_da_fatura(): void
    {
        $cartao = $this->cartao();
        $quitacao = $this->pagarFatura($cartao);

        $saldoDepoisDoPagamento = $this->conta->fresh()->balance; // 10.000 − 1.200

        // Pelos DOIS caminhos que o usuário tem na tela.
        $this->actingAs($this->user)
            ->delete(route('faturas.compra.destroy', $quitacao))
            ->assertSessionHasErrors('transaction');

        $this->actingAs($this->user)
            ->delete(route('transactions.destroy', $quitacao))
            ->assertSessionHasErrors('transaction');

        // A quitação continua lá e o dinheiro NÃO voltou para a conta.
        $this->assertDatabaseHas('transactions', ['id' => $quitacao->id]);
        $this->assertSame($saldoDepoisDoPagamento, $this->conta->fresh()->balance);
        $this->assertSame(8800.0, $saldoDepoisDoPagamento);
    }

    public function test_quitacao_nao_aparece_como_despesa_avulsa_na_tela(): void
    {
        $cartao = $this->cartao();
        $this->pagarFatura($cartao);

        $avulsas = app(\App\Services\FaturaService::class)
            ->build($this->user->id)['accountExpenses'];

        // A quitação não é gasto novo: as compras que ela pagou já estão no cartão.
        $this->assertTrue(
            $avulsas->every(fn ($t) => $t->settles_account_id === null),
            'a linha de quitação não pode aparecer em "Despesas em conta"',
        );
    }

    public function test_despesa_comum_continua_podendo_ser_excluida(): void
    {
        $comum = Transaction::factory()->for($this->user)->for($this->conta)->expense()
            ->create(['amount' => 90, 'date' => '2026-07-15']);

        $this->actingAs($this->user)
            ->delete(route('transactions.destroy', $comum))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('transactions', ['id' => $comum->id]);
    }
}
