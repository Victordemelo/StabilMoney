<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dashboard: empty states para usuário novo e payload JSON
 * (#sm-dashboard-data) com dados reais para usuário com transações.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_sees_dashboard_with_empty_states(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();

        // Empty states de cada card
        $response->assertSee('Sem movimentações ainda');
        $response->assertSee('Nenhuma transação ainda');
        $response->assertSee('Nenhuma conta ainda');
        $response->assertSee('Sem gastos este mês');

        // Contrato com o JS: JSON embutido com hasData false
        $response->assertSee('id="sm-dashboard-data"', false);
        $response->assertSee('"hasData":false', false);
    }

    public function test_user_with_transactions_sees_dashboard_with_data(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create([
            'name' => 'Conta Corrente Principal',
            'type' => 'checking', // caixa: cartão de crédito ficaria fora do saldo/patrimônio
            'initial_balance' => 1000,
        ]);
        $category = Category::factory()->expense()->for($user)->create(['name' => 'Alimentação']);

        Transaction::factory()->for($user)->for($account)->income()->create([
            'amount' => 250.00,
            'description' => 'Salário do mês',
            'date' => now()->toDateString(),
        ]);
        Transaction::factory()->for($user)->for($account)->expense()->create([
            'category_id' => $category->id,
            'amount' => 80.50,
            'description' => 'Mercado da semana',
            'date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();

        // Payload do contrato com dados
        $response->assertSee('id="sm-dashboard-data"', false);
        $response->assertSee('"hasData":true', false);

        // Transações recentes server-rendered
        $response->assertSee('Salário do mês');
        $response->assertSee('Mercado da semana');

        // Conta aparece no card "Meu cartão"
        $response->assertSee('Conta Corrente Principal');

        // Sem empty state de movimentações
        $response->assertDontSee('Sem movimentações ainda');
        $response->assertDontSee('Nenhuma conta ainda');
    }

    public function test_negative_balance_is_shown_in_red(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create([
            'type' => 'checking',
            'initial_balance' => 100,
        ]);
        // Despesa maior que o saldo: saldo total fica negativo (-900).
        Transaction::factory()->for($user)->for($account)->expense()->create([
            'amount' => 1000,
            'date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        // Stat card "Saldo total" marcado como negativo (CSS pinta de vermelho)
        $response->assertSee('class="value neg"', false);
        // O card "Patrimônio total" da sidebar também
        $response->assertSee('class="sb-value neg"', false);
    }

    public function test_positive_balance_is_not_marked_negative(): void
    {
        $user = User::factory()->create();
        Account::factory()->for($user)->create([
            'type' => 'checking',
            'initial_balance' => 500,
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertDontSee('class="value neg"', false);
        $response->assertDontSee('class="sb-value neg"', false);
    }

    public function test_dashboard_opens_on_week_period(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 0]);

        // Despesa de hoje (entra na semana) e uma de 20 dias atrás (só no mês).
        Transaction::factory()->for($user)->for($account)->expense()->create([
            'amount' => 100, 'date' => now()->toDateString(),
        ]);
        Transaction::factory()->for($user)->for($account)->expense()->create([
            'amount' => 900, 'date' => now()->subDays(20)->toDateString(),
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        // O botão "Semana" abre marcado (o JS desenha o período do botão ativo)
        $response->assertSee('data-p="semana" class="active"', false);
        $response->assertDontSee('data-p="mes" class="active"', false);
        // E os stat cards já vêm com os números DA SEMANA (100), não do mês (1.000)
        $response->assertSee('100,00');
    }

    public function test_locked_categories_always_appear_in_breakdown(): void
    {
        $user = User::factory()->create();
        \App\Support\DefaultCategories::seedFor($user);
        $account = Account::factory()->for($user)->create(['type' => 'checking']);

        // Um gasto só em Alimentação — as outras 4 fixas ficam sem gasto no mês.
        $alimentacao = Category::where('user_id', $user->id)->where('name', 'Alimentação')->firstOrFail();
        Transaction::factory()->for($user)->for($account)->expense()->create([
            'category_id' => $alimentacao->id, 'amount' => 250, 'date' => now()->toDateString(),
        ]);

        $cats = collect(app(\App\Services\DashboardService::class)->build($user->id)['cats']);

        // As 5 fixas aparecem, mesmo as zeradas; a com gasto vem com valor.
        foreach (['Alimentação', 'Moradia', 'Saúde', 'Transporte', 'Contas'] as $nome) {
            $this->assertTrue($cats->contains('name', $nome), "categoria fixa {$nome} deveria estar na lista");
        }
        $this->assertSame(250.0, $cats->firstWhere('name', 'Alimentação')['value']);
        $this->assertSame(0.0, $cats->firstWhere('name', 'Moradia')['value']);
        // Categoria não fixa e sem gasto NÃO entra
        $this->assertFalse($cats->contains('name', 'Lazer'));
    }

    public function test_breakdown_is_empty_without_expenses(): void
    {
        $user = User::factory()->create();
        \App\Support\DefaultCategories::seedFor($user);

        // Sem despesa no mês, o card mostra o estado vazio (não um donut zerado)
        $this->assertSame([], app(\App\Services\DashboardService::class)->build($user->id)['cats']);
    }

    public function test_saldo_stat_discounts_goals_and_investments(): void
    {
        $user = User::factory()->create();
        $conta = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 1000]);

        // Guardado numa meta (modelo cofrinho: o dinheiro segue na conta, mas
        // não está disponível para gastar).
        $meta = \App\Models\Goal::factory()->for($user)->create();
        \App\Models\GoalContribution::factory()->for($meta)->for($conta)->create([
            'type' => 'aporte', 'amount' => 300, 'date' => now()->toDateString(),
        ]);

        $dashboard = app(\App\Services\DashboardService::class)->build($user->id);

        // O card do topo mostra o DISPONÍVEL (1000 − 300), não o saldo cru.
        $this->assertSame(700.0, $dashboard['initialStats']['saldo']);
        // O saldo bruto das contas continua 1000 (o dinheiro não sumiu).
        $this->assertSame(1000.0, $dashboard['totalBalance']);
    }

    public function test_card_panel_lists_every_credit_card_with_spent_and_available(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-06-18');

        $user = User::factory()->create();
        $nubank = Account::factory()->for($user)->create([
            'type' => 'credit_card', 'name' => 'Nubank', 'bank' => 'nubank',
            'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 20,
        ]);
        $itau = Account::factory()->for($user)->create([
            'type' => 'credit_card', 'name' => 'Itaú', 'bank' => 'itau',
            'credit_limit' => 2000, 'closing_day' => 10, 'due_day' => 20,
        ]);
        Transaction::factory()->for($user)->for($nubank)->expense()->create(['amount' => 300, 'date' => '2026-06-18']);
        Transaction::factory()->for($user)->for($itau)->expense()->create(['amount' => 450, 'date' => '2026-06-18']);

        $d = app(\App\Services\DashboardService::class)->build($user->id);

        // Os DOIS cartões aparecem (antes o card mostrava só a primeira conta)
        $this->assertCount(2, $d['cartoes']);
        $nu = collect($d['cartoes'])->firstWhere('nome', 'Nubank');
        $it = collect($d['cartoes'])->firstWhere('nome', 'Itaú');

        // Cada um com o que gastou e o limite que ainda tem
        $this->assertSame(300.0, $nu['gasto']);
        $this->assertSame(4700.0, $nu['disponivel']);
        $this->assertSame(450.0, $it['gasto']);
        $this->assertSame(1550.0, $it['disponivel']);
        $this->assertSame(23, $it['usadoPct']); // 450 de 2.000

        // E os totais da carteira
        $this->assertSame(750.0, $d['cartoesTotais']['gasto']);
        $this->assertSame(6250.0, $d['cartoesTotais']['disponivel']);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_page_title_uses_section(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('<title>Visão geral · StabilMoney</title>', false);
    }
}
