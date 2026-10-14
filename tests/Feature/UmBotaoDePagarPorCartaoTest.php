<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Um botão de pagar por cartão em Contas a pagar (13/10/2026 — pedido do Victor: "não pode ter
 * Pagar fatura vencida e Marcar como paga, não faz sentido; a vencida eu vou ter que pagar
 * primeiro"). Com uma fatura fechada em aberto, ela é a que se paga; a do ciclo atual aparece só
 * como informação. Sem fatura fechada, o botão é "Pagar fatura".
 */
class UmBotaoDePagarPorCartaoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $cartao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(new \DateTimeImmutable('2026-10-13 10:00:00'));
        $this->user = User::factory()->create();
        Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 5000]);
        $this->cartao = Account::factory()->for($this->user)->creditCard()->create([
            'name' => 'Mercado Pago', 'closing_day' => 2, 'due_day' => 9, 'credit_limit' => 5000,
        ]);
    }

    private function compra(string $data, float $valor, ?string $descricao = null): Transaction
    {
        return Transaction::factory()->for($this->user)->expense()->create([
            'account_id' => $this->cartao->id, 'amount' => $valor, 'date' => $data, 'description' => $descricao,
        ]);
    }

    private function botoesDePagar(string $html): int
    {
        return substr_count($html, 'data-pay-open');
    }

    public function test_com_fatura_vencida_so_ela_tem_botao(): void
    {
        $this->compra('2026-09-20', 200, 'Compra antiga'); // fatura que fechou em 02/10 e venceu em 09/10
        $this->compra('2026-10-05', 80, 'Padaria');        // fatura atual

        $html = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();

        $this->assertSame(1, $this->botoesDePagar($html));
        $this->assertStringContainsString('Pagar fatura vencida', $html);
        // Enquanto a vencida não é paga, o cartão fala só dela: o valor dela no cabeçalho, as compras
        // dela na lista — a fatura atual (R$ 80, a Padaria) e as próximas esperam (13/10/2026).
        $this->assertMatchesRegularExpression('#<em class="fh-vencida">vencida</em></span>\s*<b>R\$ 200,00</b>#', $html);
        $this->assertStringContainsString('A fatura atual aparece aqui depois que esta for paga.', $html);
        $this->assertStringContainsString('<strong>Compra antiga</strong>', $html);
        $this->assertStringNotContainsString('<strong>Padaria</strong>', $html);
        $this->assertStringNotContainsString('R$ 80,00', $html);
        $this->assertStringContainsString('data-ciclo="fechado"', $html);
        $this->assertStringNotContainsString('data-ciclo="aberto"', $html);
    }

    public function test_sem_fatura_fechada_o_botao_e_pagar_fatura(): void
    {
        $this->compra('2026-10-05', 80);

        $html = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();

        $this->assertSame(1, $this->botoesDePagar($html));
        $this->assertMatchesRegularExpression('#data-ciclo="aberto"[^>]*>\s*Pagar fatura\s*</button>#', $html);
        $this->assertStringNotContainsString('Marcar como paga', $html);
        $this->assertStringNotContainsString('Depois dela', $html);
    }

    public function test_depois_de_paga_a_vencida_volta_a_fatura_atual(): void
    {
        $this->compra('2026-09-20', 200, 'Compra antiga');
        $this->compra('2026-10-05', 80, 'Padaria');
        $conta = Account::where('user_id', $this->user->id)->where('type', 'checking')->firstOrFail();
        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $conta->id, 'ciclo' => 'fechado', 'paid_on' => '2026-10-13',
        ])->assertSessionHasNoErrors();

        $html = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<span>Fatura atual</span>\s*<b>R\$ 80,00</b>#', $html);
        $this->assertStringContainsString('<strong>Padaria</strong>', $html);
        $this->assertStringNotContainsString('<strong>Compra antiga</strong>', $html);
    }

    public function test_as_compras_e_as_despesas_em_conta_abrem_a_movimentacao(): void
    {
        $compra = $this->compra('2026-10-05', 80, 'Aspirador de pó');
        $conta = Account::where('user_id', $this->user->id)->where('type', 'checking')->firstOrFail();
        $conta->update(['bank' => 'itau']);
        $pix = Transaction::factory()->for($this->user)->expense()->create(['account_id' => $conta->id, 'amount' => 20, 'date' => '2026-10-10', 'description' => 'Feira']);

        $html = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<a class="fi-txt" href="'.route('transactions.edit', $compra->id).'">', $html);
        $this->assertStringContainsString('<a class="fi-txt" href="'.route('transactions.edit', $pix->id).'">', $html);
        // Despesa em conta: o ícone das movimentações, com o banco de onde saiu no canto.
        $this->assertMatchesRegularExpression('#<img class="tx-banco" src="[^"]*icones/itau\.png"#', $html);
        // Duas colunas em tela grande.
        $this->assertSame(2, substr_count($html, '<div class="faturas-colunas">'));
    }
}
