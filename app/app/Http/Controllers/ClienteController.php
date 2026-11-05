<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Ordem;
use App\Services\Auditoria;
use App\Support\Normaliza;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Cadastro único da rede; o histórico exibido respeita as unidades visíveis ao usuário. */
class ClienteController extends Controller
{
    public function index(Request $request)
    {
        $busca = trim((string) ($request->validate(['busca' => ['nullable', 'string', 'max:100']])['busca'] ?? ''));
        $user = $request->user();
        $visiveis = fn ($q) => $q->visiveisPara($user);
        $clientes = Cliente::ativos()->with('veiculos')
            ->withCount(['ordens' => $visiveis])->withMax(['ordens' => $visiveis], 'entrada_em')
            // Sem busca: só quem já foi atendido nas unidades do usuário. Com busca: cadastro da rede (evita duplicar na entrada).
            ->when($busca === '', fn ($q) => $q->whereHas('ordens', $visiveis)->orderByDesc('ordens_max_entrada_em'), fn ($q) => $q->buscar($busca)->orderBy('nome'))
            ->paginate(24)->withQueryString();

        return view('clientes.index', compact('clientes', 'busca'));
    }

    public function show(Request $request, Cliente $cliente)
    {
        if ($cliente->mesclado_em_id) {
            return redirect()->route('clientes.show', $cliente->mesclado_em_id)->with('sucesso', 'Este cadastro foi unificado com o atual.');
        }
        $cliente->load('veiculos');

        return view('clientes.show', [
            'cliente' => $cliente,
            'ordens' => Ordem::visiveisPara($request->user())->with('veiculo', 'unidade')->where('cliente_id', $cliente->id)->orderByDesc('entrada_em')->limit(100)->get(),
            'possiveisDuplicados' => Cliente::ativos()->whereKeyNot($cliente->id)->where('telefone', $cliente->telefone)->get(),
        ]);
    }

    public function update(Request $request, Cliente $cliente)
    {
        abort_if($cliente->mesclado_em_id, 404);
        // Cadastro é da rede, mas a alteração (ex.: telefone que recebe links) cabe a quem atende o cliente.
        abort_unless($request->user()->visaoGlobal() || Ordem::visiveisPara($request->user())->where('cliente_id', $cliente->id)->exists(), 403);
        $request->merge(['telefone' => Normaliza::telefone($request->input('telefone'))]);
        // Endereço só entra para quem vê contato do cliente (mecânico nunca); sem a permissão, os campos são ignorados.
        $comEndereco = $request->user()->can('ver_contato_cliente') && $request->hasAny(\App\Support\Endereco::CAMPOS);
        if ($comEndereco) {
            $request->merge(\App\Support\Endereco::normalizar($request->all()));
        }
        $dados = $request->validate([
            'nome' => ['required', 'string', 'min:2', 'max:150'],
            'telefone' => ['required', 'regex:/^\d{10,11}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
        ] + ($comEndereco ? \App\Support\Endereco::regras() : []), ['telefone.regex' => 'Informe o telefone com DDD (10 ou 11 dígitos).'] + \App\Support\Endereco::MENSAGENS, \App\Support\Endereco::atributos());

        DB::transaction(function () use ($cliente, $dados) {
            $antes = $cliente->only(array_keys($dados));
            $cliente->update($dados);
            Auditoria::registrar('cliente_alterado', $cliente, $antes, $cliente->only(array_keys($dados)));
        });

        return back()->with('sucesso', 'Cadastro atualizado.');
    }

    /** Unifica duplicado no cadastro atual sem apagar atendimentos: ordens e veículos são transferidos. */
    public function mesclar(Request $request, Cliente $cliente)
    {
        $this->authorize('mesclar_clientes');
        $dados = $request->validate(['duplicado_id' => ['required', 'integer'], 'motivo' => ['required', 'string', 'min:5', 'max:500']], [], ['motivo' => 'motivo']);
        DB::transaction(function () use ($cliente, $dados) {
            [$destino, $origem] = [Cliente::lockForUpdate()->findOrFail($cliente->id), Cliente::lockForUpdate()->findOrFail($dados['duplicado_id'])];
            if ($destino->is($origem) || $destino->mesclado_em_id || $origem->mesclado_em_id) {
                throw ValidationException::withMessages(['duplicado_id' => 'Escolha outro cadastro ativo para unificar.']);
            }
            $ordens = Ordem::where('cliente_id', $origem->id)->pluck('id');
            $veiculos = $origem->veiculos()->pluck('id');
            Ordem::whereIn('id', $ordens)->update(['cliente_id' => $destino->id]);
            $origem->veiculos()->update(['cliente_id' => $destino->id]);
            $origem->mesclado_em_id = $destino->id;
            $origem->save();
            Auditoria::registrar('cliente_mesclado', $destino, ['cliente_origem' => $origem->id], ['ordens' => $ordens->all(), 'veiculos' => $veiculos->all()], $dados['motivo'], unidadeId: request()->user()->unidade_id);
        });

        return back()->with('sucesso', 'Cadastros unificados. Atendimentos e veículos foram mantidos.');
    }
}
