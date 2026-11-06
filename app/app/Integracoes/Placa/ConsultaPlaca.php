<?php

namespace App\Integracoes\Placa;

/** Consulta de dados do veículo pela placa (base oficial via provedor contratado). */
interface ConsultaPlaca
{
    /** @return array{marca:?string, modelo:?string, ano:?int, cor:?string}|null null = não encontrado ou serviço indisponível (nunca bloqueia a entrada). */
    public function consultar(string $placa): ?array;
}
