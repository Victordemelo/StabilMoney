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
 * Cria o usuário principal de desenvolvimento com categorias padrão e uma
 * conta "Carteira" para usar o app sem passar pelo fluxo de cadastro.
 *
 * As credenciais vêm do .env (NUNCA commitar senha real no código):
 *   SEED_USER_NAME, SEED_USER_EMAIL, SEED_USER_PASSWORD
 *
 * Em qualquer ambiente que não seja `local`, ele não faz nada (proteção
 * contra rodar `migrate --seed` em produção por engano).
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

        $email = env('SEED_USER_EMAIL', 'victor@stabilmoney.test');

        // Usuário principal de dev. Se já existir, atualiza nome/senha para
        // os valores do .env (garante que o login do seed sempre funciona).
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => env('SEED_USER_NAME', 'Victor'),
                'password' => Hash::make(env('SEED_USER_PASSWORD', 'password')),
                'is_admin' => true,
                // O usuário de dev não passa pelo /register, então ninguém marca
                // isto por ele — e no dia em que uma rota ganhar `verified`, ele
                // ficaria trancado fora do próprio ambiente de desenvolvimento.
                'email_verified_at' => now(),
            ],
        );

        // Mesmas categorias padrão que um usuário novo recebe ao se cadastrar.
        DefaultCategories::seedFor($user);

        // Conta inicial (Conta Corrente com banco — tipos novos do modelo).
        Account::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'Conta Corrente'],
            ['type' => 'checking', 'bank' => 'nubank', 'initial_balance' => 0],
        );
    }
}
