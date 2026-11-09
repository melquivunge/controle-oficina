<?php

namespace App\Http\Controllers;

use App\Models\Cobranca;
use App\Models\Ordem;
use App\Services\Combinados;
use App\Services\Financeiro;
use App\Services\PagamentoDividido;
use App\Support\Dinheiro;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Aba "Pagamentos e entrega" guiada por "Como o cliente vai pagar?". Só traduz o formulário;
 * permissão, unidade, limites e auditoria ficam em Financeiro, Combinados e PagamentoDividido.
 */
class PagamentoGuiadoController extends Controller
{
    /** Comprovante anexado ao pagamento (privado): quem recebe pagamentos na unidade. */
    public function comprovante(Request $request, Ordem $ordem, \App\Models\Pagamento $pagamento)
    {
        abort_unless($pagamento->ordem_id === $ordem->id && $pagamento->comprovante_caminho, 404);
        abort_unless($request->user()->pode('receber_manual') && $request->user()->acessaUnidade($ordem->unidade_id), 403);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($pagamento->comprovante_caminho, null,
            ['Content-Type' => $pagamento->comprovante_mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'"]);
    }

    /** Tudo agora (valor livre, pode ser parcial): Pix gera cobrança; dinheiro/cartão registra. */
    public function agora(Request $request, Ordem $ordem, Financeiro $financeiro)
    {
        $request->validate(['forma_agora' => ['required', 'in:pix,pix_manual,dinheiro,cartao_externo'], 'observacao' => ['nullable', 'string', 'max:300'],
            'comprovante' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:8192']],
            ['forma_agora.required' => 'Escolha a forma de pagamento.', 'forma_agora.in' => 'Escolha Pix, dinheiro ou cartão.',
                'comprovante.mimetypes' => 'Comprovante deve ser foto (JPG, PNG, WEBP) ou PDF.', 'comprovante.max' => 'Comprovante de até 8 MB.']);
        $valor = $this->valor($request, 'valor_agora');
        $forma = $request->input('forma_agora');
        $r = $this->remapear(fn () => $forma === 'pix'
            ? $financeiro->gerarCobranca($ordem, $request->user(), $valor)
            : $financeiro->registrarManual($ordem, $request->user(), $forma, $valor, $request->input('observacao'), $request->file('comprovante'), $request->boolean('conferido_extrato')));

        return $this->resposta($r, 'Recebimento registrado. Ele será conferido no fechamento do caixa.');
    }

    /** Parte agora, parte depois: combinado (parcela 1 hoje + futuras) e o recebimento/cobrança da parcela 1, num envio. */
    public function dividir(Request $request, Ordem $ordem, PagamentoDividido $servico, Financeiro $financeiro)
    {
        $request->validate([
            'forma_agora' => ['required', 'in:pix,dinheiro,cartao_externo'],
            'observacao' => ['nullable', 'string', 'max:300'],
            'futuras' => ['required', 'array', 'max:'.PagamentoDividido::MAX_FUTURAS],
            'futuras.*.valor' => ['nullable', 'string', 'max:20'],
            'futuras.*.vencimento' => ['nullable', 'string', 'max:10'],
            'futuras.*.meio' => ['nullable', 'in:pix,dinheiro,cartao_externo'],
        ], ['forma_agora.required' => 'Escolha como o cliente paga agora.', 'forma_agora.in' => 'Escolha Pix, dinheiro ou cartão.', 'futuras.required' => 'Informe a data do restante.']);
        $agora = $this->valor($request, 'valor_agora');
        $linhas = array_values(array_filter($request->input('futuras', []), fn ($l) => trim((string) ($l['valor'] ?? '')) !== '' || trim((string) ($l['vencimento'] ?? '')) !== ''));
        if ($linhas === []) {
            throw ValidationException::withMessages(['futuras' => 'Informe a data em que o cliente paga o restante.']);
        }
        // Uma única data sem valor: o restante é calculado (saldo − valor de agora). Conferido de novo sob lock no serviço.
        if (count($linhas) === 1 && trim((string) ($linhas[0]['valor'] ?? '')) === '') {
            $linhas[0]['valor'] = Dinheiro::paraCampo(max(0, $financeiro->resumo($ordem)['saldo'] - $agora));
        }
        $futuras = [];
        foreach ($linhas as $i => $l) {
            try {
                $centavos = Dinheiro::deTexto(trim((string) ($l['valor'] ?? '')));
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['futuras' => 'Data '.($i + 1).' do restante: informe um valor válido. Exemplo: 500,00']);
            }
            $futuras[] = ['valor_centavos' => $centavos, 'vencimento' => trim((string) ($l['vencimento'] ?? '')), 'meio_previsto' => ($l['meio'] ?? null) ?: null];
        }
        $r = $this->remapear(fn () => $servico->registrar($ordem, $request->user(), $agora, $request->input('forma_agora'), $futuras, $request->input('observacao')));

        return $this->resposta($r, 'Combinado registrado e pagamento de agora recebido. O restante fica pendente com data; o caixa conta só o que entrou.');
    }

    private function resposta($r, string $okManual)
    {
        if ($r instanceof Cobranca) {
            return back()->with($r->status === 'falha' ? 'erro_cobranca' : 'sucesso', $r->status === 'falha'
                ? 'Não foi possível gerar a cobrança Pix no provedor. Nada foi cobrado. Tente gerar o Pix de novo em instantes.'
                : 'Cobrança Pix gerada. Ela só conta como recebida quando o banco confirmar o pagamento.');
        }

        return back()->with('sucesso', $okManual);
    }

    /** Erros dos serviços aparecem junto do campo correspondente do formulário guiado. */
    private function remapear(callable $acao)
    {
        try {
            return $acao();
        } catch (ValidationException $e) {
            $mapa = ['valor' => 'valor_agora', 'meio' => 'forma_agora', 'cobranca' => 'forma_agora', 'parcelas' => 'futuras', 'motivo_combinado' => 'futuras'];
            $msgs = [];
            foreach ($e->errors() as $campo => $lista) {
                $msgs[$mapa[$campo] ?? $campo] = $lista;
            }
            throw ValidationException::withMessages($msgs);
        }
    }

    private function valor(Request $request, string $campo): int
    {
        try {
            return Dinheiro::deTexto(trim((string) $request->input($campo)));
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([$campo => 'Informe um valor válido. Exemplo: 150,00']);
        }
    }
}
