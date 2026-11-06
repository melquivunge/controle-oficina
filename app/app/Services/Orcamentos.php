<?php

namespace App\Services;

use App\Enums\StatusOrdem;
use App\Models\Aprovacao;
use App\Models\CatalogoItem;
use App\Models\LinkCliente;
use App\Models\Orcamento;
use App\Models\Ordem;
use App\Models\User;
use App\Support\Dinheiro;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Orçamento versionado: totais sempre calculados aqui; versões enviadas ficam congeladas. */
class Orcamentos
{
    public const STATUS_EDITAVEIS = [StatusOrdem::Diagnostico, StatusOrdem::AguardandoAprovacao, StatusOrdem::Autorizado, StatusOrdem::EmExecucao];

    /**
     * @param  list<array{descricao:string, quantidade:string, preco:string}>  $itens  texto vindo da interface
     */
    public function salvarRascunho(Ordem $ordem, User $ator, array $itens, int $descontoCentavos = 0, ?string $descontoMotivo = null, ?string $observacao = null): Orcamento
    {
        $this->autorizar($ator, 'orcar', $ordem);
        $linhas = $this->normalizarItens($itens, $ordem->unidade_id);
        // Item do catálogo abaixo do preço de tabela também é desconto (catálogo, 31/10/2026).
        $descontoEmItem = collect($linhas)->contains(fn ($l) => $l['preco_tabela_centavos'] !== null && $l['preco_unitario_centavos'] < $l['preco_tabela_centavos']);
        if (($descontoCentavos > 0 || $descontoEmItem) && ! $ator->pode('aplicar_desconto')) {
            throw new AuthorizationException('Você não tem permissão para aplicar descontos.');
        }
        if (($descontoCentavos > 0 || $descontoEmItem) && blank($descontoMotivo)) {
            throw ValidationException::withMessages(['desconto_motivo' => 'Informe o motivo do desconto.']);
        }
        $subtotal = array_sum(array_column($linhas, 'total_centavos'));
        if ($descontoCentavos > $subtotal) {
            throw ValidationException::withMessages(['desconto' => 'O desconto não pode ser maior que o subtotal.']);
        }

        return DB::transaction(function () use ($ordem, $ator, $linhas, $subtotal, $descontoCentavos, $descontoMotivo, $observacao, $descontoEmItem) {
            $o = Ordem::lockForUpdate()->findOrFail($ordem->id);
            if (! in_array($o->status, self::STATUS_EDITAVEIS, true)) {
                throw ValidationException::withMessages(['itens' => "Não é possível orçar com a ordem “{$o->status->rotulo()}”."]);
            }
            $rascunho = Orcamento::where('ordem_id', $o->id)->where('status', 'rascunho')->first()
                ?? new Orcamento(['ordem_id' => $o->id, 'unidade_id' => $o->unidade_id, 'status' => 'rascunho',
                    'versao' => (int) Orcamento::where('ordem_id', $o->id)->max('versao') + 1, 'criado_por' => $ator->id]);
            $antes = $rascunho->exists ? $rascunho->only('subtotal_centavos', 'desconto_centavos', 'total_centavos') : null;
            $rascunho->fill([
                'subtotal_centavos' => $subtotal, 'desconto_centavos' => $descontoCentavos, 'total_centavos' => $subtotal - $descontoCentavos,
                'desconto_motivo' => ($descontoCentavos > 0 || $descontoEmItem) ? $descontoMotivo : null, 'observacao_cliente' => $observacao,
            ])->save();

            // Durante a execução, itens que não constam da versão aprovada são extras e dependem de nova aprovação.
            $aprovados = $o->status === StatusOrdem::EmExecucao
                ? Orcamento::where('ordem_id', $o->id)->where('status', 'aprovado')->first()?->itens->map(fn ($i) => $this->chave($i->descricao, $i->quantidade, $i->preco_unitario_centavos))->all() ?? []
                : null;
            $rascunho->itens()->delete();
            foreach ($linhas as $n => $l) {
                $rascunho->itens()->create($l + ['posicao' => $n + 1,
                    'extra' => $aprovados !== null && ! in_array($this->chave($l['descricao'], $l['quantidade'], $l['preco_unitario_centavos']), $aprovados, true)]);
            }
            Auditoria::registrar('orcamento_rascunho', $rascunho, $antes, $rascunho->only('versao', 'subtotal_centavos', 'desconto_centavos', 'total_centavos') + ['itens' => count($linhas)], ($descontoCentavos > 0 || $descontoEmItem) ? $descontoMotivo : null, ator: $ator);
            // Desconto acima do limite abre (ou mantém) pedido de aprovação interna; o envio fica bloqueado até a aprovação.
            app(AprovacoesInternas::class)->sincronizar($rascunho->fresh(), $ator, ($descontoCentavos > 0 || $descontoEmItem) ? $descontoMotivo : null);

            return $rascunho->fresh('itens');
        });
    }

