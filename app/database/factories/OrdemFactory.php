<?php

namespace Database\Factories;

use App\Enums\StatusOrdem;
use App\Models\Unidade;
use App\Models\User;
use App\Models\Veiculo;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Ordem> */
class OrdemFactory extends Factory
{
    private static int $seq = 0;

    public function definition(): array
    {
        $n = ++self::$seq;

        return [
            'unidade_id' => Unidade::factory(),
            'sequencia' => $n,
            'numero' => 'T-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT).'-'.fake()->unique()->numerify('####'),
            'veiculo_id' => Veiculo::factory(),
            'cliente_id' => fn (array $a) => Veiculo::find($a['veiculo_id'])->cliente_id,
            'status' => StatusOrdem::Entrada,
            'motivo' => 'Barulho na suspensão',
            'entrada_em' => now(),
            'criado_por' => fn (array $a) => User::factory()->create(['unidade_id' => $a['unidade_id']])->id,
        ];
    }
}
