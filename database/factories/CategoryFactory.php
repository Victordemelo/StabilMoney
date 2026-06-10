<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fábrica de categorias para os testes.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Estado padrão: categoria com tipo válido (income|expense), cor da
     * paleta do design e ícone emoji — igual ao que o app cria.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'type' => fake()->randomElement(['income', 'expense']),
            'color' => fake()->randomElement(['#0F6B47', '#1FA06E', '#59C497', '#18B6BE', '#F0A93B']),
            'icon' => fake()->randomElement(['🍽️', '🚗', '💰', '🎁', '📦']),
        ];
    }

    /** Categoria de receita. */
    public function income(): static
    {
        return $this->state(fn (array $attributes) => ['type' => 'income']);
    }

    /** Categoria de despesa. */
    public function expense(): static
    {
        return $this->state(fn (array $attributes) => ['type' => 'expense']);
    }
}
