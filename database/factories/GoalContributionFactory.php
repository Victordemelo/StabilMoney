<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalContribution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fábrica de movimentações de meta (aporte/resgate) para os testes.
 *
 * Atenção: por padrão goal_id e account_id criam registros independentes.
 * Nos testes, passe a mesma meta/conta da família, ex.:
 * GoalContribution::factory()->for($goal)->for($account)->create();
 *
 * @extends Factory<GoalContribution>
 */
class GoalContributionFactory extends Factory
{
    /**
     * Estado padrão: aporte com valor positivo (o sentido vem do type).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'goal_id' => Goal::factory(),
            'account_id' => Account::factory(),
            'made_by_user_id' => null,
            'type' => 'aporte',
            'amount' => fake()->randomFloat(2, 10, 1000),
            'date' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
        ];
    }

    /** Aporte (reserva dinheiro). */
    public function aporte(): static
    {
        return $this->state(fn (array $attributes) => ['type' => 'aporte']);
    }

    /** Resgate (devolve à conta). */
    public function resgate(): static
    {
        return $this->state(fn (array $attributes) => ['type' => 'resgate']);
    }
}
