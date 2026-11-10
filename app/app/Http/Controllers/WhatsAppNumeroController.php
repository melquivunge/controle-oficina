<?php

namespace App\Http\Controllers;

use App\Models\Unidade;
use App\Models\WhatsappNumero;
use App\Services\Auditoria;
use App\Support\Normaliza;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Números oficiais de WhatsApp (somente proprietário, com reautenticação). Credenciais nunca são exibidas depois de salvas. */
class WhatsAppNumeroController extends Controller
{
    private const AUDITADOS = ['nome', 'telefone', 'unidade_id', 'provedor', 'modo_conexao', 'phone_number_id', 'modelos', 'minutos_alerta', 'ativo'];

    public function index()
    {
        return view('whatsapp.numeros', [
            'numeros' => WhatsappNumero::with('unidade')->withCount('conversas')->orderByRaw('unidade_id IS NOT NULL')->orderBy('nome')->get(),
            'unidades' => Unidade::where('ativa', true)->orderBy('nome')->get(),
            'simulado' => \App\Integracoes\WhatsApp\Provedores::simulacaoAtiva(),
        ]);
    }

    private const SEGREDOS = ['token', 'app_secret', 'verify_token', 'signing_secret'];

    /** Botão Ativar/Desativar: inativo não envia nem recebe (webhook recusa; mensagens automáticas param). */
    public function alternarAtivo(WhatsappNumero $numero)
    {
        $ativo = DB::transaction(function () use ($numero) {
            $n = WhatsappNumero::lockForUpdate()->findOrFail($numero->id);
            $antes = $n->only(self::AUDITADOS);
            $n->ativo = ! $n->ativo;
            $n->save();
            Auditoria::registrar('whatsapp_numero_alterado', $n, $antes, $n->only(self::AUDITADOS) + ['credenciais_alteradas' => false], unidadeId: $n->unidade_id);

            return $n->ativo;
        });

        return redirect()->route('whatsapp.numeros.index')->with('sucesso', $ativo ? 'Número ativado: volta a enviar e receber.'
            : 'Número desativado: não envia nem recebe mensagens. As mensagens que chegarem enquanto estiver desativado não entram na Central.');
    }

    /** URL a partir do APP_URL, nunca do Host da requisição (Host forjado registraria webhook de terceiro). */
    public static function urlWebhookDrope(WhatsappNumero $n): string
    {
        return rtrim((string) config('app.url'), '/').'/webhooks/whatsapp/drope/'.$n->id;
    }

    /** Registra no Drope o webhook deste número e guarda o segredo de assinatura (cifrado) devolvido por ele. */
    public function registrarWebhookDrope(WhatsappNumero $numero)
    {
        abort_unless($numero->provedor === 'drope', 404);
        if (\App\Integracoes\WhatsApp\Provedores::simulacaoAtiva()) {
            return back()->withErrors(['drope' => 'Modo de simulação: nada é registrado no Drope.']);
        }
        if (! $numero->ativo || (app()->isProduction() && ! str_starts_with(self::urlWebhookDrope($numero), 'https://'))) {
            return back()->withErrors(['drope' => $numero->ativo ? 'APP_URL precisa ser https em produção.' : 'Ative o número antes de registrar o webhook.']);
        }
        try {
            $segredo = (new \App\Integracoes\WhatsApp\DropeApi)->registrarWebhook($numero, self::urlWebhookDrope($numero));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['drope' => $e->getMessage()]);
        }
        DB::transaction(function () use ($numero, $segredo) {
            $n = WhatsappNumero::lockForUpdate()->findOrFail($numero->id);
            $n->credenciais = array_merge($n->credenciais ?? [], ['signing_secret' => $segredo]);
            $n->save();
            Auditoria::registrar('whatsapp_drope_webhook_registrado', $n, depois: ['url' => self::urlWebhookDrope($n)], unidadeId: $n->unidade_id);
        });

