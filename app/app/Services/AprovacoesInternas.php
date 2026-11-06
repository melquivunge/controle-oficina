<?php

namespace App\Services;

use App\Enums\Papel;
use App\Models\AprovacaoInterna;
use App\Models\Orcamento;
use App\Models\Ordem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aprovação interna de desconto acima do limite (31/10/2026).
 *
 * Desconto efetivo de cada item = 1 − (preço unitário × (subtotal − desconto geral)) / (preço base × subtotal),
 * onde preço base é o preço de tabela (item do catálogo) ou o próprio preço (item livre, que só sofre o desconto geral).
 * Excede quando o efetivo é maior que o limite do item (catálogo) ou que a regra geral (item livre). Comparação exata (bcmath).
 * Um rascunho que excede só pode ser enviado com pedido "aprovada" cuja assinatura corresponda ao conteúdo atual.
 */
class AprovacoesInternas
{
    public static function regraGeral(): string
    {
        return bcadd((string) config('oficina.desconto_max_geral_percentual', '10'), '0', 2);
    }

    /** Aprovador: `aprovar_desconto` e `ver_valores` (o mecânico nunca, mesmo com concessão). */
    public static function podeAprovar(User $user): bool
    {
        return $user->pode('aprovar_desconto') && $user->pode('ver_valores');
    }

    public static function excede(int $precoUnitario, int $precoBase, string $limite, int $subtotal, int $desconto): bool
    {
        if (! config('oficina.desconto_exige_aprovacao') || $precoBase <= 0 || $subtotal <= 0) {
            return false;
        }
        $lhs = bcmul(bcmul((string) $precoUnitario, (string) ($subtotal - $desconto)), '100');
        $rhs = bcmul(bcmul((string) $precoBase, bcsub('100', $limite, 2), 2), (string) $subtotal, 2);

        return bccomp($lhs, $rhs, 2) < 0;
    }

    public static function percentualEfetivo(int $precoUnitario, int $precoBase, int $subtotal, int $desconto): string
    {
        if ($precoBase <= 0 || $subtotal <= 0) {
            return '0.00';
        }
        $pago = bcdiv(bcmul(bcmul((string) $precoUnitario, (string) ($subtotal - $desconto)), '100'), bcmul((string) $precoBase, (string) $subtotal), 6);
        $p = bcsub('100', $pago, 6);

        return bccomp($p, '0', 6) <= 0 ? '0.00' : bcadd($p, '0.005', 2); // arredonda ao centésimo (meio para cima)
    }

    /** @return array{excede:bool, excessos:list<array{descricao:string, desconto_percentual:string, limite_percentual:string}>, maior:string, assinatura:string} */
    public function avaliar(Orcamento $orc): array
    {
        $itens = $orc->itens()->get();
        $subtotal = (int) $orc->subtotal_centavos;
        $desconto = (int) $orc->desconto_centavos;
        $excessos = [];
        $maior = '0.00';
        $regra = self::regraGeral();
        foreach ($itens as $i) {
            $base = $i->preco_tabela_centavos !== null ? (int) $i->preco_tabela_centavos : (int) $i->preco_unitario_centavos;
            $limite = $i->catalogo_item_id ? bcadd((string) $i->desconto_max_percentual, '0', 2) : $regra;
            $efetivo = self::percentualEfetivo((int) $i->preco_unitario_centavos, $base, $subtotal, $desconto);
            if (bccomp($efetivo, $maior, 2) > 0) {
                $maior = $efetivo;
            }
            if (self::excede((int) $i->preco_unitario_centavos, $base, $limite, $subtotal, $desconto)) {
                $excessos[] = ['descricao' => $i->descricao, 'desconto_percentual' => $efetivo, 'limite_percentual' => $limite];
            }
        }
        $assinatura = hash('sha256', json_encode([
            'itens' => $itens->map(fn ($i) => [$i->catalogo_item_id, $i->descricao, bcadd((string) $i->quantidade, '0', 3), (int) $i->preco_unitario_centavos,
                $i->preco_tabela_centavos === null ? null : (int) $i->preco_tabela_centavos, $i->desconto_max_percentual === null ? null : bcadd((string) $i->desconto_max_percentual, '0', 2)])->all(),
            'subtotal' => $subtotal, 'desconto' => $desconto,
        ], JSON_UNESCAPED_UNICODE));

        return ['excede' => $excessos !== [], 'excessos' => $excessos, 'maior' => $maior, 'assinatura' => $assinatura];
    }

    /**
     * Chamado ao salvar o rascunho (dentro da transação, com a ordem travada). Mantém o pedido se o conteúdo não mudou;
     * senão cancela o pedido aberto e, se ainda exceder, abre um novo pendente.
     */
    public function sincronizar(Orcamento $rascunho, User $ator, ?string $motivo): ?AprovacaoInterna
    {
        $av = $this->avaliar($rascunho);
        $ultimo = AprovacaoInterna::where('orcamento_id', $rascunho->id)->where('status', '<>', 'cancelada')->orderByDesc('id')->lockForUpdate()->first();
        if ($av['excede'] && $ultimo && $ultimo->assinatura === $av['assinatura']) {
            return $ultimo; // mesmo conteúdo: pedido (pendente/aprovado/recusado) continua valendo
        }
        $abertos = AprovacaoInterna::where('orcamento_id', $rascunho->id)->whereIn('status', ['pendente', 'aprovada'])->lockForUpdate()->get();
        foreach ($abertos as $a) {
            $a->update(['status' => 'cancelada']);
            Auditoria::registrar('desconto_aprovacao_cancelada', $a, ['status' => $a->getOriginal('status')], ['status' => 'cancelada'], 'Rascunho alterado', ator: $ator);
        }
        if (! $av['excede']) {
            return null;
        }
        $pedido = AprovacaoInterna::create([
            'orcamento_id' => $rascunho->id, 'ordem_id' => $rascunho->ordem_id, 'unidade_id' => $rascunho->unidade_id, 'versao' => $rascunho->versao,
            'status' => 'pendente', 'assinatura' => $av['assinatura'], 'subtotal_centavos' => $rascunho->subtotal_centavos,
            'desconto_centavos' => $rascunho->desconto_centavos, 'total_centavos' => $rascunho->total_centavos,
            'maior_desconto_percentual' => $av['maior'], 'excessos' => $av['excessos'], 'motivo' => $motivo,
            'solicitado_por' => $ator->id, 'solicitado_em' => now(),
        ]);
        Auditoria::registrar('desconto_aprovacao_solicitada', $pedido, depois: ['versao' => $pedido->versao, 'maior_desconto_percentual' => $av['maior'], 'excessos' => $av['excessos'], 'total_centavos' => $pedido->total_centavos], motivo: $motivo, ator: $ator);

        return $pedido;
    }

