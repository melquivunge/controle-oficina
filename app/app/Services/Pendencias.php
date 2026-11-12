<?php

namespace App\Services;

use App\Models\Cobranca;
use App\Models\Devolucao;
use App\Models\Divergencia;
use App\Models\Entrega;
use App\Models\FiscalDocument;
use App\Models\MessageDelivery;
use App\Models\Ordem;
use App\Models\Pagamento;
use App\Models\ResolucaoPendencia;
use App\Models\Unidade;
use App\Models\User;
use App\Support\Dinheiro;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lista unificada de situações a investigar (não são acusações) nas unidades visíveis ao usuário.
 * Divergências usam a resolução própria (Conferencias::resolver); pagamento em revisão é tratado na OS.
 */
class Pendencias
{
    /** Tipos cuja resolução fica em resolucoes_pendencias: [modelo, condição que caracteriza a pendência]. */
    private const RESOLVIVEIS = [
        'entrega_excecao' => [Entrega::class, ['excecao' => true]],
        'cobranca_falha' => [Cobranca::class, ['status' => 'falha']],
        'mensagem_falhou' => [MessageDelivery::class, ['status' => 'falhou']],
        'fiscal_rejeitado' => [FiscalDocument::class, ['status' => 'rejeitado']],
        'devolucao_falhou' => [Devolucao::class, ['status' => 'falhou']],
        // Pós-venda: relato do cliente registrado no contato (App\Services\PosVenda).
        'pos_venda_problema' => [\App\Models\PosVendaContato::class, ['problema_servico' => true]],
        'pos_venda_negociacao' => [\App\Models\PosVendaContato::class, ['negociacao_fora_padrao' => true]],
    ];

    private const LIMITE_POR_TIPO = 200;

    public function __construct(private Conferencias $conferencias) {}

    public static function tiposResolviveis(): array
    {
        return [...array_keys(self::RESOLVIVEIS), 'divergencia'];
    }