    /** Conclui a versão: fica aguardando aprovação interna. Retorna o token do link do cliente (só visualização), em texto puro (exibido uma vez). */
    public function enviar(Orcamento $orcamento, User $ator): string
    {
        $this->autorizar($ator, 'orcar', $orcamento->ordem);

        return DB::transaction(function () use ($orcamento, $ator) {
            $ordem = Ordem::lockForUpdate()->findOrFail($orcamento->ordem_id);
            $orc = Orcamento::lockForUpdate()->findOrFail($orcamento->id);
            if ($orc->status !== 'rascunho' || $orc->itens()->count() === 0) {
                throw ValidationException::withMessages(['itens' => 'Somente rascunho com ao menos um item pode ser enviado.']);
            }
            app(AprovacoesInternas::class)->exigirLiberacaoParaEnvio($orc);
            // Versão anterior ainda sem decisão deixa de valer: o link passa a mostrar a nova.
            Orcamento::where('ordem_id', $ordem->id)->where('status', 'enviado')->update(['status' => 'substituido']);
            $orc->update(['status' => 'enviado', 'enviado_em' => now()]);

            if (in_array($ordem->status, [StatusOrdem::Diagnostico, StatusOrdem::Autorizado], true)) {
                app(Ordens::class)->transicionar($ordem, StatusOrdem::AguardandoAprovacao, $ator, "Orçamento versão {$orc->versao} enviado");
            }
            Auditoria::registrar('orcamento_enviado', $orc, depois: ['versao' => $orc->versao, 'total_centavos' => $orc->total_centavos], ator: $ator);

            $token = $this->novoLink($ordem, $ator);
            app(Mensagens::class)->enfileirar($ordem, 'orcamento', $token);

            return $token;
        });
    }

