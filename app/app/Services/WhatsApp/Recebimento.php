<?php

namespace App\Services\WhatsApp;

use App\Jobs\BaixarMidiaWhatsApp;
use App\Jobs\ProcessarEventoWhatsApp;
use App\Models\Cliente;
use App\Models\IntegrationEvent;
use App\Models\WhatsappConversa;
use App\Models\WhatsappMensagem;
use App\Models\WhatsappNumero;
use App\Services\Auditoria;
use App\Support\Normaliza;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Entrada da Central: grava eventos normalizados (deduplicados em integration_events) e os processa no worker.
 * Idempotência dupla: (provedor, evento_id) em integration_events e (numero_id, id_provedor) em whatsapp_mensagens.
 */
class Recebimento
{
    /** @param list<array{evento_id: string, tipo: string, numero_id: int, dados: array}> $eventos  @return int eventos novos */
    public function registrar(string $provedor, array $eventos): int
    {
        $novos = 0;
        DB::transaction(function () use ($provedor, $eventos, &$novos) {
            foreach ($eventos as $e) {
                $eventoId = strlen($e['evento_id']) > 120 ? substr($e['evento_id'], 0, 40).':'.hash('sha256', $e['evento_id']) : $e['evento_id'];
                $inserido = IntegrationEvent::query()->insertOrIgnore([
                    'provedor' => 'whatsapp_'.$provedor, 'evento_id' => $eventoId, 'tipo' => $e['tipo'],
                    'payload' => json_encode(['numero_id' => $e['numero_id'], 'dados' => $e['dados']]), 'recebido_em' => now(),
                ]);
                if ($inserido) {
                    $novos++;
                    $id = IntegrationEvent::where('provedor', 'whatsapp_'.$provedor)->where('evento_id', $eventoId)->value('id');
                    ProcessarEventoWhatsApp::dispatch($id)->afterCommit();
                }
            }
        });

        return $novos;
    }

    /** @return string resultado gravado no evento */
    public function processar(IntegrationEvent $evento): string
    {
        $numero = WhatsappNumero::findOrFail($evento->payload['numero_id']);
        $d = $evento->payload['dados'];

        return match ($evento->tipo) {
            'status' => $this->status($numero, $d),
            'mensagem', 'eco', 'historico' => $this->mensagem($numero, $d, $evento->tipo),
            default => 'tipo_ignorado',
        };
    }

