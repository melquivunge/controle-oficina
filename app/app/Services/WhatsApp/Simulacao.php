<?php

namespace App\Services\WhatsApp;

use App\Integracoes\WhatsApp\Provedores;
use App\Integracoes\WhatsApp\SimuladorWhatsApp;
use App\Models\WhatsappConversa;
use App\Models\WhatsappNumero;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * SOMENTE SIMULAÇÃO, fora de produção: injeta eventos do simulador pelo mesmo caminho do webhook
 * (interpretar → integration_events → fila). Nada sai do sistema nem vem de fora.
 */
class Simulacao
{
    private function exigir(): void
    {
        if (! Provedores::podeSimular()) {
            throw new AuthorizationException('Simulação indisponível neste ambiente.');
        }
    }

    public function mensagemRecebida(WhatsappNumero $numero, string $telefone, ?string $nome, string $tipo, ?string $texto): int
    {
        $this->exigir();
        $conteudo = match ($tipo) {
            'imagem' => ['tipo' => 'imagem', 'midia_id' => 'SIM-MIDIA-imagem-'.Str::random(8), 'mime' => 'image/png', 'nome' => 'foto-simulada.png', 'legenda' => $texto],
            'audio' => ['tipo' => 'audio', 'midia_id' => 'SIM-MIDIA-audio-'.Str::random(8), 'mime' => 'audio/ogg', 'nome' => 'audio-simulado.ogg'],
            'documento' => ['tipo' => 'documento', 'midia_id' => 'SIM-MIDIA-documento-'.Str::random(8), 'mime' => 'application/pdf', 'nome' => 'documento-simulado.pdf', 'legenda' => $texto],
            'localizacao' => ['tipo' => 'localizacao', 'lat' => -15.7942, 'lng' => -47.8822, 'nome' => 'Local simulado'],
            default => ['tipo' => 'texto', 'texto' => $texto ?: 'Mensagem simulada'],
        };

        return $this->injetar($numero, [['tipo' => 'mensagem', 'id' => 'SIM-IN-'.Str::upper(Str::random(14)), 'de' => '55'.$telefone, 'nome' => $nome,
            'conteudo' => $conteudo, 'timestamp' => now()->getTimestamp()]]);
    }

    /** Coexistência: atendente responde pelo app do celular da empresa (eco). */
    public function respostaCelular(WhatsappConversa $conversa, string $texto): int
    {
        $this->exigir();

        return $this->injetar($conversa->numero, [['tipo' => 'eco', 'id' => 'SIM-ECO-'.Str::upper(Str::random(14)), 'para' => $conversa->contato_wa_id,
            'conteudo' => ['tipo' => 'texto', 'texto' => $texto], 'timestamp' => now()->getTimestamp()]]);
    }

    public function injetar(WhatsappNumero $numero, array $eventos): int
    {
        $this->exigir();
        $normalizados = (new SimuladorWhatsApp)->interpretar(['numero' => $numero->telefone, 'eventos' => $eventos]);

        return app(Recebimento::class)->registrar('simulado', $normalizados);
    }
}
