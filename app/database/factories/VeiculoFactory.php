<?php

namespace Database\Factories;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Veiculo> */
class VeiculoFactory extends Factory
{
    public function definition(): array
    {
        return ['cliente_id' => Cliente::factory(), 'placa' => strtoupper(fake()->unique()->bothify('???#?##')), 'modelo' => 'Carro de teste'];
    }
}
