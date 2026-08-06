<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fábrica de contas/carteiras para os testes.
 *
 * @extends Factory<Account>
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
        //
        // O saldo inicial é FIXO e folgado de propósito: desde que existe limite
        // de gasto (SpendingGuard), um saldo sorteado entre 0 e 5.000 fazia
        // qualquer teste que lança despesa falhar de vez em quando, conforme o
        // sorteio. Quem testa saldo/limite informa `initial_balance` explícito.
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Conta Corrente', 'Banco Azul', 'Banco Roxo', 'Poupança']).' '.fake()->unique()->numberBetween(1, 9999),
            'type' => 'checking',
            'bank' => fake()->randomElement(array_keys(Account::BANKS)),
            'initial_balance' => 100000,
        ];
    }

    /** Conta corrente com cheque especial (o limite é só de `checking`). */
    public function overdraft(float $limite = 2500): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'checking',
            'overdraft_limit' => $limite,
        ]);
    }

    /** Cartão de débito espelhando uma conta corrente. */
    public function debitCard(?int $checkingId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'debit_card',
            'initial_balance' => null,
            'checking_account_id' => $checkingId,
        ]);
    }

    /**
     * Pix registrado numa conta. A chave vive em UMA conta só, então só uma das
     * duas colunas é preenchida (a outra fica nula, valendo 0 no espelho).
     */
    public function pix(?int $contaId = null, string $tipoDaConta = 'checking'): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'pix',
            'initial_balance' => null,
            'checking_account_id' => $tipoDaConta === 'checking' ? $contaId : null,
            'savings_account_id' => $tipoDaConta === 'savings' ? $contaId : null,
        ]);
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
