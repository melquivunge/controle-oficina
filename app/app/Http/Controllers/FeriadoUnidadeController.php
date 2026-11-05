<?php

namespace App\Http\Controllers;

use App\Models\FeriadoUnidade;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Feriados estaduais/municipais por unidade. Proprietário (todas) ou supervisor (suas unidades); conferido no servidor. */
class FeriadoUnidadeController extends Controller
{
    public static function podeGerir(User $user): bool
    {
        return $user->pode('configurar') || $user->pode('gerir_feriados');
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless(self::podeGerir($user), 403);
        $unidades = Unidade::visiveisPara($user)->where('ativa', true)->orderBy('nome')->get();
        if ($unidades->isEmpty()) {
            return $this->semUnidade('Feriados das unidades');
        }
        $unidadeId = $request->integer('unidade') ?: ($unidades->contains('id', $user->unidade_id) ? $user->unidade_id : $unidades->first()?->id);
        abort_unless($unidadeId && $user->acessaUnidade((int) $unidadeId), 403);

        return view('feriados.index', [
            'unidades' => $unidades, 'unidadeId' => (int) $unidadeId,
            'feriados' => FeriadoUnidade::where('unidade_id', $unidadeId)->orderBy('data')->get(),
            'nacionais' => (array) config('oficina.feriados', []),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless(self::podeGerir($user), 403);
        $dados = $request->validate([
            'unidade_id' => ['required', 'integer'],
            'data' => ['required', 'date_format:Y-m-d', Rule::unique('feriados_unidade')->where('unidade_id', (int) $request->input('unidade_id'))],
            'descricao' => ['required', 'string', 'min:3', 'max:120'],
        ], ['data.unique' => 'Esta data já está cadastrada como feriado da unidade.'], ['data' => 'data', 'descricao' => 'descrição']);
        $unidadeId = (int) $dados['unidade_id'];
        abort_unless($user->acessaUnidade($unidadeId) && Unidade::whereKey($unidadeId)->where('ativa', true)->exists(), 403);

        DB::transaction(function () use ($dados, $unidadeId, $user) {
            $f = FeriadoUnidade::create(['unidade_id' => $unidadeId, 'data' => $dados['data'], 'descricao' => trim($dados['descricao']), 'criado_por' => $user->id]);
            Auditoria::registrar('feriado_unidade_criado', $f, depois: ['data' => $dados['data'], 'descricao' => $f->descricao], unidadeId: $unidadeId, ator: $user);
        });

        return redirect()->route('feriados.index', ['unidade' => $unidadeId])->with('sucesso', 'Feriado cadastrado. Prazos novos já consideram esta data.');
    }

    public function destroy(Request $request, FeriadoUnidade $feriado)
    {
        $user = $request->user();
        abort_unless(self::podeGerir($user) && $user->acessaUnidade($feriado->unidade_id), 403);
        DB::transaction(function () use ($feriado, $user) {
            Auditoria::registrar('feriado_unidade_removido', $feriado, ['data' => $feriado->data->format('Y-m-d'), 'descricao' => $feriado->descricao],
                unidadeId: $feriado->unidade_id, ator: $user);
            $feriado->delete();
        });

        return redirect()->route('feriados.index', ['unidade' => $feriado->unidade_id])->with('sucesso', 'Feriado removido. Prazos já definidos não mudam.');
    }
}
