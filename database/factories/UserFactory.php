<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Já aceitou a versão atual dos documentos: sem isso, todo teste que entra no
            // app cairia na tela de aceite (ExigeAceiteDaPoliticaAtual).
            'terms_accepted_at' => now(),
            'terms_version' => config('legal.version'),
        ];
    }

    /**
     * Aceitou uma versão antiga dos documentos (ou nenhuma, com `null`).
     */
    public function aceitouAVersao(?string $versao): static
    {
        return $this->state(fn (array $attributes) => [
            'terms_version' => $versao,
            'terms_accepted_at' => $versao === null ? null : now()->subMonth(),
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
