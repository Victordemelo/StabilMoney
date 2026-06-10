<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Usuário padrão (id 1) usado enquanto não há login (ver CLAUDE.md).
        $user = User::firstOrCreate(
            ['email' => 'victor@stabilmoney.test'],
            [
                'name' => 'Victor',
                'password' => Hash::make('password'),
                'is_admin' => true,
            ],
        );

        // Conta inicial
        Account::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'Carteira'],
            ['type' => 'wallet', 'initial_balance' => 0, 'icon' => '💵'],
        );

        // Categorias padrão de despesa
        $expenses = [
            ['Alimentação', '🍽️'],
            ['Transporte', '🚗'],
            ['Moradia', '🏠'],
            ['Saúde', '💊'],
            ['Lazer', '🎮'],
            ['Educação', '📚'],
            ['Compras', '🛒'],
            ['Contas', '🧾'],
            ['Outros', '📦'],
        ];
        foreach ($expenses as [$name, $icon]) {
            Category::firstOrCreate(
                ['user_id' => $user->id, 'name' => $name, 'type' => 'expense'],
                ['icon' => $icon],
            );
        }

        // Categorias padrão de receita
        $incomes = [
            ['Salário', '💰'],
            ['Freelance', '💼'],
            ['Investimentos', '📈'],
            ['Presente', '🎁'],
            ['Outros', '📦'],
        ];
        foreach ($incomes as [$name, $icon]) {
            Category::firstOrCreate(
                ['user_id' => $user->id, 'name' => $name, 'type' => 'income'],
                ['icon' => $icon],
            );
        }
    }
}
