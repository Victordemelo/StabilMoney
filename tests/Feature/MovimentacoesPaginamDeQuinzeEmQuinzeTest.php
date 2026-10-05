<?php

namespace Tests\Feature;

use App\Http\Controllers\TransactionController;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Movimentações: a lista cresce até 15 itens, SEM rolagem interna, e depois pagina (out/2026 —
 * pedido do Victor). Antes ela herdava a regra das "recentes" do painel (4 itens visíveis e
 * rolagem dentro do card) e a página de 30 ficava escondida atrás de uma barrinha.
 */
class MovimentacoesPaginamDeQuinzeEmQuinzeTest extends TestCase
{
    use RefreshDatabase;

    public function test_quinze_por_pagina_e_o_resto_na_pagina_seguinte(): void
    {
        $this->assertSame(15, TransactionController::POR_PAGINA);

        $user = User::factory()->create();
        $conta = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 0]);
        foreach (range(1, 17) as $n) {
            Transaction::factory()->create([
                'user_id' => $user->id, 'account_id' => $conta->id, 'type' => 'income', 'amount' => 10,
                'description' => sprintf('Movimento %02d', $n), 'date' => now()->subDays($n)->toDateString(),
            ]);
        }

        $pagina1 = $this->actingAs($user)->get(route('transactions.index'))->assertOk();
        $this->assertSame(15, substr_count($pagina1->getContent(), 'class="tx" href='));
        $pagina1->assertSee('class="tx-list tx-list-cheia"', false)
            ->assertSee('Movimento 01')->assertSee('Movimento 15')->assertDontSee('Movimento 16');

        $pagina2 = $this->get(route('transactions.index', ['page' => 2]))->assertOk();
        $this->assertSame(2, substr_count($pagina2->getContent(), 'class="tx" href='));
        $pagina2->assertSee('Movimento 16')->assertSee('Movimento 17');
    }

    public function test_a_lista_cheia_nao_tem_rolagem_interna_e_o_painel_mantem_a_dele(): void
    {
        $css = file_get_contents(resource_path('css/design-system.css'));

        $this->assertMatchesRegularExpression('/\.tx-list\.tx-list-cheia\s*\{[^}]*max-height:\s*none;[^}]*overflow:\s*visible;/', $css);
        // As "recentes" do painel continuam com 4 itens e rolagem própria.
        $this->assertMatchesRegularExpression('/\.tx-list\s*\{[^}]*max-height:\s*calc\(var\(--tx-item-h\) \* 4\);[^}]*overflow-y:\s*auto;/', $css);
    }
}
