<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shell v2 (handoff de design jun/2026): sidebar com menu novo, card
 * "Patrimônio total" com dados reais, card Dependentes (estado vazio),
 * popover do perfil e botão "Lançar" na topbar — presentes em todas as
 * páginas autenticadas.
 */
class ShellV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_shell_v2_with_patrimonio_and_popover(): void
    {
        $user = User::factory()->create(['name' => 'Maria Silva']);
        // type=bank: cartão de crédito não entra no patrimônio, então fixamos
        // uma conta que é caixa para o saldo bater 1.234,56.
        Account::factory()->for($user)->create(['type' => 'bank', 'initial_balance' => 1234.56]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();

        // Menu novo da sidebar (grupos Menu + Preferências)
        $response->assertSee('Visão geral');
        $response->assertSee('Pagar despesas');
        $response->assertSee('Metas');
        $response->assertSee('Investimentos');
        $response->assertSee('Métodos de Pagamento');
        $response->assertSee('Categorias');

        // Itens removidos no design v2 não aparecem mais
        $response->assertDontSee('Relatórios');
        $response->assertDontSee('Ajuda');

        // Card "Patrimônio total" com o saldo real (inteiro + centavos separados)
        $response->assertSee('Patrimônio total');
        $response->assertSee('R$ 1.234');
        // Modelo "cofrinho": sublines de disponível e guardado em metas
        // (sem aportes => disponível = saldo cru, guardado = 0).
        $response->assertSee('Disponível: R$ 1.234,56');
        $response->assertSee('Guardado em metas: R$ 0,00');

        // Sem transações não há base de variação => .sb-foot oculto
        $response->assertDontSee('nos últimos 30 dias');

        // Card Dependentes em estado vazio, linkando para a rota
        $response->assertSee('Dependentes');
        $response->assertSee('Nenhum dependente');

        // Popover do perfil (markup presente; abre via shell.js)
        $response->assertSee('id="profilePop"', false);
        $response->assertSee('id="profileBtn"', false);
        $response->assertSee('Meu perfil');
        $response->assertSee('Configurações');
        $response->assertSee('Sair');
        $response->assertSee('Maria Silva');

        // Botão "Lançar" da topbar leva ao form de nova transação
        $response->assertSee('Lançar');
        $response->assertSee(route('transactions.create'));
    }

    public function test_launch_modal_is_present_on_authenticated_pages(): void
    {
        $user = User::factory()->create();
        Account::factory()->for($user)->create(['name' => 'Conta Teste']);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('id="launchModal"', false);
        $response->assertSee('Nova transação');
        $response->assertSee('data-launch-form', false);
        $response->assertSee('action="' . route('transactions.store') . '"', false);
        // A conta da família aparece no select do modal.
        $response->assertSee('Conta Teste');
    }

    public function test_patrimonio_card_shows_variation_when_there_is_a_base(): void
    {
        $user = User::factory()->create();
        // type=bank: conta que é caixa (cartão de crédito ficaria fora do patrimônio).
        $account = Account::factory()->for($user)->create(['type' => 'bank', 'initial_balance' => 1000]);

        // Receita de hoje: saldo 1.100 vs base de 30 dias atrás 1.000 => +10,0%
        Transaction::factory()->for($user)->for($account)->income()->create([
            'amount' => 100,
            'date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('nos últimos 30 dias');
        $response->assertSee('10,0%');
    }

    public function test_shell_v2_is_present_on_other_authenticated_pages(): void
    {
        $user = User::factory()->create();
        Account::factory()->for($user)->create(['type' => 'bank', 'initial_balance' => 50]);

        foreach (['/transactions', '/categories', '/faturas'] as $uri) {
            $response = $this->actingAs($user)->get($uri);

            $response->assertOk();
            $response->assertSee('Patrimônio total');
            $response->assertSee('id="profilePop"', false);
            $response->assertSee('Lançar');
        }
    }
}
