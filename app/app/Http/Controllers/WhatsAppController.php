<?php

namespace App\Http\Controllers;

use App\Enums\StatusOrdem;
use App\Integracoes\WhatsApp\Provedores;
use App\Models\Cliente;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Models\User;
use App\Models\WhatsappConversa;
use App\Models\WhatsappMensagem;
use App\Models\WhatsappNumero;
use App\Services\WhatsApp\Atendimento;
use App\Services\WhatsApp\Atrasos;
use App\Services\WhatsApp\Simulacao;
use App\Support\Normaliza;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Central de WhatsApp (RF23): lista + conversa. Autorização por unidade/triagem conferida no servidor em toda ação. */
class WhatsAppController extends Controller
{
    public function __construct(private Atendimento $atendimento) {}

    private function exigirCentral(Request $request): User
    {
        $user = $request->user();
        abort_unless(WhatsappConversa::usaCentral($user), 403);

        return $user;
    }

    private function exigirConversa(Request $request, WhatsappConversa $conversa): User
    {
        $user = $this->exigirCentral($request);
        abort_unless($conversa->visivelPara($user), 403);

        return $user;
    }

    public function index(Request $request)
    {
        return view('whatsapp.central', $this->dadosLista($request, $this->exigirCentral($request)) + ['conversa' => null]);
    }

    public function show(Request $request, WhatsappConversa $conversa)
    {
        $user = $this->exigirConversa($request, $conversa);
        $conversa->load(['numero', 'unidade', 'cliente', 'ordem', 'responsavel', 'mensagens.autor']);
        $ordensAbertas = $conversa->cliente_id
            ? Ordem::visiveisPara($user)->with(['veiculo', 'unidade'])->where('cliente_id', $conversa->cliente_id)->whereIn('status', StatusOrdem::abertas())->orderByDesc('entrada_em')->get()
            : collect();
        $buscaCliente = trim((string) $request->query('busca_cliente', ''));

        return view('whatsapp.central', $this->dadosLista($request, $user) + [
            'conversa' => $conversa,
            'ordensAbertas' => $ordensAbertas,
            'sugestao' => $conversa->numero->central() ? $this->atendimento->sugestaoUnidade($conversa) : null,
            'responsaveis' => $this->atendimento->responsaveisPossiveis($conversa),
            'unidadesEncaminhar' => Unidade::where('ativa', true)->orderBy('nome')->get(),
            'podeEncaminhar' => $conversa->numero->central() && (WhatsappConversa::fazTriagem($user) || ($user->visaoGlobal() && WhatsappConversa::atende($user))),
            'buscaCliente' => $buscaCliente,
            'clientesEncontrados' => mb_strlen($buscaCliente) >= 2 ? Cliente::ativos()->buscar($buscaCliente)->orderBy('nome')->limit(8)->get() : collect(),
            'podeSimular' => Provedores::podeSimular(),
        ]);
    }

