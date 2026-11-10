<?php

namespace App\Http\Controllers;

use App\Integracoes\Placa\ConsultaPlaca;
use App\Integracoes\Placa\SimuladorPlaca;
use App\Models\Veiculo;
use App\Support\Normaliza;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Consulta da placa para formulários sem Livewire (cadastro pela Central e Novo cliente). Mesmas regras da Nova entrada:
 * placa já cadastrada não consulta fora; limite de 20 consultas por minuto por usuário; falha vira aviso, nunca erro 500.
 */
class ConsultaPlacaController extends Controller
{
    public function __invoke(Request $request, ConsultaPlaca $consulta)
    {
        $placa = Normaliza::placa((string) $request->input('placa'));
        if (! preg_match(Normaliza::REGEX_PLACA, $placa)) {
            return response()->json(['aviso' => 'Digite a placa completa (ex.: ABC1D23).'], 422);
        }
        if (Veiculo::where('placa', $placa)->exists()) {
            return response()->json(['aviso' => 'Esta placa já está cadastrada. Use "Vincular cliente" e busque pela placa.']);
        }
        if (! RateLimiter::attempt('consulta-placa:'.$request->user()->id, 20, fn () => true)) {
            return response()->json(['aviso' => 'Muitas consultas seguidas. Aguarde um minuto ou preencha os dados à mão.'], 429);
        }
        try {
            $d = $consulta->consultar($placa);
        } catch (\Throwable $e) {
            report($e);
            $d = null;
        }
        if (! $d) {
            return response()->json(['aviso' => 'Não encontramos dados desta placa agora. Preencha marca, modelo, ano e cor à mão.']);
        }

        return response()->json(['dados' => [
            'marca' => mb_substr((string) ($d['marca'] ?? ''), 0, 40), 'modelo' => mb_substr((string) ($d['modelo'] ?? ''), 0, 80),
            'ano' => empty($d['ano']) ? '' : (string) (int) $d['ano'], 'cor' => mb_substr((string) ($d['cor'] ?? ''), 0, 30),
        ], 'aviso' => 'Marca, modelo, ano e cor preenchidos pela consulta da placa. Confira com o veículo.'
            .($consulta instanceof SimuladorPlaca ? ' SIMULAÇÃO: dados fictícios, não vêm do DETRAN.' : '')]);
    }
}
