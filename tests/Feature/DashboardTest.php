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
}
