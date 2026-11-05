<?php

namespace App\Integracoes\Mensagens;

/** Canal oficial de mensagens. Implementações: SimuladorMensagens. Real depende de provedor contratado. */
interface Mensageiro
{
    public function canal(): string;

    /** Retorna o id da mensagem no provedor. Lança exceção em falha (aceito ≠ entregue). */
    public function enviar(string $destino, string $texto): string;
}