    /**
     * Registra decisão. Concorrência: lock da versão + unique(orcamento_id) em aprovacoes.
     * Versão substituída, já decidida ou de link antigo é rejeitada.
     */
    public function decidir(int $orcamentoId, int $ordemId, string $decisao, string $canal, ?User $registrador = null, ?string $observacao = null): Aprovacao
    {
        if (! in_array($decisao, ['aprovado', 'recusado'], true)) {
            throw ValidationException::withMessages(['decisao' => 'Decisão inválida.']);
        }

        return DB::transaction(function () use ($orcamentoId, $ordemId, $decisao, $canal, $registrador, $observacao) {
            $ordem = Ordem::lockForUpdate()->findOrFail($ordemId);
            $orc = Orcamento::lockForUpdate()->where('ordem_id', $ordem->id)->find($orcamentoId);
            if (! $orc || $orc->status !== 'enviado' || $ordem->status->encerrada()) {
                throw ValidationException::withMessages(['decisao' => 'Este orçamento não está mais aguardando decisão. Atualize a página para ver a versão atual.']);
            }
            $aprovacao = Aprovacao::create([
                'orcamento_id' => $orc->id, 'ordem_id' => $ordem->id, 'unidade_id' => $ordem->unidade_id, 'decisao' => $decisao,
                'versao' => $orc->versao, 'total_centavos' => $orc->total_centavos, 'canal' => $canal,
                'registrado_por' => $registrador?->id, 'observacao' => $observacao, 'decidido_em' => now(),
            ]);

            $anterior = Orcamento::where('ordem_id', $ordem->id)->where('status', 'aprovado')->first();
            if ($decisao === 'aprovado') {
                $anterior?->update(['status' => 'substituido']);
                if ($anterior) {
                    app(Financeiro::class)->invalidarCobrancasDaVersao($anterior->id);
                }
                $orc->update(['status' => 'aprovado']);
                if ($anterior) {
                    $this->herdarConclusoes($anterior, $orc);
                }
                if ($ordem->status === StatusOrdem::AguardandoAprovacao) {
                    app(Ordens::class)->transicionar($ordem, StatusOrdem::Autorizado, $registrador, "Versão {$orc->versao} aprovada");
                }
            } else {
                $orc->update(['status' => 'recusado']);
                if ($ordem->status === StatusOrdem::AguardandoAprovacao) {
                    // Sem versão aprovada volta ao diagnóstico; com versão anterior aprovada ela continua valendo.
                    app(Ordens::class)->transicionar($ordem, $anterior ? StatusOrdem::Autorizado : StatusOrdem::Diagnostico, $registrador, "Versão {$orc->versao} recusada");
                }
            }
            Auditoria::registrar($decisao === 'aprovado' ? 'orcamento_aprovado' : 'orcamento_recusado', $orc, depois: [
                'versao' => $orc->versao, 'total_centavos' => $orc->total_centavos, 'canal' => $canal,
            ], motivo: $observacao, unidadeId: $ordem->unidade_id, ator: $registrador, atorTipo: $registrador ? 'usuario' : 'cliente');

            return $aprovacao;
        });
    }

    /**
     * Decisão interna (06/11/2026: quem aprova é dono, gerente/supervisor ou recepção, não o cliente).
     * ponytail: grava canal 'presencial' (= registrado por funcionário; o CHECK do banco exige registrado_por).
     */
    public function decidirPresencial(Orcamento $orc, User $ator, string $decisao, ?string $observacao = null): Aprovacao
    {
        $this->autorizar($ator, 'acompanharCliente', $orc->ordem);

        return $this->decidir($orc->id, $orc->ordem_id, $decisao, 'presencial', $ator, $observacao);
    }

    /** Um clique: conclui o rascunho e aprova na hora (só quem orça). Tudo ou nada. */
    public function aprovarDireto(Orcamento $rascunho, User $ator, ?string $observacao = null): string
    {
        return DB::transaction(function () use ($rascunho, $ator, $observacao) {
            $token = $this->enviar($rascunho, $ator);
            $this->decidirPresencial($rascunho->fresh(), $ator, 'aprovado', $observacao);

            return $token;
        });
    }

    /** Links anteriores seguem válidos (mensagens já enviadas), salvo revogação explícita. */
    public function novoLink(Ordem $ordem, User $ator, int $dias = 30, bool $revogarAnteriores = false): string
    {
        $token = Str::random(48);
        if ($revogarAnteriores) {
            LinkCliente::where('ordem_id', $ordem->id)->whereNull('revogado_em')->update(['revogado_em' => now()]);
        }
        $link = LinkCliente::create(['ordem_id' => $ordem->id, 'unidade_id' => $ordem->unidade_id, 'token_hash' => LinkCliente::hash($token),
            'expira_em' => now()->addDays($dias), 'criado_por' => $ator->id]);
        Auditoria::registrar('link_cliente_gerado', $ordem, depois: ['link_id' => $link->id, 'expira_em' => $link->expira_em->toIso8601String()], ator: $ator);

        return $token;
    }

    public function revogarLinks(Ordem $ordem, User $ator): void
    {
        $this->autorizar($ator, 'acompanharCliente', $ordem);
        $n = LinkCliente::where('ordem_id', $ordem->id)->whereNull('revogado_em')->update(['revogado_em' => now()]);
        Auditoria::registrar('link_cliente_revogado', $ordem, depois: ['links' => $n], ator: $ator);
    }

