<?php

namespace Database\Factories;

use App\Models\Account;
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
        // Padrão: Conta Corrente (tem saldo próprio, comportamento previsível nos
        // testes) com um banco. Cartões usam os states abaixo.
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Conta Corrente', 'Banco Azul', 'Banco Roxo', 'Poupança']) . ' ' . fake()->unique()->numberBetween(1, 9999),
            'type' => 'checking',
            'bank' => fake()->randomElement(array_keys(Account::BANKS)),
            'initial_balance' => fake()->randomFloat(2, 0, 5000),
        ];
    }

    /** Cartão de crédito com limite e dias de fechamento/vencimento. */
    public function creditCard(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'credit_card',
            'initial_balance' => null,
            'credit_limit' => 5000,
            'closing_day' => 10,
            'due_day' => 20,
        ]);
    }
}
