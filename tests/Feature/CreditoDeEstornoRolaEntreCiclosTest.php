<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FaturaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * F-2 (auditoria de 02/09/2026): o CRÉDITO de um estorno de cartão rola para a
 * fatura seguinte, e uma fatura com líquido ≤ 0 é quitada sem sair dinheiro.
 *
 * Antes, o piso 0 era aplicado janela a janela: um estorno maior que as compras
 * do ciclo dele era perdido (não abatia a fatura seguinte) e `payInvoice`
 * respondia "já estava quitada", deixando as linhas sem `paid_at` para sempre.
 *
 * Cartão fecha dia 10 / vence dia 20.
 */
class CreditoDeEstornoRolaEntreCiclosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    private Account $cartao;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-05');

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Corrente', 'initial_balance' => 10000, 'overdraft_limit' => 0,
        ]);
        $this->cartao = Account::factory()->for($this->user)->creditCard()->create([
            'name' => 'Nubank', 'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 20,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function compra(string $data, float $valor, string $descricao): Transaction
    {
        return Transaction::factory()->for($this->user)->for($this->cartao)->expense()->create([
            'amount' => $valor, 'date' => $data, 'description' => $descricao,
        ]);
    }

    private function estorno(string $data, float $valor, string $descricao): Transaction
    {
        return Transaction::factory()->for($this->user)->for($this->cartao)->income()->create([
            'amount' => $valor, 'date' => $data, 'description' => $descricao,
        ]);
    }

    private function pagar(string $ciclo): TestResponse
    {
        return $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $this->conta->id,
            'ciclo' => $ciclo,
        ]);
    }

    private function cartao(): Account
    {
        return Account::find($this->cartao->id);
    }

    private function saidasDoCaixa(): float
    {
        return round((float) Transaction::where('settles_account_id', $this->cartao->id)->sum('amount'), 2);
    }

    /** O cenário do relatório: o caixa sai 1.000 + 0 + 100 = 1.100, não 1.700. */
    public function test_credito_do_estorno_rola_e_o_caixa_paga_so_o_que_deve(): void
    {
        // Ciclo (10/07, 10/08]: compra de 1.000.
        $this->compra('2026-08-01', 1000, 'Notebook');

        // 15/08: a fatura de agosto fechou; paga os 1.000.
        Carbon::setTestNow('2026-08-15');
        $this->pagar('fechado')->assertSessionHasNoErrors();
        $this->assertSame(1000.0, $this->saidasDoCaixa());

        // Ciclo (10/08, 10/09]: estorno do notebook + compra de 400.
        $this->estorno('2026-08-15', 1000, 'Estorno do notebook');
        $this->compra('2026-08-16', 400, 'Mercado');

        // 15/09: a fatura de setembro fechou com líquido −600 → nada a pagar,
        // e o crédito de 600 fica para a próxima.
        Carbon::setTestNow('2026-09-15');
        $cartao = $this->cartao();
        $this->assertSame(0.0, $cartao->closedInvoiceDue, 'fatura fechada em crédito cobra zero');
        $this->assertSame(-600.0, $cartao->closedInvoiceNet, 'o crédito remanescente é 600');
        $this->assertNull($cartao->closedInvoice, 'sem dívida fechada, sem botão de pagar fechada');

        $this->pagar('fechado')->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'crédito') && str_contains($s, '600,00'));
        $this->assertSame(1000.0, $this->saidasDoCaixa(), 'setembro não tirou nada do caixa');

        // Ciclo (10/09, 10/10]: compra de 700 → a fatura em aberto é 700 − 600 = 100.
        $this->compra('2026-09-20', 700, 'Tênis');
        $cartao = $this->cartao();
        $this->assertSame(100.0, $cartao->openInvoiceDue, 'o crédito de 600 abate a compra de 700');
        $this->assertSame(100.0, $cartao->committed, 'o limite preso é o líquido de tudo não pago');
        $this->assertSame(4900.0, $cartao->availableLimit);

        // 15/10: a fatura de outubro fechou; paga os 100.
        Carbon::setTestNow('2026-10-15');
        $cartao = $this->cartao();
        $this->assertSame(100.0, $cartao->closedInvoiceDue);
        // O vencimento é o da fatura de OUTUBRO (a dívida começa na compra de
        // 20/09), não o de setembro — as compras de agosto foram cobertas pelo crédito.
        $this->assertSame('2026-10-20', $cartao->closedInvoice['vencimento']->toDateString());
        $this->assertFalse($cartao->closedInvoice['vencida']);

        $this->pagar('fechado')->assertSessionHasNoErrors();

        $this->assertSame(1100.0, $this->saidasDoCaixa(), 'total: 1.000 + 0 + 100');
        $this->assertSame(8900.0, Account::find($this->conta->id)->available);

        // NENHUMA linha em aberto sobra no cartão.
        $this->assertSame(0, Transaction::where('account_id', $this->cartao->id)->whereNull('paid_at')->count());
        $cartao = $this->cartao();
        $this->assertSame(0.0, $cartao->committed);
        $this->assertSame(5000.0, $cartao->availableLimit);
        $this->assertSame(0.0, $cartao->closedInvoiceDue);
        $this->assertSame(0.0, $cartao->openInvoiceDue);
    }

    /** Pagar o ciclo ABERTO havendo crédito fechado consome o crédito e quita as duas janelas. */
    public function test_pagar_o_ciclo_aberto_consome_o_credito_do_fechado(): void
    {
        $this->estorno('2026-08-15', 1000, 'Estorno');
        $this->compra('2026-08-16', 400, 'Mercado');

        Carbon::setTestNow('2026-09-20');
        $this->compra('2026-09-20', 700, 'Tênis');

        $this->assertSame(100.0, $this->cartao()->openInvoiceDue);

        $this->pagar('aberto')->assertSessionHasNoErrors();

        $this->assertSame(100.0, $this->saidasDoCaixa(), 'o caixa sai só o que falta depois do crédito');
        $this->assertSame(0, Transaction::where('account_id', $this->cartao->id)->whereNull('paid_at')->count());

        // Todas apontam para a MESMA quitação — o estorno do pagamento desfaz tudo junto.
        $quitacao = Transaction::where('settles_account_id', $this->cartao->id)->firstOrFail();
        $this->assertSame(
            3,
            Transaction::where('account_id', $this->cartao->id)->where('settled_by_id', $quitacao->id)->count(),
        );
    }

    /** Pagar o ciclo aberto NÃO arrasta uma dívida fechada (só o crédito é consumido). */
    public function test_pagar_o_ciclo_aberto_nao_arrasta_divida_fechada(): void
    {
        $this->compra('2026-08-01', 500, 'Dívida fechada');
        Carbon::setTestNow('2026-08-20');
        $this->compra('2026-08-20', 200, 'Compra do aberto');

        $this->pagar('aberto')->assertSessionHasNoErrors();

        $this->assertSame(200.0, $this->saidasDoCaixa());
        $this->assertSame(500.0, $this->cartao()->closedInvoiceDue, 'a dívida fechada continua devendo');
    }

    /** Estorno maior que TODA a dívida: fatura 0 e o limite volta ao teto, sem passar. */
    public function test_estorno_maior_que_toda_a_divida(): void
    {
        Carbon::setTestNow('2026-08-20');
        $this->compra('2026-08-15', 300, 'Compra');
        $this->estorno('2026-08-20', 1000, 'Estorno gordo');

        $cartao = $this->cartao();
        $this->assertSame(0.0, $cartao->closedInvoiceDue + $cartao->openInvoiceDue);
        $this->assertSame(0.0, $cartao->committed);
        $this->assertSame(5000.0, $cartao->availableLimit, 'o limite não passa do teto do cartão');

        // Pagar não tira nada do caixa e avisa o crédito que fica.
        $this->pagar('aberto')->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'crédito') && str_contains($s, '700,00'));
        $this->assertSame(0.0, $this->saidasDoCaixa());
        $this->assertSame(10000.0, Account::find($this->conta->id)->available);

        // O crédito de 700 continua disponível para a próxima compra.
        Carbon::setTestNow('2026-09-20');
        $this->compra('2026-09-20', 900, 'Compra nova');
        $this->assertSame(200.0, $this->cartao()->openInvoiceDue, '900 − 700 de crédito');
    }

    /** Estorno que cobre EXATAMENTE as compras: as linhas ficam pagas sem sair dinheiro. */
    public function test_liquido_zero_quita_as_linhas_sem_saida_de_caixa(): void
    {
        $this->compra('2026-08-01', 300, 'Compra');
        $this->estorno('2026-08-02', 300, 'Estorno total');

        $this->pagar('aberto')->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'crédito'));

        $this->assertSame(0.0, $this->saidasDoCaixa());
        $this->assertSame(0, Transaction::where('account_id', $this->cartao->id)->whereNull('paid_at')->count());
        $this->assertSame(0, Transaction::where('settles_account_id', $this->cartao->id)->count(), 'sem linha de quitação');
    }

    /** Estorno no MESMO ciclo da compra: nada regrediu. */
    public function test_estorno_no_mesmo_ciclo_continua_abatendo(): void
    {
        $this->compra('2026-08-01', 1000, 'Compra');
        $this->estorno('2026-08-02', 300, 'Estorno parcial');

        $cartao = $this->cartao();
        $this->assertSame(700.0, $cartao->openInvoiceDue);
        $this->assertSame(700.0, $cartao->committed);

        $this->pagar('aberto')->assertSessionHasNoErrors();
        $this->assertSame(700.0, $this->saidasDoCaixa());
        $this->assertSame(0, Transaction::where('account_id', $this->cartao->id)->whereNull('paid_at')->count());
    }

    /** Estornar o pagamento que consumiu o crédito devolve as linhas (e o crédito) ao estado anterior. */
    public function test_estornar_o_pagamento_devolve_o_credito(): void
    {
        $this->estorno('2026-08-15', 1000, 'Estorno');
        $this->compra('2026-08-16', 400, 'Mercado');
        Carbon::setTestNow('2026-09-20');
        $this->compra('2026-09-20', 700, 'Tênis');

        $this->pagar('aberto')->assertSessionHasNoErrors();
        $quitacao = Transaction::where('settles_account_id', $this->cartao->id)->firstOrFail();

        $this->actingAs($this->user)->delete(route('faturas.fatura.estornar', $quitacao))
            ->assertSessionHasNoErrors();

        $this->assertSame(0.0, $this->saidasDoCaixa());
        $this->assertSame(10000.0, Account::find($this->conta->id)->available);
        $cartao = $this->cartao();
        $this->assertSame(100.0, $cartao->openInvoiceDue, 'a fatura volta a 700 − 600');
        $this->assertSame(-600.0, $cartao->closedInvoiceNet);
    }

    /** O total do topo de /faturas e o dashboard enxergam o crédito. */
    public function test_tela_de_faturas_mostra_a_fatura_ja_abatida(): void
    {
        $this->estorno('2026-08-15', 1000, 'Estorno');
        $this->compra('2026-08-16', 400, 'Mercado');
        Carbon::setTestNow('2026-09-20');
        $this->compra('2026-09-20', 700, 'Tênis');

        $dados = app(FaturaService::class)->build($this->user->id);
        $this->assertSame(100.0, $dados['stats']['totalFaturas']);
        $this->assertSame(100.0, $dados['cards'][0]['invoiceDue']);
    }
}
