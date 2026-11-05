<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Unidade> */
class UnidadeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'codigo' => strtoupper(fake()->unique()->bothify('U??#')),
            'nome' => 'Oficina '.fake()->unique()->city(),
            'ativa' => true,
        ];
    }
}
