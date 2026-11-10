<?php

namespace App\Integracoes\Mensagens;

use App\Integracoes\WhatsApp\DropeApi;
use App\Integracoes\WhatsApp\Provedores;
use App\Models\WhatsappNumero;
use App\Support\Normaliza;

/**
 * Mensagens ao cliente (entrada, orçamento, cobrança, conclusão, OS) pelo número Drope: texto com link do portal.
 */
class MensageiroDrope implements Mensageiro
{
    public function __construct(private WhatsappNumero $numero) {}

    /** Número Drope ativo da unidade, senão o central (triagem). null = sem Drope ou em simulação. */
    public static function paraUnidade(?int $unidadeId): ?self
    {
        if (Provedores::simulacaoAtiva()) {
            return null;
        }
        $n = WhatsappNumero::where('provedor', 'drope')->where('ativo', true)
            ->where(fn ($q) => $q->where('unidade_id', $unidadeId)->orWhereNull('unidade_id'))
            ->orderByRaw('unidade_id IS NULL')->get()->first(fn ($n) => $n->credencial('token') !== null);

        return $n ? new self($n) : null;
    }

    public function canal(): string
    {
        return 'whatsapp_drope';
    }

    public function enviar(string $destino, string $texto): string
    {
        $telefone = Normaliza::telefone($destino);
        if (! preg_match('/^[0-9]{10,11}$/', $telefone)) {
            throw new \RuntimeException('Telefone do cliente inválido para WhatsApp: informe DDD + número.');
        }

        return (new DropeApi)->enviarTexto($this->numero, '55'.$telefone, $texto);
    }
}
