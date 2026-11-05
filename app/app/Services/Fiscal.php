<?php

namespace App\Services;

use App\Enums\StatusOrdem;
use App\Integracoes\Fiscal\EmissorFiscal;
use App\Jobs\ProcessarDocumentoFiscal;
use App\Models\FiscalDocument;
use App\Models\Orcamento;
use App\Models\Ordem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Ciclo fiscal independente do pagamento. Emissão com chave idempotente e consulta prévia. */
class Fiscal
{
    public function __construct(private EmissorFiscal $emissor) {}

    public function solicitar(Ordem $ordem, User $ator): FiscalDocument
    {
        if (! $ator->pode('cobrar') || ! $ator->acessaUnidade($ordem->unidade_id)) {
            throw new AuthorizationException('Sem permissão para solicitar documento fiscal.');
        }

        return DB::transaction(function () use ($ordem, $ator) {
            $o = Ordem::lockForUpdate()->findOrFail($ordem->id);
            $orc = Orcamento::where('ordem_id', $o->id)->where('status', 'aprovado')->first();
            if (! $orc || $o->status === StatusOrdem::Cancelado) {
                throw ValidationException::withMessages(['fiscal' => 'É preciso um orçamento aprovado em ordem não cancelada.']);
            }
            if (FiscalDocument::where('ordem_id', $o->id)->whereIn('status', ['pendente', 'processando', 'autorizado'])->exists()) {
                throw ValidationException::withMessages(['fiscal' => 'Já existe documento fiscal ativo para esta ordem.']);
            }
            $tentativa = FiscalDocument::where('ordem_id', $o->id)->count() + 1;
            $doc = FiscalDocument::create(['ordem_id' => $o->id, 'unidade_id' => $o->unidade_id, 'orcamento_id' => $orc->id, 'valor_centavos' => $orc->total_centavos,
                'status' => 'pendente', 'provedor' => $this->emissor->nome(), 'solicitado_por' => $ator->id,
                'chave_idempotencia' => hash('sha256', "{$o->id}|{$orc->id}|{$tentativa}")]);
            Auditoria::registrar('fiscal_solicitado', $o, depois: ['documento_id' => $doc->id, 'valor_centavos' => $doc->valor_centavos], ator: $ator);
            ProcessarDocumentoFiscal::dispatch($doc->id)->afterCommit();

            return $doc;
        });
    }

    /** Executado pelo worker. Sem chave repetida: consulta antes de emitir. */
    public function processar(int $documentoId): void
    {
        $doc = DB::transaction(function () use ($documentoId) {
            $d = FiscalDocument::lockForUpdate()->find($documentoId);
            if (! $d || ! in_array($d->status, ['pendente', 'processando'], true)) {
                return null;
            }
            $d->update(['status' => 'processando', 'tentativas' => $d->tentativas + 1]);

            return $d;
        });
        if (! $doc) {
            return;
        }
        $ordem = Ordem::with('cliente', 'unidade')->findOrFail($doc->ordem_id);
        try {
            $r = $this->emissor->consultar($doc->chave_idempotencia)
                ?? $this->emissor->emitir($doc->chave_idempotencia, [
                    'valor_centavos' => $doc->valor_centavos, 'ordem' => $ordem->numero, 'unidade' => $ordem->unidade->nome, 'cliente' => $ordem->cliente->nome,
                    'cliente_endereco' => $ordem->cliente->enderecoTexto(), 'cliente_telefone' => $ordem->cliente->telefoneFormatado(), 'cliente_email' => $ordem->cliente->email,
                    'itens' => Orcamento::findOrFail($doc->orcamento_id)->itens->map->only('descricao', 'quantidade', 'preco_unitario_centavos', 'total_centavos')->all(),
                ]);
        } catch (\Throwable $e) {
            $doc->update(['status' => 'pendente', 'mensagem' => mb_substr($e->getMessage(), 0, 300)]); // visível e recuperável
            throw $e;
        }
        $arquivo = null;
        if (! empty($r['documento'])) {
            $arquivo = "fiscal/{$doc->unidade_id}/{$doc->id}-{$r['numero']}.html";
            Storage::disk('local')->put($arquivo, $r['documento']);
        }
        $doc->update(['status' => $r['status'], 'numero' => $r['numero'] ?? $doc->numero, 'protocolo' => $r['protocolo'] ?? $doc->protocolo,
            'mensagem' => $r['mensagem'], 'arquivo' => $arquivo ?? $doc->arquivo, 'autorizado_em' => $r['status'] === 'autorizado' ? now() : null]);
        Auditoria::registrar('fiscal_'.$r['status'], $ordem, depois: ['documento_id' => $doc->id, 'numero' => $r['numero'] ?? null], motivo: $r['mensagem'], atorTipo: 'sistema');
    }

    /** Rejeitado: nova tentativa cria novo registro (nova chave); o rejeitado é preservado. */
    public function reprocessar(FiscalDocument $doc, User $ator): FiscalDocument
    {
        if ($doc->status === 'pendente') {
            if (! $ator->pode('cobrar') || ! $ator->acessaUnidade($doc->unidade_id)) {
                throw new AuthorizationException('Sem permissão.');
            }
            ProcessarDocumentoFiscal::dispatch($doc->id);

            return $doc;
        }
        if ($doc->status !== 'rejeitado') {
            throw ValidationException::withMessages(['fiscal' => 'Somente documentos pendentes ou rejeitados podem ser reprocessados.']);
        }

        return $this->solicitar(Ordem::findOrFail($doc->ordem_id), $ator);
    }

    public function cancelar(FiscalDocument $doc, User $ator, string $motivo): void
    {
        if (! $ator->pode('cancelar_ordem') || ! $ator->acessaUnidade($doc->unidade_id)) {
            throw new AuthorizationException('Cancelamento fiscal exige gerente.');
        }
        DB::transaction(function () use ($doc, $ator, $motivo) {
            $d = FiscalDocument::lockForUpdate()->findOrFail($doc->id);
            if ($d->status !== 'autorizado') {
                throw ValidationException::withMessages(['fiscal' => 'Somente documento autorizado pode ser cancelado.']);
            }
            $this->emissor->cancelar($d->chave_idempotencia, $motivo);
            $d->update(['status' => 'cancelado', 'cancelado_em' => now(), 'cancelamento_motivo' => $motivo]);
            Auditoria::registrar('fiscal_cancelado', Ordem::find($d->ordem_id), depois: ['documento_id' => $d->id, 'numero' => $d->numero], motivo: $motivo, ator: $ator);
        });
    }
}