        return redirect()->route('whatsapp.numeros.index')->with('sucesso', 'Webhook registrado no Drope. Mande uma mensagem de teste para o número.');
    }

    public function store(Request $request)
    {
        try {
            $dados = $this->validar($request, null);
        } catch (ValidationException $e) {
            return $this->voltarSemSegredos($request, $e);
        }
        DB::transaction(function () use ($dados, $request) {
            $n = new WhatsappNumero($dados['campos']);
            $n->credenciais = $dados['credenciais'];
            $n->criado_por = $request->user()->id;
            $n->save();
            Auditoria::registrar('whatsapp_numero_criado', $n, depois: $n->only(self::AUDITADOS) + ['credenciais_informadas' => $dados['credenciais'] !== []], unidadeId: $n->unidade_id);
        });

        return redirect()->route('whatsapp.numeros.index')->with('sucesso', 'Número cadastrado.');
    }

    public function update(Request $request, WhatsappNumero $numero)
    {
        try {
            $dados = $this->validar($request, $numero);
        } catch (ValidationException $e) {
            return $this->voltarSemSegredos($request, $e);
        }
        DB::transaction(function () use ($dados, $numero, $request) {
            $n = WhatsappNumero::lockForUpdate()->findOrFail($numero->id);
            $antes = $n->only(self::AUDITADOS);
            $n->fill($dados['campos']);
            $n->ativo = $request->boolean('ativo');
            $trocou = $dados['credenciais'] !== [];
            if ($trocou) {
                $n->credenciais = array_merge($n->credenciais ?? [], $dados['credenciais']); // campo vazio mantém o valor salvo
            }
            if ($n->provedor !== 'meta') {
                $n->phone_number_id = null;
            }
            $n->save();
            Auditoria::registrar('whatsapp_numero_alterado', $n, $antes, $n->only(self::AUDITADOS) + ['credenciais_alteradas' => $trocou], unidadeId: $n->unidade_id);
        });

        return redirect()->route('whatsapp.numeros.index')->with('sucesso', 'Número atualizado.');
    }

    /** Erro de validação: devolve os campos digitados, exceto credenciais (não vão para a sessão em texto claro). */
    private function voltarSemSegredos(Request $request, ValidationException $e)
    {
        return redirect()->route('whatsapp.numeros.index')->withErrors($e->errors())->withInput($request->except(self::SEGREDOS));
    }

    /** @return array{campos: array, credenciais: array} */
    private function validar(Request $request, ?WhatsappNumero $atual): array
    {
        $d = $request->validate([
            'nome' => ['required', 'string', 'max:80'],
            'telefone' => ['required', 'string', 'max:25'],
            'unidade_id' => ['nullable', 'integer', Rule::exists('unidades', 'id')->where('ativa', true)],
            'provedor' => ['required', Rule::in(['simulado', 'meta', 'drope'])],
            'modo_conexao' => ['required', Rule::in(['api', 'coexistencia'])],
            'phone_number_id' => ['nullable', 'required_if:provedor,meta', 'regex:/^[0-9]{5,64}$/', Rule::unique('whatsapp_numeros', 'phone_number_id')->ignore($atual?->id)],
            'token' => ['nullable', 'string', 'max:1000'],
            'app_secret' => ['nullable', 'string', 'max:200'],
            'verify_token' => ['nullable', 'string', 'min:12', 'max:200'],
            'signing_secret' => ['nullable', 'string', 'max:200'],
            'minutos_alerta' => ['required', 'integer', 'min:1', 'max:1440'],
            'modelos' => ['nullable', 'string', 'max:3000'],
        ], [], ['nome' => 'descrição', 'unidade_id' => 'unidade', 'phone_number_id' => 'Phone number ID', 'app_secret' => 'App secret',
            'verify_token' => 'token de verificação', 'minutos_alerta' => 'minutos para alerta', 'modo_conexao' => 'modo de conexão']);
        $telefone = Normaliza::telefone($d['telefone']);
        if (strlen($telefone) < 10 || strlen($telefone) > 13) {
            throw ValidationException::withMessages(['telefone' => 'Informe DDD + número.']);
        }
        if (WhatsappNumero::where('telefone', $telefone)->when($atual, fn ($q) => $q->whereKeyNot($atual->id))->exists()) {
            throw ValidationException::withMessages(['telefone' => 'Este número já está cadastrado.']);
        }
        $credenciais = array_filter(['token' => $d['token'] ?? null, 'app_secret' => $d['app_secret'] ?? null, 'verify_token' => $d['verify_token'] ?? null,
            'signing_secret' => $d['signing_secret'] ?? null],
            fn ($v) => is_string($v) && trim($v) !== '');
        $modelos = [];
        foreach (preg_split('/\R/', (string) ($d['modelos'] ?? '')) as $linha) {
            $partes = array_map('trim', explode('|', $linha));
            if ($partes[0] === '') {
                continue;
            }
            if (! preg_match('/^[a-z0-9_]{1,120}$/', $partes[0]) || (isset($partes[1]) && ! preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $partes[1]))) {
                throw ValidationException::withMessages(['modelos' => "Modelo inválido: “{$linha}”. Use nome_do_modelo|pt_BR|descrição."]);
            }
            $modelos[] = ['nome' => $partes[0], 'idioma' => ($partes[1] ?? '') ?: 'pt_BR', 'descricao' => isset($partes[2]) ? mb_substr($partes[2], 0, 200) : null];
        }

        return ['campos' => [
            'nome' => $d['nome'], 'telefone' => $telefone, 'unidade_id' => $d['unidade_id'] ?? null, 'provedor' => $d['provedor'], 'modo_conexao' => $d['modo_conexao'],
            'phone_number_id' => $d['provedor'] === 'meta' ? $d['phone_number_id'] : null, 'minutos_alerta' => (int) $d['minutos_alerta'], 'modelos' => $modelos,
        ], 'credenciais' => $credenciais];
    }
}
