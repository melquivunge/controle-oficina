<?php

namespace App\Services;

use App\Enums\StatusOrdem;
use App\Integracoes\Fiscal\EmissorFiscal;
use App\Integracoes\Fiscal\EmissorReal;
use App\Integracoes\Fiscal\NfseNacional;
use App\Jobs\ProcessarDocumentoFiscal;
use App\Models\FiscalDocument;
use App\Models\Orcamento;
use App\Models\Ordem;
use App\Models\User;
use App\Support\DadosFiscais;
use App\Support\Danfse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Ciclo fiscal independente do pagamento. Emissão com chave idempotente e consulta prévia. */
class Fiscal
{
    public function __construct(private EmissorFiscal $emissor) {}

    /**
     * Emite as notas que faltam (09/11/2026): NFS-e para os serviços e NF-e para as peças/produtos do orçamento aprovado.
     * Com $tipo, só aquela. Cada tipo tem no máximo uma nota ativa por ordem (índice único no banco).
     *
     * @return Collection<int, FiscalDocument> notas criadas
     */
    public function solicitar(Ordem $ordem, User $ator, ?string $tipo = null): Collection
    {
        if (! $ator->pode('cobrar') || ! $ator->acessaUnidade($ordem->unidade_id)) {
            throw new AuthorizationException('Sem permissão para solicitar documento fiscal.');
        }

        return DB::transaction(function () use ($ordem, $ator, $tipo) {
            $o = Ordem::lockForUpdate()->findOrFail($ordem->id);
            $orc = Orcamento::where('ordem_id', $o->id)->where('status', 'aprovado')->first();
            if (! $orc || $o->status === StatusOrdem::Cancelado) {
                throw ValidationException::withMessages(['fiscal' => 'É preciso um orçamento aprovado em ordem não cancelada.']);
            }
            $ativos = FiscalDocument::where('ordem_id', $o->id)->whereIn('status', ['pendente', 'processando', 'autorizado'])->pluck('tipo')->all();
            $partes = array_filter(DadosFiscais::partes($orc->itens, (int) $orc->desconto_centavos), fn ($v) => $v > 0);
            $faltam = array_diff_key($partes, array_flip($ativos));
            if ($tipo !== null) {
                $faltam = array_intersect_key($faltam, [$tipo => true]);
            }
            if (! $faltam) {
                throw ValidationException::withMessages(['fiscal' => 'As notas desta ordem já foram emitidas (ou estão em emissão).']);
            }
            $criados = collect();
            foreach ($faltam as $t => $valor) {
                $tentativa = FiscalDocument::where('ordem_id', $o->id)->where('tipo', $t)->count() + 1;
                $doc = FiscalDocument::create(['ordem_id' => $o->id, 'unidade_id' => $o->unidade_id, 'orcamento_id' => $orc->id, 'tipo' => $t, 'valor_centavos' => $valor,
                    'status' => 'pendente', 'provedor' => $this->emissor->nome(), 'solicitado_por' => $ator->id,
                    // NFS-e mantém a chave anterior (compatível com notas já emitidas); NF-e tem a sua.
                    'chave_idempotencia' => hash('sha256', $t === 'nfse' ? "{$o->id}|{$orc->id}|{$tentativa}" : "{$o->id}|{$orc->id}|nfe|{$tentativa}")]);
                Auditoria::registrar('fiscal_solicitado', $o, depois: ['documento_id' => $doc->id, 'tipo' => $t, 'valor_centavos' => $doc->valor_centavos], ator: $ator);
                ProcessarDocumentoFiscal::dispatch($doc->id)->afterCommit();
                $criados->push($doc);
            }

            return $criados;
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
        $fiscal = DadosFiscais::atuais();
        try {
            if ($this->emissor instanceof EmissorReal) {
                $doc = $this->numerar($doc, $this->emissor->ambiente(), $fiscal);
            }
            $dados = $this->dados($doc, $ordem, $fiscal);
            $r = $this->emissor->consultar($doc->chave_idempotencia, $dados) ?? $this->emissor->emitir($doc->chave_idempotencia, $dados);
        } catch (\Throwable $e) {
            $doc->update(['status' => 'pendente', 'mensagem' => mb_substr($e->getMessage(), 0, 300)]); // visível e recuperável
            throw $e;
        }
        $this->registrarResultado($doc, $ordem, $r);
    }

    /**
     * Interrompe nota pendente: a fila para de tentar e ela vira rejeitada (o número volta e "Tentar de novo" continua possível).
     * NFS-e já numerada consulta a Sefin antes: se a nota existe, registra como autorizada em vez de abandonar.
     * ponytail: NF-e não consulta (falta o XML assinado para autorizar); resposta perdida reaparece como duplicidade na nova tentativa.
     *
     * @return bool true se interrompeu; false se a Fazenda já tinha a nota
     */
    public function interromper(FiscalDocument $doc, User $ator): bool
    {
        if (! $ator->pode('cobrar') || ! $ator->acessaUnidade($doc->unidade_id)) {
            throw new AuthorizationException('Sem permissão.');
        }
        $interrompivel = fn (FiscalDocument $d) => $d->status === 'pendente' || ($d->status === 'processando' && $d->updated_at->lt(now()->subMinutes(5)));
        if (! $interrompivel($doc)) {
            throw ValidationException::withMessages(['fiscal' => 'Esta nota não está mais aguardando emissão.']);
        }
        $ordem = Ordem::with('cliente', 'unidade')->findOrFail($doc->ordem_id);
        if ($this->emissor instanceof EmissorReal && $doc->tipo === 'nfse' && $doc->numero_dps) {
            try {
                $r = $this->emissor->consultar($doc->chave_idempotencia, $this->dados($doc, $ordem, DadosFiscais::atuais()));
            } catch (\Throwable) {
                throw ValidationException::withMessages(['fiscal' => 'Não consegui confirmar com a Fazenda se esta nota já existe. Tente interromper de novo em instantes.']);
            }
            if ($r) {
                $this->registrarResultado($doc, $ordem, $r);

                return false;
            }
        }
        DB::transaction(function () use ($doc, $ator, $ordem, $interrompivel) {
            $d = FiscalDocument::lockForUpdate()->findOrFail($doc->id);
            if (! $interrompivel($d)) {
                throw ValidationException::withMessages(['fiscal' => 'Esta nota não está mais aguardando emissão.']);
            }
            $d->update(['status' => 'rejeitado', 'mensagem' => 'Emissão interrompida por '.$ator->name.'.']);
            Auditoria::registrar('fiscal_interrompido', $ordem, depois: ['documento_id' => $d->id, 'tipo' => $d->tipo], ator: $ator);
        });

        return true;
    }

    /** Busca de novo o PDF oficial da NFS-e autorizada que veio sem ele. */
    public function buscarPdf(FiscalDocument $doc): bool
    {
        if ($doc->tipo !== 'nfse' || $doc->status !== 'autorizado' || ! $doc->chave_acesso || ! $this->emissor instanceof EmissorReal) {
            return false;
        }
        $pdf = (new NfseNacional((int) $doc->ambiente))->danfse($doc->chave_acesso)
            ?? ($doc->xml ? Danfse::gerar((string) Storage::disk('local')->get($doc->xml)) : null);
        if (! $pdf) {
            return false;
        }
        $arquivo = "fiscal/{$doc->unidade_id}/{$doc->id}-".preg_replace('/\W/', '', (string) $doc->numero).'.pdf';
        Storage::disk('local')->put($arquivo, $pdf);
        $doc->update(['arquivo' => $arquivo]);

        return true;
    }

    private function dados(FiscalDocument $doc, Ordem $ordem, array $fiscal): array
    {
        return [
            'tipo' => $doc->tipo, 'fiscal' => $fiscal, 'serie' => $doc->serie, 'numero_dps' => $doc->numero_dps, 'criado_em' => $doc->created_at,
            'valor_centavos' => $doc->valor_centavos, 'ordem' => $ordem->numero, 'unidade' => $ordem->unidade->nome, 'cliente' => $ordem->cliente->nome,
            'cliente_documento' => $ordem->cliente->documento,
            'cliente_local' => $ordem->cliente->only('logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'cep'),
            'cliente_endereco' => $ordem->cliente->enderecoTexto(), 'cliente_telefone' => $ordem->cliente->telefoneFormatado(), 'cliente_email' => $ordem->cliente->email,
            'itens' => Orcamento::findOrFail($doc->orcamento_id)->itens()->with('catalogoItem')->get()->where('tipo', $doc->tipo === 'nfe' ? 'produto' : 'servico')
                ->map(fn ($i) => $i->only('descricao', 'quantidade', 'preco_unitario_centavos', 'total_centavos', 'tipo')
                    + ['ncm' => $i->catalogoItem?->ncm, 'codigo' => $i->catalogo_item_id])->values()->all(),
        ];
    }

    private function registrarResultado(FiscalDocument $doc, Ordem $ordem, array $r): void
    {
        $arquivo = null;
        $base = "fiscal/{$doc->unidade_id}/{$doc->id}-".preg_replace('/\W/', '', (string) ($r['numero'] ?? 'sem-numero'));
        if (! empty($r['documento'])) {
            $arquivo = $base.'.'.($r['extensao'] ?? 'html');
            Storage::disk('local')->put($arquivo, $r['documento']);
        }
        if (! empty($r['xml'])) {
            Storage::disk('local')->put($base.'.xml', $r['xml']);
        }
        $doc->update(['status' => $r['status'], 'numero' => $r['numero'] ?? $doc->numero, 'protocolo' => $r['protocolo'] ?? $doc->protocolo,
            'chave_acesso' => $r['chave_acesso'] ?? $doc->chave_acesso, 'xml' => ! empty($r['xml']) ? $base.'.xml' : $doc->xml,
            'mensagem' => $r['mensagem'], 'arquivo' => $arquivo ?? $doc->arquivo, 'autorizado_em' => $r['status'] === 'autorizado' ? now() : null]);
        Auditoria::registrar('fiscal_'.$r['status'], $ordem, depois: ['documento_id' => $doc->id, 'numero' => $r['numero'] ?? null], motivo: $r['mensagem'], atorTipo: 'sistema');
    }

    /**
     * Fixa série e número antes do envio (o mesmo número em toda nova tentativa = sem nota duplicada).
     * Rejeitada não consome número: a nova tentativa da mesma ordem reaproveita o número da rejeitada.
     * Produção começa no "próximo número" configurado (continua a numeração do sistema antigo).
     */
    private function numerar(FiscalDocument $doc, int $ambiente, array $fiscal): FiscalDocument
    {
        if ($doc->numero_dps) {
            return $doc;
        }
        $serie = (string) (int) $fiscal[$doc->tipo === 'nfe' ? 'nfe_serie' : 'nfse_serie'];
        $inicial = $ambiente !== 1 ? 1 : (int) $fiscal[$doc->tipo === 'nfe' ? 'nfe_proximo_numero' : 'nfse_proximo_numero'];
        if ($ambiente === 1 && $inicial < 1) {
            throw new \RuntimeException('Informe o próximo número da '.($doc->tipo === 'nfe' ? 'NF-e' : 'NFS-e').' em Configurações → Dados fiscais antes de emitir em produção.');
        }

        return DB::transaction(function () use ($doc, $ambiente, $serie, $inicial) {
            $d = FiscalDocument::lockForUpdate()->findOrFail($doc->id);
            $rejeitada = FiscalDocument::where('ordem_id', $d->ordem_id)->where('tipo', $d->tipo)->where('ambiente', $ambiente)->where('serie', $serie)
                ->where('status', 'rejeitado')->whereNotNull('numero_dps')
                ->whereRaw("coalesce(mensagem, '') !~ '^(110|205|301|302|303) '") // denegada consome o número na SEFAZ
                ->lockForUpdate()->first();
            if ($rejeitada) {
                $numero = $rejeitada->numero_dps;
                $rejeitada->update(['numero_dps' => null]);
            } else {
                $numero = DB::selectOne('INSERT INTO fiscal_numeracao (tipo, ambiente, serie, ultimo) VALUES (?, ?, ?, ?)
                    ON CONFLICT (tipo, ambiente, serie) DO UPDATE SET ultimo = GREATEST(fiscal_numeracao.ultimo + 1, EXCLUDED.ultimo) RETURNING ultimo',
                    [$d->tipo, $ambiente, $serie, max($inicial, 1)])->ultimo;
            }
            $d->update(['ambiente' => $ambiente, 'serie' => $serie, 'numero_dps' => $numero]);

            return $d;
        });
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

        return $this->solicitar(Ordem::findOrFail($doc->ordem_id), $ator, $doc->tipo)->first(); // rejeitada: refaz só a mesma nota
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
            try {
                $this->emissor->cancelar($d->chave_idempotencia, $motivo, ['tipo' => $d->tipo, 'chave_acesso' => $d->chave_acesso, 'protocolo' => $d->protocolo]);
            } catch (\RuntimeException $e) { // recusa ou Fazenda fora do ar: a nota segue autorizada e o motivo aparece na OS
                throw ValidationException::withMessages(['fiscal' => mb_substr($e->getMessage(), 0, 300)]);
            }
            $d->update(['status' => 'cancelado', 'cancelado_em' => now(), 'cancelamento_motivo' => $motivo]);
            Auditoria::registrar('fiscal_cancelado', Ordem::find($d->ordem_id), depois: ['documento_id' => $d->id, 'numero' => $d->numero], motivo: $motivo, ator: $ator);
        });
    }
}