    private function dadosLista(Request $request, User $user): array
    {
        $f = [
            'situacao' => (string) $request->query('situacao', 'abertas'),
            'unidade' => (string) $request->query('unidade', ''),
            'numero' => (string) $request->query('numero', ''),
            'responsavel' => (string) $request->query('responsavel', ''),
            'busca' => trim((string) $request->query('busca', '')),
        ];
        $q = WhatsappConversa::visiveisPara($user)->with(['numero', 'unidade', 'cliente', 'responsavel', 'ultimaMensagem']);
        match ($f['situacao']) {
            'todas' => null,
            'sem_resposta' => $q->whereIn('whatsapp_conversas.id', app(Atrasos::class)->consultaSemResposta($user)->select('whatsapp_conversas.id')),
            'abertas' => $q->where('situacao', '<>', 'resolvida'),
            default => array_key_exists($f['situacao'], WhatsappConversa::SITUACOES) ? $q->where('situacao', $f['situacao']) : $q->where('situacao', '<>', 'resolvida'),
        };
        if ($f['unidade'] === 'triagem') {
            $q->whereNull('unidade_id');
        } elseif (ctype_digit($f['unidade'])) {
            $q->where('unidade_id', (int) $f['unidade']);
        }
        if (ctype_digit($f['numero'])) {
            $q->where('numero_id', (int) $f['numero']);
        }
        match (true) {
            $f['responsavel'] === 'eu' => $q->where('responsavel_id', $user->id),
            $f['responsavel'] === 'sem' => $q->whereNull('responsavel_id'),
            ctype_digit($f['responsavel']) => $q->where('responsavel_id', (int) $f['responsavel']),
            default => null,
        };
        if ($f['busca'] !== '') {
            $digitos = preg_replace('/\D/', '', $f['busca']);
            $termo = '%'.mb_strtolower(addcslashes($f['busca'], '%_\\')).'%';
            $completo = $user->pode('ver_auditoria'); // quem vê o telefone completo busca por qualquer trecho
            $q->where(function (Builder $w) use ($digitos, $termo, $completo, $user) {
                $w->whereRaw('lower(contato_nome) like ?', [$termo])
                    ->orWhereHas('cliente', fn (Builder $c) => $c->whereRaw('lower(nome) like ?', [$termo]));
                if (strlen($digitos) >= 4) {
                    // Demais: telefone completo só nas conversas sob sua responsabilidade; nas outras, só os 4 finais (já visíveis).
                    if ($completo) {
                        $w->orWhere('contato_telefone', 'like', '%'.$digitos.'%');
                    } else {
                        $w->orWhere(fn (Builder $t) => $t->where('responsavel_id', $user->id)->where('contato_telefone', 'like', '%'.$digitos.'%'));
                        if (strlen($digitos) === 4) {
                            $w->orWhere('contato_telefone', 'like', '%'.$digitos);
                        }
                    }
                }
            });
        }
        $conversas = $q->orderByRaw('aguardando_desde IS NULL, aguardando_desde ASC')->orderByDesc('ultima_mensagem_em')->paginate(30)->withQueryString();
        $numeros = WhatsappNumero::with('unidade')->orderBy('nome')->get()->filter(fn (WhatsappNumero $n) => $n->central()
            ? WhatsappConversa::fazTriagem($user) : (WhatsappConversa::atende($user) && $user->acessaUnidade($n->unidade_id)))->values();

        return [
            'conversas' => $conversas,
            'filtros' => $f,
            'indicadores' => app(Atrasos::class)->indicadores($user),
            'atrasadas' => app(Atrasos::class)->consultaSemResposta($user)->pluck('whatsapp_conversas.id')->flip(),
            'numeros' => $numeros,
            'unidadesFiltro' => Unidade::visiveisPara($user)->orderBy('nome')->get(),
            'veTriagem' => WhatsappConversa::fazTriagem($user),
            'podeSimular' => Provedores::podeSimular(),
            'usuario' => $user,
        ];
    }

    public function responder(Request $request, WhatsappConversa $conversa)
    {
        $user = $this->exigirConversa($request, $conversa);
        $dados = $request->validate([
            'texto' => ['nullable', 'string', 'max:4096'],
            'anexo' => ['nullable', 'file', 'max:'.(int) config('oficina.whatsapp.anexo_max_kb')],
        ], [], ['texto' => 'mensagem', 'anexo' => 'anexo']);
        $this->atendimento->responder($conversa, $user, $dados['texto'] ?? null, $request->file('anexo'));

        return $this->voltar($conversa, 'Resposta na fila de envio.');
    }

    public function modelo(Request $request, WhatsappConversa $conversa)
    {
        $user = $this->exigirConversa($request, $conversa);
        $dados = $request->validate(['modelo' => ['required', 'string', 'max:120']]);
        $this->atendimento->enviarModelo($conversa, $user, $dados['modelo']);

        return $this->voltar($conversa, 'Modelo aprovado na fila de envio.');
    }

    public function tentar(Request $request, WhatsappMensagem $mensagem)
    {
        $user = $this->exigirConversa($request, $mensagem->conversa);
        $this->atendimento->tentarDeNovo($mensagem, $user);

        return $this->voltar($mensagem->conversa, 'Nova tentativa de envio na fila.');
    }

