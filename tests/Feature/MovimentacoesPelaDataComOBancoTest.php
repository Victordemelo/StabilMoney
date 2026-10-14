<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Movimentações pela DATA, a mais recente primeiro, com o título de cada dia (13/10/2026 — pedido
 * do Victor: "quero na ordem das movimentações, as mais recentes primeiro"), e o ícone de cada
 * linha com o ícone do banco no canto ("tá fácil de identificar o item?").
 */
class MovimentacoesPelaDataComOBancoTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_ordem_e_a_data_nunca_o_valor_e_cada_dia_tem_titulo(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-13 10:00:00'));
        $user = User::factory()->create();
        $conta = Account::factory()->for($user)->create(['type' => 'checking', 'bank' => 'itau', 'initial_balance' => 10000]);

        // Valores na ordem inversa das datas: se a lista seguisse o valor, a ordem viraria.
        foreach ([['2026-10-01', 900, 'Antiga e cara'], ['2026-10-12', 50, 'Ontem barata'], ['2026-10-13', 10, 'Hoje baratinha'], ['2025-12-20', 5000, 'Ano passado']] as [$data, $valor, $nome]) {
            Transaction::factory()->for($user)->expense()->create(['account_id' => $conta->id, 'amount' => $valor, 'date' => $data, 'description' => $nome]);
        }
        // Lançada DEPOIS, com data antiga: vai para o lugar da data, não para o topo.
        Transaction::factory()->for($user)->expense()->create(['account_id' => $conta->id, 'amount' => 3000, 'date' => '2026-10-01', 'description' => 'Lançada agora, data antiga']);

        $html = $this->actingAs($user)->get(route('transactions.index'))->assertOk()->getContent();

        $posicoes = array_map(fn ($nome) => strpos($html, $nome), ['Hoje baratinha', 'Ontem barata', 'Lançada agora, data antiga', 'Antiga e cara', 'Ano passado']);
        $ordenadas = $posicoes;
        sort($ordenadas);
        $this->assertSame($ordenadas, $posicoes, 'A lista segue a data, a mais recente primeiro.');

        $this->assertSame(4, substr_count($html, 'class="tx-dia"'), 'Um título por dia.');
        $this->assertMatchesRegularExpression('#class="tx-dia">Hoje<#', $html);
        $this->assertMatchesRegularExpression('#class="tx-dia">Ontem<#', $html);
        $this->assertMatchesRegularExpression('#class="tx-dia">1 de outubro<#', $html);
        $this->assertMatchesRegularExpression('#class="tx-dia">20 de dezembro de 2025<#', $html);
    }

    public function test_o_icone_traz_o_banco_da_conta(): void
    {
        $user = User::factory()->create();
        $conta = Account::factory()->for($user)->create(['type' => 'checking', 'bank' => 'nubank', 'initial_balance' => 100]);
        $semBanco = Account::factory()->for($user)->create(['type' => 'savings', 'bank' => null, 'initial_balance' => 100]);
        $mercado = Category::factory()->expense()->for($user)->create(['name' => 'Mercado', 'icon' => '🛒']);
        Transaction::factory()->for($user)->expense()->create(['account_id' => $conta->id, 'category_id' => $mercado->id, 'amount' => 20, 'date' => now()->toDateString()]);
        Transaction::factory()->for($user)->income()->create(['account_id' => $semBanco->id, 'amount' => 20, 'date' => now()->toDateString()]);

        foreach ([route('transactions.index'), route('dashboard')] as $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'class="tx-banco"'), "Só a linha com banco ganha o ícone do banco ({$url}).");
            $this->assertStringContainsString('assets/banks/icones/nubank.png', $html);
        }
    }
}
