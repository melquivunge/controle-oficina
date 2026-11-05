<?php

namespace App\Integracoes\WhatsApp;

use App\Models\WhatsappNumero;

/** Seleção do adaptador. Em simulação/demonstração SEMPRE o simulador: nada é enviado nem recebido de verdade. */
class Provedores
{
    public static function simulacaoAtiva(): bool
    {
        return config('oficina.integracoes') === 'simulado' || config('database.default') === 'demo';
    }

    /** "Simular mensagem recebida/resposta pelo celular": somente simulação e fora de produção. */
    public static function podeSimular(): bool
    {
        return self::simulacaoAtiva() && ! app()->isProduction();
    }

    public static function paraNumero(WhatsappNumero $numero): ProvedorWhatsApp
    {
        if (self::simulacaoAtiva()) {
            return new SimuladorWhatsApp;
        }

        return $numero->provedor === 'meta'
            ? new MetaCloudApi
            : new WhatsAppIndisponivel('Número cadastrado como simulado: em modo real ele não envia nem baixa nada. Configure a API oficial.');
    }

    /** Adaptador que atende o webhook; null = rota inexistente neste modo (404). */
    public static function paraWebhook(string $provedor): ?ProvedorWhatsApp
    {
        return match (true) {
            $provedor === 'simulado' && self::simulacaoAtiva() => new SimuladorWhatsApp,
            $provedor === 'meta' && ! self::simulacaoAtiva() => new MetaCloudApi,
            default => null,
        };
    }
}
