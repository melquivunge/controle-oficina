<?php

namespace App\Integracoes\WhatsApp;

use App\Models\WhatsappNumero;
use App\Support\Normaliza;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SIMULADO: nenhuma mensagem sai do sistema e nenhuma chega de fora. Webhook próprio com HMAC (X-Assinatura).
 * Destino terminado em 0000 simula indisponibilidade do provedor. Mídias são arquivos fictícios gerados aqui.
 *
 * Formato do webhook: {"numero": "<telefone oficial>", "eventos": [
 *   {"tipo":"mensagem","id":"..","de":"5561..","nome":"..","conteudo":{..},"timestamp":1700000000},
 *   {"tipo":"eco","id":"..","para":"5561..","conteudo":{..}},             (resposta pelo celular da empresa)
 *   {"tipo":"historico","id":"..","contato":"5561..","direcao":"entrada|saida","conteudo":{..},"timestamp":..},
 *   {"tipo":"status","id":"<id da mensagem enviada>","status":"enviada|entregue|lida|falhou","erro":".."}]}
 * conteudo: {"tipo":"texto","texto":".."} | {"tipo":"imagem|audio|documento","midia_id":"SIM-MIDIA-..","mime":"..","nome":"..","legenda":".."}
 *           | {"tipo":"localizacao","lat":-15.79,"lng":-47.88,"nome":".."}
 */
class SimuladorWhatsApp implements ProvedorWhatsApp
{
    public const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function nome(): string
    {
        return 'simulado';
    }

    public static function assinar(string $corpo): string
    {
        return hash_hmac('sha256', $corpo, (string) config('oficina.whatsapp.webhook_segredo'));
    }

    public function webhookAutentico(Request $request): bool
    {
        $segredo = (string) config('oficina.whatsapp.webhook_segredo');

        return $segredo !== '' && hash_equals(hash_hmac('sha256', $request->getContent(), $segredo), (string) $request->header('X-Assinatura'));
    }

    public function interpretar(array $payload): array
    {
        $numero = WhatsappNumero::where('telefone', Normaliza::telefone((string) ($payload['numero'] ?? '')))->where('ativo', true)->first();
        if (! $numero) {
            return [];
        }
        $eventos = [];
        foreach ((array) ($payload['eventos'] ?? []) as $ev) {
            $id = is_array($ev) ? (string) ($ev['id'] ?? '') : '';
            if ($id === '' || strlen($id) > 150) {
                continue;
            }
            $tipo = (string) ($ev['tipo'] ?? '');
            if ($tipo === 'status') {
                $status = (string) ($ev['status'] ?? '');
                if (in_array($status, ['enviada', 'entregue', 'lida', 'falhou'], true)) {
                    $eventos[] = ['evento_id' => "st:{$id}:{$status}", 'tipo' => 'status', 'numero_id' => $numero->id,
                        'dados' => ['id' => $id, 'status' => $status, 'timestamp' => (int) ($ev['timestamp'] ?? time()), 'erro' => isset($ev['erro']) ? mb_substr((string) $ev['erro'], 0, 300) : null]];
                }

                continue;
            }
            $contato = preg_replace('/\D/', '', (string) match ($tipo) {
                'mensagem' => $ev['de'] ?? '', 'eco' => $ev['para'] ?? '', 'historico' => $ev['contato'] ?? '', default => '',
            });
            $conteudo = self::conteudo((array) ($ev['conteudo'] ?? []));
            if (! in_array($tipo, ['mensagem', 'eco', 'historico'], true) || strlen($contato) < 10 || strlen($contato) > 15 || ! $conteudo) {
                continue;
            }
            $direcao = match ($tipo) { 'mensagem' => 'entrada', 'eco' => 'saida', default => ($ev['direcao'] ?? '') === 'saida' ? 'saida' : 'entrada' };
            $prefixo = ['mensagem' => 'msg', 'eco' => 'eco', 'historico' => 'hist'][$tipo];
            $eventos[] = ['evento_id' => "{$prefixo}:{$id}", 'tipo' => $tipo, 'numero_id' => $numero->id, 'dados' => $conteudo + [
                'id' => $id, 'contato' => $contato, 'nome' => isset($ev['nome']) ? mb_substr((string) $ev['nome'], 0, 120) : null,
                'direcao' => $direcao, 'timestamp' => (int) ($ev['timestamp'] ?? time()),
            ]];
        }

        return $eventos;
    }