    public function atribuir(Request $request, WhatsappConversa $conversa)
    {
        $user = $this->exigirConversa($request, $conversa);
        $dados = $request->validate(['responsavel_id' => ['nullable', 'integer']]);
        $this->atendimento->atribuir($conversa, $user, isset($dados['responsavel_id']) ? (int) $dados['responsavel_id'] : null);

        return $this->voltar($conversa, 'Responsável atualizado.');
    }

    public function situacao(Request $request, WhatsappConversa $conversa)
    {
        $user = $this->exigirConversa($request, $conversa);
        $dados = $request->validate(['situacao' => ['required', Rule::in(array_keys(WhatsappConversa::SITUACOES))], 'motivo' => ['nullable', 'string', 'max:500']]);
        $this->atendimento->mudarSituacao($conversa, $user, $dados['situacao'], $dados['motivo'] ?? null);

        return $this->voltar($conversa, 'Situação atualizada.');
    }

    public function encaminhar(Request $request, WhatsappConversa $conversa)
    {
        $user = $this->exigirConversa($request, $conversa);
        $dados = $request->validate(['unidade_id' => ['required', 'integer'], 'observacao' => ['nullable', 'string', 'max:300']]);
        $this->atendimento->encaminhar($conversa, $user, (int) $dados['unidade_id'], $dados['observacao'] ?? null);

        // Quem encaminhou pode deixar de ver a conversa (outra unidade): volta para a Central.
        return $conversa->fresh()->visivelPara($user)
            ? $this->voltar($conversa, 'Conversa encaminhada.')
            : redirect()->route('whatsapp.index')->with('sucesso', 'Conversa encaminhada para '.$conversa->fresh()->unidade->nome.'.');
    }