    /** @return Collection<int, array> ordenada por gravidade e data (mais recente primeiro) */
    public function listar(User $user, bool $incluirResolvidas = false): Collection
    {
        $escopo = fn (Builder $q) => $user->restringirUnidades($q);
        $semResolucao = fn (string $tipo, string $tabela) => fn (Builder $q) => $q->when(! $incluirResolvidas,
            fn ($q) => $q->whereNotExists(fn ($s) => $s->from('resolucoes_pendencias')->where('tipo', $tipo)->whereColumn('referencia_id', "{$tabela}.id")));
        $itens = collect();

        $entregas = $escopo(Entrega::query())->where('excecao', true)->where($semResolucao('entrega_excecao', 'entregas'))->orderByDesc('entregue_em')->limit(self::LIMITE_POR_TIPO)->get();
        foreach ($entregas as $e) {
            $itens->push($this->item('entrega_excecao', $e, 'critico', 'Entrega sem quitação', $e->entregue_em,
                'Entregue com saldo de '.Dinheiro::formatar((int) $e->saldo_centavos).'. Motivo: '.$e->excecao_motivo));
        }

        foreach ($escopo(Pagamento::query())->where('status', 'em_revisao')->orderByDesc('recebido_em')->limit(self::LIMITE_POR_TIPO)->get() as $p) {
            $itens->push($this->item('pagamento_revisao', $p, 'critico', 'Pagamento em revisão', $p->recebido_em,
                $p->rotuloMeio().' de '.Dinheiro::formatar((int) $p->valor_centavos).': '.($p->motivo_revisao ?: 'aguardando revisão').'. Não quita a OS até ser aceito; aceite ou devolva na OS.', resolvivel: false));
        }

        $divergencias = $escopo(Divergencia::query())->when(! $incluirResolvidas, fn ($q) => $q->whereNull('resolvido_em'))->with('resolvidoPor')->orderByDesc('created_at')->limit(self::LIMITE_POR_TIPO)->get();
        foreach ($divergencias as $d) {
            $item = $this->item('divergencia', $d, 'atencao', 'Divergência na conferência', $d->created_at,
                $d->rotulo().($d->placa ? ' — placa '.\App\Support\Normaliza::placaExibicao($d->placa) : ' — OS '.($d->ordem?->numero ?? '').' (sem veículo: '.($d->ordem?->item_descricao ?? '').')').'.');
            if ($d->resolvido_em) {
                $item['resolucao'] = ['texto' => $d->resolucao, 'por' => $d->resolvidoPor?->name, 'em' => $d->resolvido_em];
            }
            $itens->push($item);
        }

        foreach ($escopo(Cobranca::query())->where('status', 'falha')->where($semResolucao('cobranca_falha', 'cobrancas'))->orderByDesc('created_at')->limit(self::LIMITE_POR_TIPO)->get() as $c) {
            $itens->push($this->item('cobranca_falha', $c, 'atencao', 'Falha ao gerar cobrança Pix', $c->created_at,
                'Cobrança de '.Dinheiro::formatar((int) $c->valor_centavos).' não foi gerada: '.($c->erro ?: 'erro não informado').'.'));
        }

        // Sai da lista sozinha quando a mesma mensagem (OS + tipo) foi enviada depois, pelo reenvio ou por um envio novo.
        foreach ($escopo(MessageDelivery::query())->where('status', 'falhou')->where($semResolucao('mensagem_falhou', 'message_deliveries'))
            ->whereNotExists(fn ($s) => $s->from('message_deliveries as ok')->where('ok.status', 'enviado')->whereColumn('ok.ordem_id', 'message_deliveries.ordem_id')
                ->whereColumn('ok.tipo', 'message_deliveries.tipo')->whereColumn('ok.id', '>', 'message_deliveries.id'))->orderByDesc('updated_at')->limit(self::LIMITE_POR_TIPO)->get() as $m) {
            $itens->push($this->item('mensagem_falhou', $m, 'atencao', 'Mensagem não enviada', $m->updated_at,
                "{$m->rotuloTipo()} falhou após {$m->tentativas} tentativa(s): ".($m->ultimo_erro ?: 'erro não informado').'. Reenvie na aba Mensagens.'));
        }

        foreach ($escopo(FiscalDocument::query())->where('status', 'rejeitado')->where($semResolucao('fiscal_rejeitado', 'fiscal_documents'))->orderByDesc('updated_at')->limit(self::LIMITE_POR_TIPO)->get() as $f) {
            $itens->push($this->item('fiscal_rejeitado', $f, 'atencao', 'Documento fiscal rejeitado', $f->updated_at,
                'Emissão de '.Dinheiro::formatar((int) $f->valor_centavos).' rejeitada: '.($f->mensagem ?: 'motivo não informado').'.'));
        }

        // Sai da lista sozinha quando uma nova tentativa conclui (status deixa de ser 'falhou').
        foreach ($escopo(Devolucao::query())->where('status', 'falhou')->where($semResolucao('devolucao_falhou', 'devolucoes'))->orderByDesc('updated_at')->limit(self::LIMITE_POR_TIPO)->get() as $d) {
            $itens->push($this->item('devolucao_falhou', $d, 'atencao', 'Devolução falhou: provedor recusou', $d->updated_at,
                'Devolução Pix de '.Dinheiro::formatar((int) $d->valor_centavos).' não foi feita: '.($d->mensagem ?: 'motivo não informado').'. Tente de novo na OS ou devolva por outro meio e registre a resolução.'));
        }

        // Parcela combinada vencida e não paga: some sozinha quando o recebimento efetivo cobre a parcela.
        foreach (app(Combinados::class)->vencidas($user) as $p) {
            $itens->push($this->item('pagamento_vencido', $p, 'atencao', 'Pagamento combinado vencido', $p->vencimento,
                'Parcela '.$p->sequencia.' de '.Dinheiro::formatar((int) $p->valor_centavos).' venceu em '.$p->vencimento->format('d/m/Y')
                .'; falta receber '.Dinheiro::formatar($p->emAbertoCentavos()).'. Cobre o cliente e registre o recebimento na OS.', resolvivel: false));
        }

        // Pós-venda: problema no serviço / negociação fora do padrão relatados pelo cliente (resolvíveis pelo gerente).
        foreach (\App\Services\PosVenda::PENDENCIAS as $tipoPv => $colunaPv) {
            $rotuloPv = $tipoPv === 'pos_venda_problema' ? 'Pós-venda: problema no serviço' : 'Pós-venda: negociação fora do padrão';
            $descPv = $tipoPv === 'pos_venda_problema' ? 'problema_descricao' : 'negociacao_descricao';
            foreach ($escopo(\App\Models\PosVendaContato::query())->where($colunaPv, true)->where($semResolucao($tipoPv, 'pos_venda_contatos'))->orderByDesc('registrado_em')->limit(self::LIMITE_POR_TIPO)->get() as $c) {
                $itens->push($this->item($tipoPv, $c, 'atencao', $rotuloPv, $c->registrado_em, 'Relato do cliente: '.$c->{$descPv}.'. Trate com o cliente e registre a resolução.'));
            }
        }

        // Ordens, unidades e resoluções em lote (evita N+1).
        $ordens = Ordem::whereIn('id', $itens->pluck('ordem_id')->filter()->unique())->get(['id', 'numero', 'unidade_id'])->keyBy('id');
        $unidades = Unidade::whereIn('id', $itens->pluck('unidade_id')->unique())->pluck('nome', 'id');
        $resolucoes = ResolucaoPendencia::with('resolvidoPor')->where(function ($q) use ($itens) {
            foreach ($itens->groupBy('tipo') as $tipo => $grupo) {
                $q->orWhere(fn ($q) => $q->where('tipo', $tipo)->whereIn('referencia_id', $grupo->pluck('id')));
            }
        })->get()->keyBy(fn ($r) => "{$r->tipo}:{$r->referencia_id}");

        return $itens->map(function (array $i) use ($ordens, $unidades, $resolucoes) {
            $i['ordem'] = $ordens[$i['ordem_id']] ?? null;
            $i['unidade'] = $unidades[$i['unidade_id']] ?? '—';
            if ($r = $resolucoes["{$i['tipo']}:{$i['id']}"] ?? null) {
                $i['resolucao'] = ['texto' => $r->texto, 'por' => $r->resolvidoPor?->name, 'em' => $r->resolvido_em];
            }

            return $i;
        })->sortBy([fn ($a, $b) => (bool) $a['resolucao'] <=> (bool) $b['resolucao'], fn ($a, $b) => ($a['gravidade'] === 'critico' ? 0 : 1) <=> ($b['gravidade'] === 'critico' ? 0 : 1), fn ($a, $b) => $b['data'] <=> $a['data']])->values();
    }