    private function mensagem(WhatsappNumero $numero, array $d, string $tipoEvento): string
    {
        $historico = $tipoEvento === 'historico';
        $saida = $d['direcao'] === 'saida';
        $ocorrida = CarbonImmutable::createFromTimestamp((int) $d['timestamp'])->min(now()->toImmutable());

        return DB::transaction(function () use ($numero, $d, $historico, $saida, $ocorrida) {
            if (WhatsappMensagem::where('numero_id', $numero->id)->where('id_provedor', $d['id'])->exists()) {
                return 'duplicada';
            }
            [$conversa, $criada] = $this->conversa($numero, $d, $historico);
            $conversa = WhatsappConversa::lockForUpdate()->find($conversa->id);
            if (WhatsappMensagem::where('numero_id', $numero->id)->where('id_provedor', $d['id'])->exists()) {
                return 'duplicada';
            }
            $midia = $d['midia'] ?? null;
            $m = new WhatsappMensagem([
                'conversa_id' => $conversa->id, 'numero_id' => $numero->id, 'direcao' => $saida ? 'saida' : 'entrada', 'origem' => $saida ? 'celular' : 'cliente',
                'historico' => $historico, 'tipo' => $d['tipo'], 'texto' => $d['texto'] ?? null, 'id_provedor' => $d['id'],
                'status' => $saida ? 'enviada' : 'recebida', 'ocorrida_em' => $ocorrida, 'enviada_em' => $saida ? $ocorrida : null,
                'midia_id_provedor' => $midia['id'] ?? null, 'midia_mime' => $midia ? (mb_substr((string) $midia['mime'], 0, 100) ?: null) : null,
                'midia_nome' => $midia['nome'] ?? null, 'midia_status' => $midia ? 'pendente' : null,
                'latitude' => $d['localizacao']['lat'] ?? null, 'longitude' => $d['localizacao']['lng'] ?? null,
            ]);
            if ($d['tipo'] === 'localizacao' && ! empty($d['localizacao']['nome'])) {
                $m->texto = mb_substr((string) $d['localizacao']['nome'], 0, 200);
            }

            $antes = $conversa->only(['situacao', 'aguardando_desde']);
            $ultima = $conversa->ultima_mensagem_em;
            $conversa->ultima_mensagem_em = $ultima && $ultima->greaterThan($ocorrida) ? $ultima : $ocorrida;
            if (! $saida && (! $conversa->ultima_entrada_em || $conversa->ultima_entrada_em->lessThan($ocorrida))) {
                $conversa->ultima_entrada_em = $ocorrida;
            }
            if (! $saida && ! empty($d['nome']) && ! $conversa->contato_nome) {
                $conversa->contato_nome = mb_substr((string) $d['nome'], 0, 120);
            }
            if (! $historico) {
                if ($saida) {
                    // Resposta pelo celular da empresa conta como resposta: encerra a espera.
                    if ($conversa->aguardando_desde) {
                        $m->aguardava_desde = $conversa->aguardando_desde;
                        $m->tempo_resposta_segundos = max(0, (int) $conversa->aguardando_desde->diffInSeconds($ocorrida));
                        $conversa->aguardando_desde = null;
                    }
                    if ($conversa->situacao === 'nova') {
                        $conversa->situacao = 'em_atendimento';
                    }
                } else {
                    $conversa->aguardando_desde ??= $ocorrida;
                    if ($conversa->situacao === 'resolvida') {
                        $conversa->forceFill(['situacao' => $conversa->responsavel_id ? 'em_atendimento' : 'nova', 'resolvida_em' => null, 'motivo_resolucao' => null]);
                    } elseif ($conversa->situacao === 'aguardando_cliente') {
                        $conversa->situacao = 'em_atendimento';
                    }
                }
            }
            $this->vincularClienteAutomatico($conversa);
            $conversa->save();
            $m->save();

            if ($antes['situacao'] === 'resolvida' && $conversa->situacao !== 'resolvida') {
                Auditoria::registrar('whatsapp_conversa_reaberta', $conversa, ['situacao' => 'resolvida'], ['situacao' => $conversa->situacao, 'mensagem_id' => $m->id], atorTipo: 'sistema');
            }
            $acao = $historico ? 'whatsapp_historico_importado' : ($saida ? 'whatsapp_resposta_celular' : 'whatsapp_recebida');
            Auditoria::registrar($acao, $conversa, depois: ['mensagem_id' => $m->id, 'tipo' => $m->tipo, 'nova_conversa' => $criada] + ($m->tempo_resposta_segundos !== null ? ['tempo_resposta_segundos' => $m->tempo_resposta_segundos] : []), atorTipo: 'sistema');
            if ($m->midia_status === 'pendente') {
                BaixarMidiaWhatsApp::dispatch($m->id)->afterCommit();
            }

            return $historico ? 'historico_importado' : ($saida ? 'eco_registrado' : 'mensagem_registrada');
        });
    }

