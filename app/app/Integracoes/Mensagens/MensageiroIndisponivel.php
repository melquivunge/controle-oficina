<?php

namespace App\Integracoes\Mensagens;

use RuntimeException;

class MensageiroIndisponivel implements Mensageiro
{
    public function canal(): string
    {
        return 'indisponivel';
    }

    public function enviar(string $destino, string $texto): string
    {
        throw new RuntimeException('Canal oficial de mensagens não configurado. Escolha e contrate o provedor.');
    }
}
