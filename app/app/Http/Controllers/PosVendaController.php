<?php

namespace App\Http\Controllers;

use App\Models\Ordem;
use App\Models\PosVendaContato;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Auditoria;
use App\Services\AvisosColaborador;
use App\Services\PosVenda;
use App\Support\Dinheiro;
use App\Support\Fuso;
use App\Support\Normaliza;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Pós-venda: lista de atendimentos entregues, ficha de contatos por OS e exportação CSV. Rotas sob can:pos_venda. */
class PosVendaController extends Controller
{
    public function __construct(private PosVenda $posVenda) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $filtros = $this->filtros($request);
        $ordens = $this->posVenda->consulta($user, $filtros)->with(['cliente', 'veiculo', 'unidade', 'orcamentoAprovado.itens'])
            ->orderByDesc('entregas.entregue_em')->paginate(50)->withQueryString();
        [$de, $ate] = PosVenda::periodo($filtros['de'] ?? null, $filtros['ate'] ?? null);

        return view('posvenda.index', [
            'ordens' => $ordens,
            'contatos' => $this->posVenda->contatosPorOrdem($ordens->pluck('id')),
            'indicadores' => $this->posVenda->indicadores($user, $filtros),
            'retornos' => $this->posVenda->retornosPendentes($user)->when($filtros['unidade'] ?? null, fn ($c, $u) => $c->where('unidade_id', (int) $u)),
            'de' => $de, 'ate' => $ate, 'filtros' => $filtros,
            'unidades' => Unidade::visiveisPara($user)->orderBy('nome')->pluck('nome', 'id'),
            'vendedores' => $this->vendedores($user),
            'verContato' => $user->pode('ver_contato_cliente'),
            'verValores' => $user->pode('ver_valores'),
        ]);
    }

    public function show(Request $request, Ordem $ordem)
    {
        $user = $request->user();
        $this->posVenda->autorizarOrdem($user, $ordem);
        $ordem->load('cliente', 'veiculo', 'unidade', 'entrega', 'orcamentoAprovado.itens');

        return view('posvenda.show', [
            'ordem' => $ordem,
            'contatos' => $this->posVenda->contatosPorOrdem(collect([$ordem->id]))->get($ordem->id, collect()),
            'abertos' => $this->posVenda->problemasAbertos($user)->where('ordem_id', $ordem->id)->pluck('id')->all(),
            'verContato' => $user->pode('ver_contato_cliente'),
            'verValores' => $user->pode('ver_valores'),
            'hoje' => PosVenda::hoje(),
        ]);
    }

    public function registrar(Request $request, Ordem $ordem)
    {
        $this->posVenda->autorizarOrdem($request->user(), $ordem); // 403/404 antes de validar
        $dados = $request->validate([
            'tentativa' => ['required', Rule::in(array_keys(PosVendaContato::TENTATIVAS))],
            'resultado' => ['required', Rule::in(array_keys(PosVendaContato::RESULTADOS))],
            'nota' => ['nullable', 'integer', 'between:0,10'],
            'comentario' => ['nullable', 'string', 'max:2000'],
            'problema_servico' => ['nullable', 'boolean'],
            'problema_descricao' => ['nullable', 'required_if:problema_servico,1', 'string', 'max:1000'],
            'negociacao_fora_padrao' => ['nullable', 'boolean'],
            'negociacao_descricao' => ['nullable', 'required_if:negociacao_fora_padrao,1', 'string', 'max:1000'],
            'retorno_em' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.PosVenda::hoje()],
        ], [
            'problema_descricao.required_if' => 'Descreva o problema relatado.',
            'negociacao_descricao.required_if' => 'Descreva a negociação fora do padrão.',
            'retorno_em.after_or_equal' => 'O retorno deve ser hoje ou em data futura.',
        ], ['tentativa' => 'tentativa', 'nota' => 'nota', 'comentario' => 'comentário', 'retorno_em' => 'data do retorno']);
        $dados['problema_servico'] = $request->boolean('problema_servico');
        $dados['negociacao_fora_padrao'] = $request->boolean('negociacao_fora_padrao');
        $this->posVenda->registrarContato($ordem, $request->user(), $dados);

        return redirect()->route('posvenda.show', $ordem)->with('sucesso', 'Contato registrado no histórico de pós-venda.');
    }

    /** CSV com os mesmos filtros e visibilidade da tela; células neutralizadas contra fórmulas. */
    public function exportar(Request $request)
    {
        $user = $request->user();
        $filtros = $this->filtros($request);
        $consulta = $this->posVenda->consulta($user, $filtros)->with(['cliente', 'veiculo', 'unidade', 'orcamentoAprovado.itens'])->orderByDesc('entregas.entregue_em');
        [$de, $ate] = PosVenda::periodo($filtros['de'] ?? null, $filtros['ate'] ?? null);
        $verContato = $user->pode('ver_contato_cliente');
        $verValores = $user->pode('ver_valores');
        Auditoria::registrar('pos_venda_exportado', depois: ['filtros' => $filtros], ator: $user);
        $esc = [AuditoriaController::class, 'celula'];
        $abertos = $this->posVenda->problemasAbertos($user)->pluck('ordem_id')->unique()->flip();

        return response()->streamDownload(function () use ($consulta, $verContato, $verValores, $esc, $abertos) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values(array_filter(['OS', 'Unidade', 'Cliente', $verContato ? 'Telefone' : null, 'Veículo', 'Placa', 'Serviço',
                $verValores ? 'Valor' : null, 'Entregue em', 'Contatos', 'Último resultado', 'Nota (0-10)', 'Problema em aberto', 'Retorno agendado'])), ';');
            foreach ($consulta->get()->chunk(200) as $lote) {
                $contatos = $this->posVenda->contatosPorOrdem($lote->pluck('id'));
                foreach ($lote as $o) {
                    $cs = $contatos->get($o->id, collect());
                    $ultimo = $cs->first();
                    $nota = $cs->firstWhere(fn ($c) => $c->nota !== null)?->nota;
                    $linha = [$o->numero, $o->unidade?->nome, $o->cliente?->nome, $verContato ? Normaliza::telefoneExibicao($o->cliente?->telefone) : null,
                        $o->veiculo?->modelo, $o->veiculo?->placaFormatada(), AvisosColaborador::resumoServico($o, 300),
                        $verValores ? Dinheiro::formatar((int) ($o->orcamentoAprovado?->total_centavos ?? 0)) : null,
                        Fuso::exibir(\Carbon\CarbonImmutable::parse($o->entregue_em)), (string) $cs->count(), $ultimo?->rotuloResultado() ?? 'Sem contato',
                        $nota === null ? '' : (string) $nota, $abertos->has($o->id) ? 'Sim' : '',
                        $ultimo?->retorno_em?->format('d/m/Y') ?? ''];
                    if (! $verContato) {
                        unset($linha[3]);
                    }
                    if (! $verValores) {
                        unset($linha[7]);
                    }
                    fputcsv($out, array_map(fn ($v) => $esc((string) $v), array_values($linha)), ';');
                }
            }
            fclose($out);
        }, "pos-venda-{$de}-a-{$ate}.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function filtros(Request $request): array
    {
        $f = $request->validate([
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
            'unidade' => ['nullable', 'integer'],
            'situacao' => ['nullable', Rule::in(array_keys(PosVenda::SITUACOES))],
            'vendedor' => ['nullable', 'integer'],
        ]);
        if (! empty($f['unidade'])) {
            abort_unless($request->user()->acessaUnidade((int) $f['unidade']), 403);
        }

        return array_filter($f, fn ($v) => $v !== null && $v !== '');
    }

    /** Filtro de vendedor só quando a OS tiver a coluna vendedor_id (entregue por outra frente). */
    private function vendedores(User $user): array
    {
        if (! PosVenda::temVendedor()) {
            return [];
        }

        return User::whereIn('id', \App\Models\Ordem::visiveisPara($user)->whereNotNull('vendedor_id')->select('vendedor_id'))->orderBy('name')->pluck('name', 'id')->all();
    }
}
