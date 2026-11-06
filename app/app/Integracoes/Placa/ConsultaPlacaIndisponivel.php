<?php

namespace App\Integracoes\Placa;

/** Produção até a escolha e contratação do provedor: não consulta nada; a recepção digita marca/modelo/ano/cor. */
class ConsultaPlacaIndisponivel implements ConsultaPlaca
{
    public function consultar(string $placa): ?array
    {
        return null;
    }
}
