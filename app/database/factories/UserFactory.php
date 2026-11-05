<?php

namespace Database\Factories;

use App\Models\User;
use App\Enums\Papel;
use App\Models\Unidade;
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
            'papel' => Papel::Recepcao,
            'unidade_id' => Unidade::factory(),
            'ativo' => true,
        ];
    }

    public function papel(Papel $papel, ?Unidade $unidade = null): static
    {
        return $this->state(fn () => [
            'papel' => $papel,
            'unidade_id' => $papel === Papel::Proprietario ? null : ($unidade?->id ?? Unidade::factory()),
        ]);
    }

    public function proprietario(): static
    {
        return $this->papel(Papel::Proprietario);
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
