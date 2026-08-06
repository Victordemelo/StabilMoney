<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fábrica de movimentações de investimento (aporte/resgate) para os testes.
 *
 * Atenção: por padrão investment_id e account_id criam registros independentes.
 * Nos testes, passe o mesmo investimento/conta da família, ex.:
 * InvestmentContribution::factory()->for($investment)->for($account)->create();
 *
 * @extends Factory<InvestmentContribution>
 */
class InvestmentContributionFactory extends Factory
{
    /**
     * Estado padrão: aporte com valor positivo (o sentido vem do type).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'investment_id' => Investment::factory(),
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
