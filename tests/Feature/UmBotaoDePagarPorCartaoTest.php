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

    private function compra(string $data, float $valor): void
    {
        Transaction::factory()->for($this->user)->expense()->create([
            'account_id' => $this->cartao->id, 'amount' => $valor, 'date' => $data,
        ]);
    }

    private function botoesDePagar(string $html): int
    {
        return substr_count($html, 'data-pay-open');
    }

    public function test_com_fatura_vencida_so_ela_tem_botao(): void
    {
        $this->compra('2026-09-20', 200); // fatura que fechou em 02/10 e venceu em 09/10
        $this->compra('2026-10-05', 80);  // fatura atual

        $html = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();

        $this->assertSame(1, $this->botoesDePagar($html));
        $this->assertStringContainsString('Pagar fatura vencida', $html);
        $this->assertStringContainsString('Depois dela, a fatura atual: <b>R$ 80,00</b>', $html);
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
}