    /** @return array{0: WhatsappConversa, 1: bool} conversa e se foi criada agora */
    private function conversa(WhatsappNumero $numero, array $d, bool $historico): array
    {
        $telefone = Normaliza::telefone($d['contato']);
        $existente = WhatsappConversa::where('numero_id', $numero->id)->where('contato_telefone', $telefone)->first();
        if ($existente) {
            return [$existente, false];
        }
        try {
            // Savepoint: corrida de duas mensagens do mesmo contato novo não aborta a transação externa.
            $nova = DB::transaction(fn () => WhatsappConversa::create([
                'numero_id' => $numero->id, 'unidade_id' => $numero->unidade_id, 'contato_telefone' => $telefone, 'contato_wa_id' => $d['contato'],
                'contato_nome' => ($d['direcao'] === 'entrada' && ! empty($d['nome'])) ? mb_substr((string) $d['nome'], 0, 120) : null,
                // Conversa que só existe no histórico importado não entra como pendente.
                'situacao' => $historico ? 'resolvida' : 'nova',
                'resolvida_em' => $historico ? now() : null, 'motivo_resolucao' => $historico ? 'Histórico importado do aplicativo (coexistência)' : null,
            ]));

            return [$nova, true];
        } catch (UniqueConstraintViolationException) {
            return [WhatsappConversa::where('numero_id', $numero->id)->where('contato_telefone', $telefone)->firstOrFail(), false];
        }
    }

    /** Vínculo automático pelo telefone normalizado, somente se houver exatamente um cliente ativo com ele. */
    public function vincularClienteAutomatico(WhatsappConversa $conversa): void
    {
        if ($conversa->cliente_id) {
            return;
        }
        $candidatos = self::variacoesTelefone($conversa->contato_telefone);
        $ids = Cliente::ativos()->whereIn('telefone', $candidatos)->limit(2)->pluck('id');
        if ($ids->count() === 1) {
            $conversa->cliente_id = $ids->first();
        }
    }

    /** WhatsApp às vezes omite o 9º dígito de celulares brasileiros (wa_id com 12 dígitos). */
    public static function variacoesTelefone(string $tel): array
    {
        $v = [$tel];
        if (strlen($tel) === 10 && in_array($tel[2], ['6', '7', '8', '9'], true)) {
            $v[] = substr($tel, 0, 2).'9'.substr($tel, 2);
        }
        if (strlen($tel) === 11 && $tel[2] === '9') {
            $v[] = substr($tel, 0, 2).substr($tel, 3);
        }

        return $v;
    }

    private function status(WhatsappNumero $numero, array $d): string
    {
        return DB::transaction(function () use ($numero, $d) {
            $m = WhatsappMensagem::where('numero_id', $numero->id)->where('id_provedor', $d['id'])->where('direcao', 'saida')->lockForUpdate()->first();
            if (! $m) {
                return 'mensagem_desconhecida';
            }
            $quando = CarbonImmutable::createFromTimestamp((int) $d['timestamp'])->min(now()->toImmutable());
            if ($d['status'] === 'falhou') {
                if (in_array($m->status, ['entregue', 'lida'], true)) {
                    return 'ignorado_fora_de_ordem';
                }
                $m->update(['status' => 'falhou', 'ultimo_erro' => $d['erro'] ?? 'Provedor informou falha na entrega.']);
                app(Atendimento::class)->restaurarEspera($m);
                Auditoria::registrar('whatsapp_envio_falhou', $m->conversa, depois: ['mensagem_id' => $m->id, 'origem' => 'webhook_status'], motivo: $m->ultimo_erro, atorTipo: 'sistema');

                return 'status_falhou';
            }
            $atual = WhatsappMensagem::ORDEM_STATUS[$m->status] ?? 0;
            if ($m->status === 'falhou' || WhatsappMensagem::ORDEM_STATUS[$d['status']] <= $atual) {
                return 'ignorado_fora_de_ordem';
            }
            $campos = ['status' => $d['status']];
            $campos['enviada_em'] = $m->enviada_em ?? $quando;
            if (in_array($d['status'], ['entregue', 'lida'], true)) {
                $campos['entregue_em'] = $m->entregue_em ?? $quando;
            }
            if ($d['status'] === 'lida') {
                $campos['lida_em'] = $quando;
            }
            $m->update($campos);

            return 'status_'.$d['status'];
        });
    }
}
