<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * O card de Dependentes ("Gastou no mês", "Gasto da família no mês", "Quem mais
 * gastou") contava o PAGAMENTO DA FATURA do cartão como gasto de quem clicou em
 * "Marcar como paga".
 *
 * A saída de caixa que quita a fatura (`settles_account_id`) não é gasto novo: o
 * gasto foi a compra no cartão, que já entrou na conta de quem comprou. É a regra
 * do modelo de dinheiro ("conta no saldo e no extrato, mas não nas somas de
 * despesa — senão pagar o cartão dobra o gasto do período"), e o dashboard, a
 * tela Pagar despesas e o donut a seguem. O `DependentController::index` filtrava
 * a transferência (`transfer_group_id`), mas não a quitação: a família que gastou
 * R$ 1.000 no cartão aparecia com R$ 2.000 de gasto no mês, e o titular que só
 * pagou a fatura virava "quem mais gastou".
 *
 * Cartão fecha dia 10, vence dia 20; hoje = 15/08/2026.
 */
class GastoDaFamiliaNaoContaPagamentoDeFaturaTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    private User $maria;

    private Account $conta;

    private Account $cartao;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-15');

        $this->titular = User::factory()->create(['name' => 'Victor Rosa']);
        $this->maria = User::factory()->create([
            'name' => 'Maria Silva',
            'account_owner_id' => $this->titular->id,
            'is_admin' => false,
            'relationship' => 'conjuge',
        ]);
        $this->conta = Account::factory()->for($this->titular)->create([
            'type' => 'checking', 'name' => 'Corrente', 'initial_balance' => 10000,
        ]);
        $this->cartao = Account::factory()->for($this->titular)->creditCard()->create([
            'name' => 'Nubank', 'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 20,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Maria compra no cartão pela tela Pagar despesas (autor = quem lançou). */
    private function mariaCompraNoCartao(string $valor = '1000,00'): void
    {
        $this->actingAs($this->maria)
            ->post(route('faturas.lancar'), [
                'description' => 'Geladeira',
                'amount' => $valor,
                'date' => '2026-08-12',
                'account_id' => $this->cartao->id,
                'mode' => 'avista',
            ])
            ->assertRedirect(route('faturas.index'))
            ->assertSessionHasNoErrors();
    }

    /** O titular paga a fatura do ciclo aberto com a conta corrente. */
    private function titularPagaAFatura(): void
    {
        $this->actingAs($this->titular)
            ->from(route('faturas.index'))
            ->post(route('faturas.fatura.pagar', $this->cartao), [
                'pay_account_id' => $this->conta->id,
                'ciclo' => 'aberto',
            ])
            ->assertRedirect(route('faturas.index'))
            ->assertSessionHasNoErrors();
    }

    private function dependentes(): TestResponse
    {
        return $this->actingAs($this->titular)->get(route('dependentes'))->assertOk();
    }

    public function test_pagar_a_fatura_nao_vira_gasto_de_quem_pagou(): void
    {
        $this->mariaCompraNoCartao();
        $this->titularPagaAFatura();

        // Cenário montado: a quitação existe, é do titular, e é saída de caixa.
        $quitacao = Transaction::whereNotNull('settles_account_id')->sole();
        $this->assertSame($this->titular->id, $quitacao->made_by_user_id);
        $this->assertSame(1000.0, (float) $quitacao->amount);

        $tela = $this->dependentes();
        $maria = $tela->viewData('dependents')->firstWhere('id', $this->maria->id);

        // A família gastou R$ 1.000 no mês — a geladeira. Pagar a fatura não é gastar
        // de novo.
        $this->assertSame(1000.0, (float) $tela->viewData('gastoFamilia'));
        $this->assertSame(1000.0, (float) $maria->gasto);
        $this->assertSame(0.0, (float) $tela->viewData('gastoTitular'));
    }

    public function test_o_card_concorda_com_o_dashboard_sobre_o_gasto_do_mes(): void
    {
        $this->mariaCompraNoCartao();
        $this->titularPagaAFatura();

        // O dashboard já exclui a quitação das despesas do mês (`settles_account_id`).
        $despesasDoMes = (float) $this->actingAs($this->titular)->get(route('dashboard'))
            ->assertOk()
            ->viewData('monthStats')['despesas'];

        $this->assertSame(1000.0, $despesasDoMes);
        $this->assertSame($despesasDoMes, (float) $this->dependentes()->viewData('gastoFamilia'));
    }

    public function test_quem_so_pagou_a_fatura_nao_e_quem_mais_gastou(): void
    {
        // Maria gastou R$ 1.000 no cartão; o titular, R$ 200 no débito da corrente.
        $this->mariaCompraNoCartao();
        Transaction::factory()->for($this->titular)->for($this->conta)->expense()->create([
            'amount' => 200, 'date' => '2026-08-14', 'made_by_user_id' => $this->titular->id,
        ]);
        $this->titularPagaAFatura();

        // O resumo do topo, como a tela o mostra: o total da família e quem mais gastou.
        // Antes: R$ 2.200,00 e o titular — que só gastou R$ 200 e pagou a fatura dela.
        $html = $this->dependentes()->getContent();

        $this->assertMatchesRegularExpression('#Gasto da família no mês</span>\s*<span class="val">R\$ 1\.200,00</span>#u', $html);
        $this->assertMatchesRegularExpression('#Quem mais gastou</span>\s*<span class="val">Maria Silva</span>#u', $html);
    }
}
