<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Listas longas de Contas a pagar rolam por dentro (14/10/2026 — pedido do Victor: "conta fixa e
 * despesa em conta no máximo até 8, depois rolagem; cartões até 10"). O servidor marca cada lista
 * com `data-rolagem` e o `sm/lista-rolavel.js` mede a altura (tests/js/lista-rolavel.test.js).
 */
class ListasDeContasAPagarRolamTest extends TestCase
{
    use RefreshDatabase;

    public function test_cada_lista_leva_o_seu_limite(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-14 10:00:00'));
        $user = User::factory()->create();
        $conta = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 9000]);
        $cartao = Account::factory()->for($user)->creditCard()->create(['closing_day' => 2, 'due_day' => 9, 'credit_limit' => 9000]);
        FixedBill::factory()->create([
            'user_id' => $user->id, 'name' => 'Internet', 'amount' => 100, 'due_day' => 16,
            'account_id' => $conta->id, 'starts_on' => '2026-10-01', 'active' => true,
        ]);
        FixedBill::factory()->create([
            'user_id' => $user->id, 'name' => 'Aluguel', 'amount' => 100, 'due_day' => 28,
            'account_id' => $conta->id, 'starts_on' => '2026-10-01', 'active' => true,
        ]);
        Transaction::factory()->for($user)->expense()->create(['account_id' => $conta->id, 'amount' => 10, 'date' => '2026-10-10']);
        Transaction::factory()->for($user)->expense()->create(['account_id' => $cartao->id, 'amount' => 10, 'date' => '2026-10-10']);

        $html = $this->actingAs($user)->get(route('faturas.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<div class="fatura-items" data-fixas-urgentes data-rolagem="8">', $html);
        $this->assertSame(2, substr_count($html, 'data-rolagem="8"') - 1, 'Contas fixas (as outras) e despesas em conta: 8.');
        $this->assertSame(1, substr_count($html, 'data-rolagem="10"'), 'A lista do cartão: 10.');

        $css = file_get_contents(resource_path('css/forms.css'));
        $this->assertMatchesRegularExpression('/\.fatura-items\.rolando \{[^}]*overflow-y: auto;[^}]*overscroll-behavior: contain;/', $css);
    }
}
