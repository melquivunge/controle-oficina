<?php

namespace App\Integracoes\Placa;

/** Staging/demo/testes: dados FICTÍCIOS e estáveis por placa (nenhuma consulta real). Placa terminada em 0 = "não encontrada". */
class SimuladorPlaca implements ConsultaPlaca
{
    private const CARROS = [['Volkswagen', 'Gol 1.0'], ['Chevrolet', 'Onix 1.0'], ['Fiat', 'Strada 1.4'], ['Toyota', 'Corolla 2.0'], ['Hyundai', 'HB20 1.6'], ['Honda', 'Civic 2.0']];

    private const CORES = ['Prata', 'Branca', 'Preta', 'Cinza', 'Vermelha'];

    public function consultar(string $placa): ?array
    {
        if (str_ends_with($placa, '0')) {
            return null;
        }
        $n = crc32($placa);
        [$marca, $modelo] = self::CARROS[$n % count(self::CARROS)];

        return ['marca' => $marca, 'modelo' => $modelo, 'ano' => 2010 + $n % 15, 'cor' => self::CORES[$n % count(self::CORES)]];
    }
}
