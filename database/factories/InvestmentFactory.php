<?php

namespace Database\Factories;

use App\Models\Investment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fábrica de investimentos para os testes.
 *
 * @extends Factory<Investment>
 */
class InvestmentFactory extends Factory
{
    /**
     * Estado padrão: investimento de renda fixa indexado ao CDI (coerente com
     * o schema; classe/indexador válidos, taxa positiva).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'made_by_user_id' => null,
            'name' => fake()->randomElement(['CDB Liquidez', 'Tesouro Selic', 'Fundo Multimercado', 'Bitcoin', 'Ações BOVA11']).' '.fake()->unique()->numberBetween(1, 9999),
            'classe' => 'renda_fixa',
            'indexador' => 'CDI',
            'taxa' => fake()->randomFloat(2, 90, 120),
        ];
    }

    /** Renda variável (sem indexador). */
    public function rendaVariavel(): static
    {
        return $this->state(fn (array $attributes) => [
            'classe' => 'renda_variavel',
            'indexador' => null,
            'taxa' => null,
        ]);
    }

    /** Cripto (sem indexador). */
    public function cripto(): static
    {
        return $this->state(fn (array $attributes) => [
            'classe' => 'cripto',
            'indexador' => null,
            'taxa' => null,
        ]);
    }
}
