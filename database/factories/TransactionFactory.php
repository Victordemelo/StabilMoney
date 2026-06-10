<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fábrica de transações para os testes.
 *
 * Atenção: por padrão user_id e account_id criam registros independentes.
 * Nos testes, passe o mesmo usuário para os dois lados, ex.:
 * Transaction::factory()->for($user)->for($account)->create();
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Estado padrão: valor positivo (o sinal vem do type), data recente,
     * sem categoria (nullable no schema).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'account_id' => Account::factory(),
            'category_id' => null,
            'type' => fake()->randomElement(['income', 'expense']),
            'amount' => fake()->randomFloat(2, 1, 2000),
            'description' => fake()->sentence(3),
            'date' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
        ];
    }

    /** Receita. */
    public function income(): static
    {
        return $this->state(fn (array $attributes) => ['type' => 'income']);
    }

    /** Despesa. */
    public function expense(): static
    {
        return $this->state(fn (array $attributes) => ['type' => 'expense']);
    }
}
