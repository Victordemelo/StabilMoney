<?php

namespace Database\Factories;

use App\Models\FixedBill;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fábrica de contas fixas mensais para os testes.
 *
 * O padrão é uma conta ATIVA que começou no primeiro dia do mês corrente e
 * vence no dia 10 — assim, num teste que não viaja no tempo, a competência do
 * mês já existe e a primeira ocorrência nunca é descartada por nascer antes do
 * `starts_on` (ver FixedBillService::occurrences).
 *
 * @extends Factory<FixedBill>
 */
class FixedBillFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'made_by_user_id' => null,
            'name' => fake()->randomElement(['Condomínio', 'Aluguel', 'Internet', 'Escola', 'Parcela do carro']),
            'amount' => fake()->randomFloat(2, 50, 3000),
            'due_day' => fake()->numberBetween(1, 28),
            'account_id' => null,
            'category_id' => null,
            'starts_on' => now()->startOfMonth()->toDateString(),
            'ends_on' => null,
            'active' => true,
        ];
    }

    /** Conta fixa desativada (o titular a "excluiu" e ela tinha pagamentos). */
    public function inativa(): static
    {
        return $this->state(fn () => ['active' => false]);
    }

    /** Vencimento num dia específico do mês (1..31). */
    public function venceDia(int $dia): static
    {
        return $this->state(fn () => ['due_day' => $dia]);
    }
}
