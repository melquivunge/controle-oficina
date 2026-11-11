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
        throw new RuntimeException('Nenhum número de WhatsApp ativo para esta unidade. Ative ou cadastre o número em Configurações › Números de WhatsApp e clique em Reenviar.');
    }
}
