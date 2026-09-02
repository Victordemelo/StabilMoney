<?php

namespace Database\Factories;

use App\Models\Admin;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Admin>
 */
class AdminFactory extends Factory
{
    protected $model = Admin::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('senha-de-teste-bem-comprida'),
        ];
    }

    /** Admin que já configurou e confirmou o autenticador. */
    public function comDoisFatores(): static
    {
        return $this->state(fn () => [
            'two_factor_secret' => Totp::gerarSegredo(),
            'two_factor_recovery_codes' => RecoveryCodes::gerar(),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
