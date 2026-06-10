<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
use App\Support\DefaultCategories;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder SOMENTE PARA DESENVOLVIMENTO LOCAL.
 *
 * Cria um usuário demo com categorias padrão e uma conta "Carteira" para
 * testar o app sem precisar passar pelo fluxo de cadastro. Em qualquer
 * ambiente que não seja `local`, ele não faz nada (proteção contra rodar
 * `migrate --seed` em produção por engano).
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Trava de segurança: dados demo só existem em ambiente local.
        if (! app()->environment('local')) {
            return;
        }

        // Usuário demo (senha vem de SEED_USER_PASSWORD no .env; padrão "password").
        $user = User::firstOrCreate(
            ['email' => 'victor@stabilmoney.test'],
            [
                'name' => 'Victor',
                'password' => Hash::make(env('SEED_USER_PASSWORD', 'password')),
                'is_admin' => true,
            ],
        );

        // Mesmas categorias padrão que um usuário novo recebe ao se cadastrar.
        DefaultCategories::seedFor($user);

        // Conta inicial
        Account::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'Carteira'],
            ['type' => 'wallet', 'initial_balance' => 0, 'icon' => '💵'],
        );
    }
}