    public function cadastrarCliente(Request $request, WhatsappConversa $conversa)
    {
        $user = $this->exigirConversa($request, $conversa);
        // Mesmas regras do Novo cliente (ClienteController::store): CPF/CNPJ e veículo opcionais; placa nunca duplica.
        $request->merge(['placa' => Normaliza::placa($request->input('placa')), 'documento' => preg_replace('/\D/', '', (string) $request->input('documento')),
            'chassi' => Normaliza::chassi($request->input('chassi'))]);
        $dados = $request->validate([
            'nome' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'documento' => ['nullable', 'regex:/^(\d{11}|\d{14})$/'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            'placa' => ['nullable', 'regex:'.Normaliza::REGEX_PLACA, Rule::unique('veiculos', 'placa')],
            'marca' => ['nullable', 'string', 'max:40'], 'modelo' => ['nullable', 'string', 'max:80'],
            'ano' => ['nullable', 'integer', 'min:1950', 'max:'.(now()->year + 1)], 'cor' => ['nullable', 'string', 'max:30'],
            'chassi' => ['nullable', 'regex:'.Normaliza::REGEX_CHASSI, Rule::unique('veiculos', 'chassi')],
        ], ['chassi.regex' => 'Chassi deve ter 17 letras e números (sem I, O ou Q).', 'chassi.unique' => 'Este chassi já está cadastrado em outro veículo.', 'documento.regex' => 'CPF (11 dígitos) ou CNPJ (14 dígitos).', 'placa.regex' => 'Placa inválida (ex.: ABC1D23).',
            'placa.unique' => 'Esta placa já está cadastrada. Use "Vincular cliente" e busque pela placa.'],
            ['nome' => 'nome do cliente', 'email' => 'e-mail', 'observacoes' => 'observações', 'documento' => 'CPF ou CNPJ']);
        $cliente = $this->atendimento->cadastrarCliente($conversa, $user, $dados);

        return $this->voltar($conversa, "Cliente {$cliente->nome} cadastrado e vinculado à conversa.");
    }

    public function vincularCliente(Request $request, WhatsappConversa $conversa)
    {
        $user = $this->exigirConversa($request, $conversa);
        $dados = $request->validate(['cliente_id' => ['nullable', 'integer']]);
        $this->atendimento->vincularCliente($conversa, $user, isset($dados['cliente_id']) ? (int) $dados['cliente_id'] : null);

        return $this->voltar($conversa, 'Vínculo com cliente atualizado.');
    }

    public function vincularOrdem(Request $request, WhatsappConversa $conversa)
    {
        $user = $this->exigirConversa($request, $conversa);
        $dados = $request->validate(['ordem' => ['nullable', 'string', 'max:40']]);
        $this->atendimento->vincularOrdem($conversa, $user, $dados['ordem'] ?? null);

        return $this->voltar($conversa, 'Vínculo com OS atualizado.');
    }

    /** Download autorizado pela conversa dona do arquivo; nunca por caminho vindo da requisição. */
    public function midia(Request $request, WhatsappMensagem $mensagem)
    {
        $this->exigirConversa($request, $mensagem->conversa);
        abort_unless($mensagem->midia_status === 'baixada' && $mensagem->midia_caminho && Storage::disk('local')->exists($mensagem->midia_caminho), 404);
        $inline = str_starts_with((string) $mensagem->midia_mime, 'image/') || str_starts_with((string) $mensagem->midia_mime, 'audio/') || $mensagem->midia_mime === 'application/ogg';

        return Storage::disk('local')->response($mensagem->midia_caminho, $mensagem->midia_nome ?: 'arquivo', [
            'Content-Type' => $mensagem->midia_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }

    /** SOMENTE simulação fora de produção: injeta uma mensagem recebida pelo caminho do webhook do simulador. */
    public function simular(Request $request, Simulacao $simulacao)
    {
        $user = $this->exigirCentral($request);
        abort_unless(Provedores::podeSimular(), 404);
        $dados = $request->validate([
            'numero_id' => ['required', 'integer'],
            'telefone' => ['required', 'string', 'max:20'],
            'nome' => ['nullable', 'string', 'max:120'],
            'tipo' => ['required', Rule::in(['texto', 'imagem', 'audio', 'documento', 'localizacao'])],
            'texto' => ['nullable', 'string', 'max:1000'],
        ], [], ['numero_id' => 'número', 'telefone' => 'telefone do contato']);
        $numero = WhatsappNumero::where('ativo', true)->findOrFail($dados['numero_id']);
        abort_unless($numero->central() ? WhatsappConversa::fazTriagem($user) : (WhatsappConversa::atende($user) && $user->acessaUnidade($numero->unidade_id)), 403);
        $tel = Normaliza::telefone($dados['telefone']);
        if (strlen($tel) < 10 || strlen($tel) > 11) {
            return back()->withErrors(['telefone' => 'Informe DDD + número (10 ou 11 dígitos).'])->withInput();
        }
        $simulacao->mensagemRecebida($numero, $tel, $dados['nome'] ?? null, $dados['tipo'], $dados['texto'] ?? null);
        $conversa = WhatsappConversa::where('numero_id', $numero->id)->where('contato_telefone', $tel)->first();

        return $conversa && $conversa->visivelPara($user)
            ? redirect()->route('whatsapp.show', $conversa)->with('sucesso', 'Mensagem SIMULADA recebida (nada veio de fora).')
            : redirect()->route('whatsapp.index')->with('sucesso', 'Mensagem SIMULADA recebida.');
    }

    /** SOMENTE simulação: resposta digitada no app do celular da empresa (coexistência). */
    public function simularCelular(Request $request, WhatsappConversa $conversa, Simulacao $simulacao)
    {
        $this->exigirConversa($request, $conversa);
        abort_unless(Provedores::podeSimular(), 404);
        $dados = $request->validate(['texto_celular' => ['required', 'string', 'max:1000']], [], ['texto_celular' => 'mensagem']);
        $simulacao->respostaCelular($conversa, $dados['texto_celular']);

        return $this->voltar($conversa, 'Resposta SIMULADA pelo celular registrada.');
    }

    private function voltar(WhatsappConversa $conversa, string $mensagem)
    {
        return redirect()->to(route('whatsapp.show', $conversa).'#fim')->with('sucesso', $mensagem);
    }
}
