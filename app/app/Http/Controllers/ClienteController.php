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
            // Sem busca: atendidos nas unidades do usuário + cadastros ainda sem atendimento (importados do sistema antigo
            // ou cadastrados em "Novo cliente", 09/11/2026). Com busca: cadastro da rede (evita duplicar na entrada).
            ->when($busca === '', fn ($q) => $q->where(fn ($q) => $q->whereHas('ordens', $visiveis)->orWhereDoesntHave('ordens'))
                ->orderByRaw('ordens_max_entrada_em desc nulls last')->orderBy('nome'), fn ($q) => $q->buscar($busca)->orderBy('nome'))
            ->paginate(24)->withQueryString();

        return view('clientes.index', compact('clientes', 'busca'));
    }

    /**
     * OS do sistema antigo (só leitura, 09/11/2026) com serviços e peças, mais recentes primeiro.
     *
     * @return \Illuminate\Support\Collection<int, array{os: array, itens: list<array>, placa: ?string}>
     */
    private static function historicoAntigo(Cliente $cliente): \Illuminate\Support\Collection
    {
        $linhas = DB::table('legado_registros')->where('cliente_id', $cliente->id)->whereIn('tabela', ['TBL_OS', 'TBL_SERVICO', 'TBL_PECA'])
            ->get(['tabela', 'grupo', 'veiculo_id', 'dados']);
        $placas = \App\Models\Veiculo::whereIn('id', $linhas->pluck('veiculo_id')->filter()->unique())->get()->keyBy('id');
        $itens = $linhas->where('tabela', '!=', 'TBL_OS')->groupBy('grupo');

        return $linhas->where('tabela', 'TBL_OS')->map(fn ($l) => [
            'os' => json_decode($l->dados, true),
            'placa' => $placas[$l->veiculo_id] ?? null,
            'itens' => ($itens[$l->grupo] ?? collect())->map(fn ($i) => ['tipo' => $i->tabela === 'TBL_PECA' ? 'Peça' : 'Serviço'] + json_decode($i->dados, true))->values()->all(),
        ])->sortByDesc(fn ($h) => ($h['os']['DATAIN'] ?? '').str_pad((string) ($h['os']['CODIGO'] ?? ''), 10, '0', STR_PAD_LEFT))->values();
    }

    public function create()
    {
        return view('clientes.novo');
    }

    /** Cadastro só do cliente (sem OS), 09/11/2026. Telefone repetido pede confirmação (família/empresa); placa nunca duplica. */
    public function store(Request $request)
    {
        $request->merge(['telefone' => Normaliza::telefone($request->input('telefone')), 'placa' => Normaliza::placa($request->input('placa')),
            'documento' => preg_replace('/\D/', '', (string) $request->input('documento'))]);
        $comEndereco = $request->user()->can('ver_contato_cliente') && $request->hasAny(\App\Support\Endereco::CAMPOS);
        if ($comEndereco) {
            $request->merge(\App\Support\Endereco::normalizar($request->all()));
        }
        $dados = $request->validate([
            'nome' => ['required', 'string', 'min:2', 'max:150'],
            'telefone' => ['required', 'regex:/^\d{10,11}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'documento' => ['nullable', 'regex:/^(\d{11}|\d{14})$/'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            'placa' => ['nullable', 'regex:'.Normaliza::REGEX_PLACA, \Illuminate\Validation\Rule::unique('veiculos', 'placa')],
            'marca' => ['nullable', 'string', 'max:40'], 'modelo' => ['nullable', 'string', 'max:80'],
            'ano' => ['nullable', 'integer', 'min:1950', 'max:'.(now()->year + 1)], 'cor' => ['nullable', 'string', 'max:30'],
        ] + ($comEndereco ? \App\Support\Endereco::regras() : []), [
            'telefone.regex' => 'Informe o telefone com DDD (10 ou 11 dígitos).', 'documento.regex' => 'CPF (11 dígitos) ou CNPJ (14 dígitos).',
            'placa.regex' => 'Placa inválida (ex.: ABC1D23).', 'placa.unique' => 'Esta placa já está cadastrada. Busque pela placa em Clientes.',
        ] + \App\Support\Endereco::MENSAGENS, \App\Support\Endereco::atributos());
        $mesmoTelefone = Cliente::ativos()->where('telefone', $dados['telefone'])->first();
        if ($mesmoTelefone && ! $request->boolean('confirmar_telefone')) {
            throw ValidationException::withMessages(['telefone' => "Este telefone já é de “{$mesmoTelefone->nome}”. Se for outra pessoa com o mesmo número, marque a confirmação."])
                ->redirectTo(route('clientes.create'));
        }
        $cliente = DB::transaction(function () use ($dados, $request) {
            $c = new Cliente(collect($dados)->only(['nome', 'telefone', 'email', 'observacoes', ...\App\Support\Endereco::CAMPOS])->all());
            $c->documento = $dados['documento'] ?: null;
            $c->save();
            Auditoria::registrar('cliente_criado', $c, depois: $c->only(['nome', 'telefone']));
            if (! empty($dados['placa'])) {
                $v = new \App\Models\Veiculo(['placa' => $dados['placa'], 'marca' => $dados['marca'] ?? null, 'modelo' => $dados['modelo'] ?? null,
                    'ano' => $dados['ano'] ?? null, 'cor' => $dados['cor'] ?? null]);
                $v->cliente_id = $c->id;
                $v->save();
                Auditoria::registrar('veiculo_criado', $v, depois: ['placa' => $v->placa, 'cliente_id' => $c->id]);
            }

            return $c;
        });

        return redirect()->route('clientes.show', $cliente)->with('sucesso', 'Cliente cadastrado.');
    }

    public function show(Request $request, Cliente $cliente)
    {
        if ($cliente->mesclado_em_id) {
            return redirect()->route('clientes.show', $cliente->mesclado_em_id)->with('sucesso', 'Este cadastro foi unificado com o atual.');
        }
        $cliente->load('veiculos');

        return view('clientes.show', [
            'cliente' => $cliente,
            'historicoAntigo' => self::historicoAntigo($cliente),
            'ordens' => Ordem::visiveisPara($request->user())->with('veiculo', 'unidade')->where('cliente_id', $cliente->id)->orderByDesc('entrada_em')->limit(100)->get(),
            // Sem telefone (importados do sistema antigo): não sugere todos os sem telefone como duplicados.
            'possiveisDuplicados' => $cliente->telefone === '' ? collect() : Cliente::ativos()->whereKeyNot($cliente->id)->where('telefone', $cliente->telefone)->get(),
        ]);
    }

    public function update(Request $request, Cliente $cliente)
    {
        abort_if($cliente->mesclado_em_id, 404);
        // Cadastro é da rede, mas a alteração (ex.: telefone que recebe links) cabe a quem atende o cliente.
        // Sem nenhum atendimento ainda (importado/cadastrado direto): qualquer um que registra entrada pode completar o cadastro.
        abort_unless($request->user()->visaoGlobal() || ! $cliente->ordens()->exists() || Ordem::visiveisPara($request->user())->where('cliente_id', $cliente->id)->exists(), 403);
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
