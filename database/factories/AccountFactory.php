<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fábrica de contas/carteiras para os testes.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Account>
 */
class AccountFactory extends Factory
{
    /**
     * Estado padrão: conta coerente com o schema (tipo válido, saldo decimal,
     * cor hexadecimal da paleta do design).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Carteira', 'Banco Azul', 'Banco Roxo', 'Poupança', 'Cartão Principal']) . ' ' . fake()->unique()->numberBetween(1, 9999),
            'type' => fake()->randomElement(['wallet', 'bank', 'credit_card', 'savings', 'investment', 'other']),
            'initial_balance' => fake()->randomFloat(2, 0, 5000),
            'color' => fake()->randomElement(['#0F6B47', '#1FA06E', '#59C497', '#18B6BE', '#F0A93B']),
            'icon' => fake()->randomElement(['💳', '🏦', '👛', '🐷']),
        ];
    }
}
