<?php

namespace App\Integracoes\Mensagens;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/** SIMULADO: nenhuma mensagem sai do sistema. Destino terminado em 0000 simula indisponibilidade do provedor. */
class SimuladorMensagens implements Mensageiro
{
    public function canal(): string
    {
        return 'simulado';
    }

    public function enviar(string $destino, string $texto): string
    {
        if (str_ends_with($destino, '0000')) {
            throw new RuntimeException('Simulação: provedor de mensagens indisponível.');
        }
        Log::info('mensagem simulada (não enviada a ninguém)', ['destino_final' => substr($destino, -4), 'tamanho' => mb_strlen($texto)]);

        return 'SIM-MSG-'.Str::upper(Str::random(12));
    }
}