    /**
     * Item do catálogo: preço de tabela (com override da unidade) e limite vêm do servidor, nunca da interface.
     *
     * @return list<array{descricao:string, quantidade:string, preco_unitario_centavos:int, total_centavos:int, catalogo_item_id:?int, preco_tabela_centavos:?int, desconto_max_percentual:?string}>
     */
    private function normalizarItens(array $itens, ?int $unidadeId = null): array
    {
        $erros = [];
        $linhas = [];
        $ids = collect($itens)->pluck('catalogo_item_id')->filter()->map(fn ($v) => (int) $v)->unique()->all();
        $catalogo = $ids ? CatalogoItem::with('precosUnidade')->whereIn('id', $ids)->get()->keyBy('id') : collect();
        foreach (array_values($itens) as $n => $item) {
            $descricao = trim((string) ($item['descricao'] ?? ''));
            $catId = filled($item['catalogo_item_id'] ?? null) ? (int) $item['catalogo_item_id'] : null;
            $cat = $catId ? $catalogo->get($catId) : null;
            if ($catId && (! $cat || ! $cat->ativo)) {
                $erros["itens.$n.descricao"] = 'Item do catálogo inexistente ou inativo. Escolha outro ou use item livre.';
            }
            if ($cat && trim((string) ($item['preco'] ?? '')) === '') {
                $item['preco'] = Dinheiro::paraCampo($cat->precoNaUnidade($unidadeId));
            }
            if ($descricao === '' && trim((string) ($item['preco'] ?? '')) === '' && ! $catId) {
                continue; // linha vazia
            }
            try {
                $q = Dinheiro::quantidade((string) ($item['quantidade'] ?? ''));
            } catch (\InvalidArgumentException) {
                $erros["itens.$n.quantidade"] = 'Quantidade maior que zero, com até 3 casas decimais.';
            }
            try {
                $p = Dinheiro::deTexto((string) ($item['preco'] ?? ''));
            } catch (\InvalidArgumentException) {
                $erros["itens.$n.preco"] = 'Preço inválido. Exemplo: 180,00';
            }
            if ($descricao === '' || mb_strlen($descricao) > 300) {
                $erros["itens.$n.descricao"] = 'Descreva o serviço ou peça (até 300 caracteres).';
            }
            if (! isset($erros["itens.$n.quantidade"], $erros["itens.$n.preco"]) && isset($q, $p)) {
                $linhas[] = ['descricao' => $descricao, 'quantidade' => $q, 'preco_unitario_centavos' => $p, 'total_centavos' => Dinheiro::totalItem($q, $p),
                    'catalogo_item_id' => $cat?->id, 'preco_tabela_centavos' => $cat?->precoNaUnidade($unidadeId), 'desconto_max_percentual' => $cat?->limiteDesconto()];
            }
            unset($q, $p);
        }
        if ($erros) {
            throw ValidationException::withMessages($erros);
        }
        if (! $linhas) {
            throw ValidationException::withMessages(['itens' => 'Inclua ao menos um item.']);
        }

        return $linhas;
    }

    private function chave(string $d, string $q, int $p): string
    {
        return mb_strtolower(trim($d)).'|'.bcadd($q, '0', 3).'|'.$p;
    }

    private function herdarConclusoes(Orcamento $de, Orcamento $para): void
    {
        $feitos = $de->itens()->whereNotNull('concluido_em')->get()->keyBy(fn ($i) => $this->chave($i->descricao, $i->quantidade, $i->preco_unitario_centavos));
        foreach ($para->itens as $item) {
            if ($f = $feitos->pull($this->chave($item->descricao, $item->quantidade, $item->preco_unitario_centavos))) {
                $item->update(['concluido_em' => $f->concluido_em, 'concluido_por' => $f->concluido_por]);
            }
        }
    }

    private function autorizar(User $ator, string $acao, Ordem $ordem): void
    {
        if (! $ator->can($acao, $ordem)) {
            throw new AuthorizationException('Ação não permitida para esta ordem.');
        }
    }
}
