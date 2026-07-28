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
            'type' => 'bank', // caixa: cartão de crédito ficaria fora do saldo/patrimônio
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

    public function test_page_title_uses_section(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('<title>Visão geral · StabilMoney</title>', false);
    }
}