    /** @return array{exige:bool, liberado:bool, pedido:?AprovacaoInterna, avaliacao:array} */
    public function situacao(Orcamento $orc): array
    {
        $av = $this->avaliar($orc);
        $pedido = AprovacaoInterna::where('orcamento_id', $orc->id)->where('status', '<>', 'cancelada')->orderByDesc('id')->first();
        if ($pedido && $pedido->assinatura !== $av['assinatura']) {
            $pedido = null;
        }

        return ['exige' => $av['excede'], 'liberado' => ! $av['excede'] || $pedido?->status === 'aprovada', 'pedido' => $pedido, 'avaliacao' => $av];
    }

    /** Barreira do envio ao cliente (chamada com o orçamento travado). */
    public function exigirLiberacaoParaEnvio(Orcamento $orc): void
    {
        $s = $this->situacao($orc);
        if ($s['liberado']) {
            return;
        }
        $msg = match ($s['pedido']?->status) {
            'recusada' => 'O desconto deste orçamento foi recusado na aprovação interna. Ajuste os valores antes de enviar.',
            default => 'Desconto acima do limite: o orçamento só pode ser concluído depois da aprovação do desconto.',
        };
        throw ValidationException::withMessages(['itens' => $msg]);
    }

    public function decidir(AprovacaoInterna $pedido, User $ator, string $decisao, string $justificativa): AprovacaoInterna
    {
        if (! in_array($decisao, ['aprovada', 'recusada'], true)) {
            throw ValidationException::withMessages(['decisao' => 'Decisão inválida.']);
        }
        if (! self::podeAprovar($ator) || ! $ator->acessaUnidade($pedido->unidade_id)) {
            throw new AuthorizationException('Você não pode decidir pedidos de desconto desta unidade.');
        }
        if ($pedido->solicitado_por === $ator->id && $ator->papel !== Papel::Proprietario) {
            throw new AuthorizationException('Quem pediu o desconto não pode decidir o próprio pedido.');
        }
        $justificativa = trim($justificativa);
        if (mb_strlen($justificativa) < 5 || mb_strlen($justificativa) > 500) {
            throw ValidationException::withMessages(['justificativa' => 'Escreva a justificativa (5 a 500 caracteres).']);
        }

        return DB::transaction(function () use ($pedido, $ator, $decisao, $justificativa) {
            Ordem::lockForUpdate()->findOrFail($pedido->ordem_id);
            $p = AprovacaoInterna::lockForUpdate()->findOrFail($pedido->id);
            if ($p->status !== 'pendente') {
                throw ValidationException::withMessages(['decisao' => 'Este pedido já foi decidido ou cancelado. Atualize a página.']);
            }
            $orc = Orcamento::lockForUpdate()->findOrFail($p->orcamento_id);
            if ($orc->status !== 'rascunho' || $this->avaliar($orc)['assinatura'] !== $p->assinatura) {
                $p->update(['status' => 'cancelada']);
                Auditoria::registrar('desconto_aprovacao_cancelada', $p, ['status' => 'pendente'], ['status' => 'cancelada'], 'Orçamento mudou antes da decisão', ator: $ator);
                throw ValidationException::withMessages(['decisao' => 'O orçamento foi alterado depois do pedido; o pedido foi cancelado.']);
            }
            $p->update(['status' => $decisao, 'decidido_por' => $ator->id, 'decidido_em' => now(), 'justificativa' => $justificativa]);
            Auditoria::registrar($decisao === 'aprovada' ? 'desconto_aprovado' : 'desconto_recusado', $p, ['status' => 'pendente'],
                ['status' => $decisao, 'versao' => $p->versao, 'maior_desconto_percentual' => $p->maior_desconto_percentual, 'total_centavos' => $p->total_centavos],
                motivo: $justificativa, ator: $ator);

            return $p->fresh();
        });
    }

    /**
     * Pedidos pendentes que o usuário pode decidir (para o sino de notificações): vazio para quem não aprova; só unidades acessíveis;
     * exclui os pedidos do próprio usuário, salvo proprietário.
     *
     * @return Collection<int, AprovacaoInterna>
     */
    public function pendentes(User $user): Collection
    {
        if (! self::podeAprovar($user)) {
            return collect();
        }

        return AprovacaoInterna::with('ordem.veiculo', 'solicitante', 'unidade')
            ->where('status', 'pendente')
            ->when($user->papel !== Papel::Proprietario, fn ($q) => $q->where('solicitado_por', '<>', $user->id))
            ->orderBy('solicitado_em')->get()
            ->filter(fn (AprovacaoInterna $a) => $user->acessaUnidade($a->unidade_id))->values();
    }
}