    public function resolver(User $ator, string $tipo, int $id, string $texto): void
    {
        if ($tipo === 'divergencia') {
            $this->conferencias->resolver(Divergencia::findOrFail($id), $ator, $texto);

            return;
        }
        [$modelo, $condicao] = self::RESOLVIVEIS[$tipo] ?? abort(404);
        $registro = $modelo::where($condicao)->findOrFail($id); // só o que é de fato pendência
        if (! $ator->pode('resolver_divergencias') || ! $ator->acessaUnidade($registro->unidade_id)) {
            throw new AuthorizationException('A resolução de pendências é feita pelo gerente da unidade.');
        }
        $ordem = Ordem::findOrFail($registro->ordem_id);
        try {
            DB::transaction(function () use ($ator, $tipo, $registro, $ordem, $texto) {
                ResolucaoPendencia::create(['tipo' => $tipo, 'referencia_id' => $registro->id, 'unidade_id' => $registro->unidade_id, 'ordem_id' => $ordem->id,
                    'texto' => $texto, 'resolvido_por' => $ator->id, 'resolvido_em' => now()]);
                Auditoria::registrar('pendencia_resolvida', $ordem, depois: ['tipo' => $tipo, 'referencia_id' => $registro->id], motivo: $texto, unidadeId: $registro->unidade_id, ator: $ator);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['resolucao' => 'Esta pendência já foi resolvida.']);
        }
    }

    /** Resolve de uma vez todas as mensagens não enviadas em aberto que o usuário vê; cada uma fica com a mesma resolução e auditoria. */
    public function resolverMensagensFalhas(User $ator, string $texto): int
    {
        $abertas = $this->listar($ator)->where('tipo', 'mensagem_falhou');
        foreach ($abertas as $i) {
            $this->resolver($ator, 'mensagem_falhou', $i['id'], $texto);
        }

        return $abertas->count();
    }

    private function item(string $tipo, $registro, string $gravidade, string $rotulo, $data, string $descricao, bool $resolvivel = true): array
    {
        return ['tipo' => $tipo, 'id' => $registro->id, 'gravidade' => $gravidade, 'rotulo' => $rotulo, 'data' => $data, 'descricao' => $descricao,
            'ordem_id' => $registro->ordem_id, 'unidade_id' => $registro->unidade_id, 'resolvivel' => $resolvivel, 'resolucao' => null];
    }
}
