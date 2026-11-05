<?php

namespace App\Http\Controllers;

use App\Models\Ordem;
use App\Services\Combinados;
use App\Support\Dinheiro;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Combinado de pagamento do saldo. Regras, permissão e unidade são verificadas em App\Services\Combinados. */
class CombinadoController extends Controller
{
    public function __construct(private Combinados $combinados) {}

    public function registrar(Request $request, Ordem $ordem)
    {
        $request->validate([
            'parcelas' => ['required', 'array', 'max:'.Combinados::MAX_PARCELAS],
            'parcelas.*.valor' => ['nullable', 'string', 'max:20'],
            'parcelas.*.vencimento' => ['nullable', 'string', 'max:10'],
            'parcelas.*.meio' => ['nullable', 'string', 'max:20'],
            'parcelas.*.observacao' => ['nullable', 'string', 'max:300'],
            'motivo_combinado' => ['nullable', 'string', 'max:500'],
        ]);
        $parcelas = [];
        foreach ($request->input('parcelas', []) as $i => $linha) {
            $valor = trim((string) ($linha['valor'] ?? ''));
            $venc = trim((string) ($linha['vencimento'] ?? ''));
            if ($valor === '' && $venc === '') {
                continue; // linha em branco do formulário
            }
            try {
                $centavos = Dinheiro::deTexto($valor);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['parcelas' => 'Parcela '.(count($parcelas) + 1).': informe um valor válido. Exemplo: 500,00']);
            }
            $parcelas[] = ['valor_centavos' => $centavos, 'vencimento' => $venc, 'meio_previsto' => $linha['meio'] ?? null, 'observacao' => $linha['observacao'] ?? null];
        }
        $existia = $this->combinados->vigente($ordem) !== null;
        $this->combinados->registrar($ordem, $request->user(), $parcelas, $request->input('motivo_combinado'));

        return back()->with('sucesso', $existia ? 'Combinado de pagamento ajustado.' : 'Combinado de pagamento registrado.');
    }

    public function cancelar(Request $request, Ordem $ordem)
    {
        $request->validate(['motivo_combinado' => ['required', 'string', 'min:5', 'max:500']]);
        $this->combinados->cancelar($ordem, $request->user(), $request->input('motivo_combinado'));

        return back()->with('sucesso', 'Combinado de pagamento cancelado.');
    }
}