    /** Converte o conteúdo do simulador para o formato normalizado; null = inválido. */
    private static function conteudo(array $c): ?array
    {
        return match ($c['tipo'] ?? null) {
            'texto' => isset($c['texto']) && trim((string) $c['texto']) !== '' ? ['tipo' => 'texto', 'texto' => mb_substr((string) $c['texto'], 0, 4096)] : null,
            'imagem', 'audio', 'documento' => isset($c['midia_id']) ? ['tipo' => $c['tipo'], 'texto' => isset($c['legenda']) ? mb_substr((string) $c['legenda'], 0, 1024) : null,
                'midia' => ['id' => mb_substr((string) $c['midia_id'], 0, 200), 'mime' => mb_substr((string) ($c['mime'] ?? ''), 0, 100), 'nome' => isset($c['nome']) ? mb_substr((string) $c['nome'], 0, 200) : null]] : null,
            'localizacao' => is_numeric($c['lat'] ?? null) && is_numeric($c['lng'] ?? null) && abs((float) $c['lat']) <= 90 && abs((float) $c['lng']) <= 180
                ? ['tipo' => 'localizacao', 'localizacao' => ['lat' => (float) $c['lat'], 'lng' => (float) $c['lng'], 'nome' => isset($c['nome']) ? mb_substr((string) $c['nome'], 0, 200) : null]] : null,
            default => null,
        };
    }

    public function enviarTexto(WhatsappNumero $numero, string $para, string $texto): string
    {
        return $this->simularEnvio($para, mb_strlen($texto));
    }

    public function enviarModelo(WhatsappNumero $numero, string $para, string $modelo, string $idioma): string
    {
        return $this->simularEnvio($para, 0);
    }

    public function enviarMidia(WhatsappNumero $numero, string $para, string $conteudo, string $mime, string $nomeArquivo, ?string $legenda): string
    {
        return $this->simularEnvio($para, strlen($conteudo));
    }

    private function simularEnvio(string $para, int $tamanho): string
    {
        if (str_ends_with($para, '0000')) {
            throw new RuntimeException('Simulação: provedor de WhatsApp indisponível.');
        }
        Log::info('whatsapp simulado (não enviado a ninguém)', ['destino_final' => substr($para, -4), 'tamanho' => $tamanho]);

        return 'SIM-WA-'.Str::upper(Str::random(16));
    }

    /** Arquivos fictícios pelo marcador no id: imagem, audio, documento, executavel (recusado na validação), grande (excede o limite). */
    public function baixarMidia(WhatsappNumero $numero, string $midiaId, int $limiteBytes): array
    {
        $conteudo = match (true) {
            str_contains($midiaId, 'executavel') => "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00".str_repeat("\x00", 64),
            str_contains($midiaId, 'grande') => str_repeat('A', $limiteBytes + 1),
            str_contains($midiaId, 'audio') => "OggS\x00\x02".str_repeat("\x00", 20)."\x01\x13OpusHead\x01\x01".str_repeat("\x00", 40),
            str_contains($midiaId, 'documento') => "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n",
            str_contains($midiaId, 'falha') => throw new RuntimeException('Simulação: mídia indisponível no provedor.'),
            default => base64_decode(self::PNG),
        };
        if (strlen($conteudo) > $limiteBytes) {
            throw new MidiaRecusada('Arquivo maior que o limite permitido.');
        }

        return ['conteudo' => $conteudo, 'mime' => null];
    }
}
