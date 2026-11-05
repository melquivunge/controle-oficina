<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WhatsappConversa;
use App\Models\WhatsappMensagem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Controle da Central: conversas sem resposta além do limite do número (padrão 15 min) e indicadores.
 * Respeita a visibilidade do usuário (unidade/triagem). Uso no sino/painel: app(Atrasos::class)->semResposta($user).
 */
class Atrasos
{
    /**
     * Conversas visíveis ao usuário, não resolvidas, cuja primeira mensagem do cliente sem resposta passou de
     * whatsapp_numeros.minutos_alerta. Ordenadas da espera mais antiga para a mais recente.
     * Cada item traz ->minutos_sem_resposta (int) e as relações numero, unidade e cliente carregadas.
     *
     * @return Collection<int, WhatsappConversa>
     */
    public function semResposta(User $user): Collection
    {
        return $this->consultaSemResposta($user)
            ->with(['numero', 'unidade', 'cliente'])->orderBy('whatsapp_conversas.aguardando_desde')->limit(200)->get()
            ->each(fn (WhatsappConversa $c) => $c->setAttribute('minutos_sem_resposta', (int) $c->aguardando_desde->diffInMinutes(now())));
    }

    public function consultaSemResposta(User $user): Builder
    {
        return WhatsappConversa::visiveisPara($user)->select('whatsapp_conversas.*')
            ->join('whatsapp_numeros', 'whatsapp_numeros.id', '=', 'whatsapp_conversas.numero_id')
            ->where('whatsapp_conversas.situacao', '<>', 'resolvida')
            ->whereNotNull('whatsapp_conversas.aguardando_desde')
            ->whereRaw('whatsapp_conversas.aguardando_desde <= ?::timestamptz - make_interval(mins => whatsapp_numeros.minutos_alerta)', [now()->toIso8601String()]);
    }

    /** @return array{abertas: int, sem_resposta: int, triagem: int, minhas: int, tempo_medio_primeira_resposta_min: ?float} */
    public function indicadores(User $user, int $dias = 30): array
    {
        $abertas = WhatsappConversa::visiveisPara($user)->where('situacao', '<>', 'resolvida');
        $media = WhatsappMensagem::whereIn('conversa_id', WhatsappConversa::visiveisPara($user)->select('id'))
            ->whereNotNull('tempo_resposta_segundos')->where('status', '<>', 'falhou')->where('ocorrida_em', '>=', now()->subDays($dias))
            ->avg('tempo_resposta_segundos');

        return [
            'abertas' => (clone $abertas)->count(),
            'sem_resposta' => $this->consultaSemResposta($user)->count(),
            'triagem' => (clone $abertas)->whereNull('unidade_id')->count(),
            'minhas' => (clone $abertas)->where('responsavel_id', $user->id)->count(),
            'tempo_medio_primeira_resposta_min' => $media !== null ? round($media / 60, 1) : null,
        ];
    }
}
