<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ajustes de out/2026 (pedidos do Victor): o cartão em Contas a pagar diz "Vence todo dia X" e
 * o melhor dia de compra (o dia seguinte ao fechamento); e cada categoria de "Gastos por
 * categoria" leva às Movimentações dela no mês.
 */
class CartaoECategoriasLevamAoDetalheTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_cartao_diz_o_dia_do_vencimento_e_o_melhor_dia_de_compra(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-08 10:00:00'));
        $user = User::factory()->create();
        $cartao = Account::factory()->for($user)->creditCard()->create(['name' => 'Crédito Santander', 'closing_day' => 3, 'due_day' => 10]);

        $this->assertSame(4, $cartao->melhorDiaDeCompra());
        $this->assertNull((new Account(['type' => 'checking']))->melhorDiaDeCompra());

        $html = $this->actingAs($user)->get(route('faturas.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#Vence todo dia 10\s*· melhor dia de compra: 4#', $html);
        $this->assertStringNotContainsString('vence dia 10', $html);
    }

    public function test_cada_categoria_do_painel_leva_as_movimentacoes_dela_no_mes(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-08 10:00:00'));
        $user = User::factory()->create();
        $conta = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 5000]);
        $transporte = Category::factory()->for($user)->create(['name' => 'Transporte', 'type' => 'expense']);
        Transaction::factory()->for($user)->create([
            'account_id' => $conta->id, 'category_id' => $transporte->id, 'type' => 'expense', 'amount' => 540.87, 'date' => '2026-10-05',
        ]);
        Transaction::factory()->for($user)->create([
            'account_id' => $conta->id, 'category_id' => null, 'type' => 'expense', 'amount' => 10, 'date' => '2026-10-05',
        ]);

        $cats = collect($this->actingAs($user)->get(route('dashboard'))->assertOk()->viewData('payload')['cats'])->keyBy('name');

        $this->assertSame(
            route('transactions.index', ['type' => 'expense', 'category' => $transporte->id, 'de' => '2026-10-01', 'ate' => '2026-10-31'], false),
            $cats['Transporte']['url'],
        );
        $this->assertNull($cats['Sem categoria']['url'], 'Sem categoria não tem um filtro só.');
        $this->assertArrayNotHasKey('id', $cats['Transporte'], 'O id é interno; o link é o contrato.');

        // E o link abre as Movimentações filtradas.
        $this->actingAs($user)->get($cats['Transporte']['url'])->assertOk()->assertSee('540,87');
    }
}
