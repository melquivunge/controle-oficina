<?php

namespace App\Services;

use App\Models\User;
use App\Models\WhatsappConversa;
use App\Services\WhatsApp\Atrasos;
use App\Support\Dinheiro;
use Illuminate\Support\Str;

/** Sino: WhatsApp sem resposta, entregas atrasadas, parcelas vencidas e pendências abertas (não são acusação). */
class Notificacoes
{
    public function __construct(private Pendencias $pendencias, private Atrasos $atrasos, private PrazosEntrega $prazos, private Combinados $combinados, private AprovacoesInternas $aprovacoes, private PosVenda $posVenda) {}

    /** @return array{total:int, whatsapp:int, itens: \Illuminate\Support\Collection<int, array{titulo:string, texto:string, url:string, quando:mixed, grave:bool}>} */
    public function abertas(User $user, int $limite = 8): array
    {
        $itens = collect();
        $whatsapp = 0;
        if (WhatsappConversa::atende($user) || WhatsappConversa::fazTriagem($user)) {
            $itens = $itens->merge($this->atrasos->semResposta($user)->map(fn ($c) => [
                'titulo' => 'WhatsApp sem resposta há '.\App\Support\Duracao::curta((int) $c->minutos_sem_resposta), 'texto' => $c->nomeExibicao().' · '.($c->unidade?->nome ?? 'Triagem central'),
                'quando' => $c->ultima_entrada_em ?? $c->updated_at, 'grave' => true, 'url' => route('whatsapp.show', $c),
            ]));
            $whatsapp = $itens->count();
        }
        if ($user->pode('orcar') || $user->pode('entregar')) {
            $itens = $itens->merge($this->prazos->atrasadas($user, 20)->map(fn ($o) => [
                'titulo' => 'Prazo de entrega vencido', 'texto' => $o->numero.' · '.$o->veiculo?->placaFormatada().' · previsto '.$o->entrega_prevista_em?->format('d/m'),
                'quando' => $o->entrega_prevista_em, 'grave' => true, 'url' => route('ordens.show', $o),
            ]));
        }
        // Gestores recebem parcelas vencidas pelas Pendências; quem só cobra (recepção) recebe aqui.
        if (! $user->pode('resolver_divergencias') && ($user->pode('receber_manual') || $user->pode('cobrar'))) {
            $itens = $itens->merge($this->combinados->vencidas($user)->map(fn ($p) => [
                'titulo' => 'Pagamento combinado vencido', 'texto' => $p->ordem->numero.' · '.Dinheiro::formatar($p->emAbertoCentavos()).' em aberto',
                'quando' => $p->vencimento, 'grave' => false, 'url' => route('ordens.show', ['ordem' => $p->ordem, 'aba' => 'pagamentos']),
            ]));
        }
        $itens = $itens->merge($this->aprovacoes->pendentes($user)->map(fn ($a) => [
            'titulo' => 'Desconto aguardando sua aprovação', 'texto' => ($a->ordem?->numero ?? '').' · pedido de '.($a->solicitante?->name ?? '—'),
            'quando' => $a->created_at, 'grave' => true, 'url' => route('aprovacoes.index'),
        ]));
        $itens = $itens->merge($this->posVenda->retornosPendentes($user)->map(fn ($c) => [
            'titulo' => 'Retorno de pós-venda', 'texto' => ($c->ordem?->numero ?? '').' · combinado para '.$c->retorno_em?->format('d/m'),
            'quando' => $c->retorno_em, 'grave' => false, 'url' => route('posvenda.show', $c->ordem),
        ]));
        if ($user->pode('resolver_divergencias')) {
            // ponytail: recalcula a lista a cada página (até 200 por tipo); cachear por usuário se pesar
            $itens = $itens->merge($this->pendencias->listar($user)->map(fn ($i) => [
                'titulo' => $i['rotulo'], 'texto' => Str::limit($i['descricao'], 90), 'quando' => $i['data'], 'grave' => $i['gravidade'] === 'critico',
                'url' => route('pendencias.index'),
            ]));
        }

        return ['total' => $itens->count(), 'whatsapp' => $whatsapp, 'itens' => $itens->take($limite)->values()];
    }
}
