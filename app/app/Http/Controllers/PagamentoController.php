<?php

namespace App\Http\Controllers;

use App\Jobs\EnviarWebhookSimulado;
use App\Models\Cobranca;
use App\Models\Devolucao;
use App\Models\Ordem;
use App\Models\Pagamento;
use App\Services\Financeiro;
use App\Support\Dinheiro;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PagamentoController extends Controller
{
    public function __construct(private Financeiro $financeiro) {}

    public function cobrar(Request $request, Ordem $ordem)
    {
        $valor = $this->valor($request, 'valor_cobranca', false);
        $c = $this->financeiro->gerarCobranca($ordem, $request->user(), $valor);

        return back()->with($c->status === 'falha' ? 'erro_cobranca' : 'sucesso', $c->status === 'falha'
            ? 'Não foi possível gerar a cobrança no provedor. Nada foi cobrado. Tente novamente em instantes.'
            : 'Cobrança Pix gerada na conta oficial da unidade.');
    }

    /** Somente no modo simulado: dispara pagamento fictício e webhook assinado pela fila. */
    public function simular(Request $request, Ordem $ordem, Cobranca $cobranca)
    {
        abort_unless(config('oficina.integracoes') === 'simulado' && config('oficina.pix.permite_simular_pagamento') && ! app()->isProduction(), 404);
        abort_unless($cobranca->ordem_id === $ordem->id && $cobranca->txid && $request->user()->can('view', $ordem) && $request->user()->pode('cobrar'), 403);
        EnviarWebhookSimulado::dispatch($cobranca->txid, $this->valor($request, 'valor_simulado', false));

        return back()->with('sucesso', 'SIMULAÇÃO: pagamento fictício enviado ao sistema pelo "banco". Atualize em alguns segundos.');
    }

    public function manual(Request $request, Ordem $ordem)
    {
        $request->validate(['meio' => ['required', 'in:dinheiro,cartao_externo'], 'observacao' => ['nullable', 'string', 'max:300']], ['meio.in' => 'Pix só é confirmado pelo banco; não pode ser registrado manualmente.']);
        $this->financeiro->registrarManual($ordem, $request->user(), $request->input('meio'), $this->valor($request, 'valor_recebido'), $request->input('observacao'));

        return back()->with('sucesso', 'Recebimento registrado. Ele será conferido no fechamento do caixa.');
    }

    public function devolver(Request $request, Ordem $ordem, Pagamento $pagamento)
    {
        abort_unless($pagamento->ordem_id === $ordem->id, 404);
        $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);
        $d = $this->financeiro->devolver($pagamento, $request->user(), $this->valor($request, 'valor_devolucao'), $request->input('motivo'));

        return $this->respostaDevolucao($d);
    }

    public function tentarDevolucao(Request $request, Ordem $ordem, Devolucao $devolucao)
    {
        abort_unless($devolucao->ordem_id === $ordem->id, 404);

        return $this->respostaDevolucao($this->financeiro->tentarDevolucaoNovamente($devolucao, $request->user()));
    }

    private function respostaDevolucao(Devolucao $d)
    {
        return match ($d->status) {
            'falhou' => back()->with('erro_cobranca', 'A devolução Pix não foi feita: '.$d->mensagem.' Nenhum valor saiu da conta. Tente de novo ou devolva por outro meio.'),
            'processando' => back()->with('sucesso', 'Devolução Pix solicitada ao provedor. Aguardando confirmação.'),
            default => back()->with('sucesso', 'Devolução registrada.'),
        };
    }

    public function aceitar(Request $request, Ordem $ordem, Pagamento $pagamento)
    {
        abort_unless($pagamento->ordem_id === $ordem->id, 404);
        $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);
        $this->financeiro->aceitarRevisao($pagamento, $request->user(), $request->input('motivo'));

        return back()->with('sucesso', 'Divergência resolvida e registrada.');
    }

    public function entregar(Request $request, Ordem $ordem)
    {
        $request->validate(['motivo' => ['nullable', 'string', 'max:500']]);
        $this->financeiro->entregar($ordem, $request->user(), $request->input('motivo'));

        return back()->with('sucesso', 'Entrega registrada.');
    }

    private function valor(Request $request, string $campo, bool $obrigatorio = true): ?int
    {
        $texto = trim((string) $request->input($campo));
        if ($texto === '' && ! $obrigatorio) {
            return null;
        }
        try {
            return Dinheiro::deTexto($texto);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([$campo => 'Informe um valor válido. Exemplo: 150,00']);
        }
    }
}
