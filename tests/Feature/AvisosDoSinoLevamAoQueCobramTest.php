<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cada aviso do sino leva ao que ele cobra (14/10/2026 — pedido do Victor: "clicar na fatura de
 * crédito tem que me levar até o cartão; o boleto, até a conta fixa"). O link aponta para a âncora
 * do bloco em Contas a pagar, e o bloco tem esse `id` — o `sm/ancora.js` abre e rola até ele
 * (tests/js/ancora.test.js).
 */
class AvisosDoSinoLevamAoQueCobramTest extends TestCase
{
    use RefreshDatabase;

    public function test_fatura_leva_ao_cartao_e_conta_fixa_a_linha_dela(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-14 10:00:00'));
        $user = User::factory()->create();
        $conta = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 5000]);
        $cartao = Account::factory()->for($user)->creditCard()->create([
            'name' => 'Nubank', 'closing_day' => 2, 'due_day' => 9, 'credit_limit' => 5000,
        ]);
        Transaction::factory()->for($user)->expense()->create(['account_id' => $cartao->id, 'amount' => 120, 'date' => '2026-09-20']);
        $fixa = FixedBill::factory()->create([
            'user_id' => $user->id, 'name' => 'Condomínio', 'amount' => 450, 'due_day' => 16,
            'account_id' => $conta->id, 'starts_on' => '2026-10-01', 'active' => true,
        ]);

        $html = $this->actingAs($user)->get(route('faturas.index'))->assertOk()->getContent();

        $paraOCartao = route('faturas.index').'#cartao-'.$cartao->id;
        $paraAConta = route('faturas.index').'#conta-fixa-'.$fixa->id.'-2026-10';
        // No sino do computador e no do celular.
        $this->assertSame(2, substr_count($html, '<a class="notif-item" href="'.$paraOCartao.'" data-ir-para>'));
        $this->assertSame(2, substr_count($html, '<a class="notif-item" href="'.$paraAConta.'" data-ir-para>'));
        // E os alvos existem na tela.
        $this->assertStringContainsString('id="cartao-'.$cartao->id.'"', $html);
        $this->assertStringContainsString('<div class="fatura-item" id="conta-fixa-'.$fixa->id.'-2026-10">', $html);
    }

    public function test_recorrencia_fora_do_cartao_leva_a_edicao_dela(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-14 10:00:00'));
        $user = User::factory()->create();
        $conta = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 5000]);
        $rec = Transaction::factory()->for($user)->expense()->create([
            'account_id' => $conta->id, 'amount' => 30, 'date' => '2026-10-16', 'recurring' => true,
            'description' => 'Streaming', 'group_id' => 'g-1',
        ]);

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('<a class="notif-item" href="'.route('transactions.edit', $rec->id).'" data-ir-para>', $html);
    }
}
