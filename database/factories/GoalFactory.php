<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fábrica de metas para os testes.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Goal>
 */
class GoalFactory extends Factory
{
    /**
     * Estado padrão: meta coerente com o schema (cor hexadecimal da paleta,
     * valor-alvo decimal positivo, data-alvo no futuro).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'made_by_user_id' => null,
            'name' => fake()->randomElement(['Viagem', 'Reserva de emergência', 'Notebook novo', 'Carro']) . ' ' . fake()->unique()->numberBetween(1, 9999),
            'emoji' => fake()->randomElement(['🎯', '✈️', '🏠', '💻', '🚗', '🐷']),
            'color' => fake()->randomElement(['#0F6B47', '#1FA06E', '#59C497', '#18B6BE', '#F0A93B']),
            'target_amount' => fake()->randomFloat(2, 100, 20000),
            'target_date' => fake()->optional()->dateTimeBetween('now', '+5 years')?->format('Y-m-d'),
        ];
    }
}
