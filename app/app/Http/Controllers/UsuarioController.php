<?php

namespace App\Http\Controllers;

use App\Enums\Papel;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Auditoria;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UsuarioController extends Controller
{
    private const CAMPOS_AUDITADOS = ['name', 'email', 'papel', 'unidade_id', 'ativo', 'permissoes_extras'];

    public function index(Request $request)
    {
        $usuarios = User::with('unidade')
            ->when($request->integer('unidade'), fn ($q, $u) => $q->where('unidade_id', $u))
            ->orderByDesc('ativo')->orderBy('name')->paginate(30)->withQueryString();

        return view('configuracoes.usuarios', ['usuarios' => $usuarios, 'unidades' => Unidade::orderBy('nome')->get()]);
    }

    public function create()
    {
        return view('configuracoes.usuario-form', ['usuario' => tap(new User, fn (User $u) => $u->papel = Papel::Recepcao), 'unidades' => Unidade::where('ativa', true)->orderBy('nome')->get()]);
    }

    public function store(Request $request)
    {
        $dados = $this->validar($request);
        $usuario = DB::transaction(function () use ($dados) {
            $usuario = new User;
            $this->preencher($usuario, $dados);
            $usuario->password = $dados['password'];
            $usuario->save();
            $this->sincronizarUnidades($usuario, $dados);
            Auditoria::registrar('usuario_criado', $usuario, depois: $this->auditado($usuario));
            $this->entregarChaveSeNecessario($usuario);

            return $usuario;
        });

        return redirect()->route('usuarios.index')->with('sucesso', "Conta de {$usuario->name} criada. Entregue a senha inicial pessoalmente.");
    }

    public function edit(User $usuario)
    {
        return view('configuracoes.usuario-form', ['usuario' => $usuario, 'unidades' => Unidade::orderBy('nome')->get()]);
    }

    public function update(Request $request, User $usuario)
    {
        $dados = $this->validar($request, $usuario);
        if ($usuario->is($request->user()) && $dados['papel'] !== Papel::Proprietario->value) {
            throw ValidationException::withMessages(['papel' => 'Você não pode remover o próprio acesso de proprietário.']);
        }
        DB::transaction(function () use ($usuario, $dados) {
            $antes = $this->auditado($usuario);
            $this->preencher($usuario, $dados);
            if (! empty($dados['password'])) {
                $usuario->password = $dados['password'];
            }
            $usuario->save();
            $this->sincronizarUnidades($usuario, $dados);
            $this->entregarChaveSeNecessario($usuario);
            Auditoria::registrar('usuario_alterado', $usuario, $antes, $this->auditado($usuario) + (empty($dados['password']) ? [] : ['senha_redefinida' => true]));
        });

        return redirect()->route('usuarios.index')->with('sucesso', "Conta de {$usuario->name} atualizada.");
    }

    public function alternarAtivo(Request $request, User $usuario)
    {
        $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']], [], ['motivo' => 'motivo']);
        if ($usuario->is($request->user())) {
            throw ValidationException::withMessages(['motivo' => 'Você não pode desativar a própria conta.']);
        }
        DB::transaction(function () use ($usuario, $request) {
            $usuario->ativo = ! $usuario->ativo;
            $usuario->save();
            Auditoria::registrar($usuario->ativo ? 'usuario_reativado' : 'usuario_desativado', $usuario, ['ativo' => ! $usuario->ativo], ['ativo' => $usuario->ativo], $request->input('motivo'));
        });

        return redirect()->route('usuarios.index')->with('sucesso', $usuario->ativo ? "Conta de {$usuario->name} reativada." : "Conta de {$usuario->name} desativada. O acesso foi revogado.");
    }

    /** Gera nova chave de verificação em duas etapas para outro proprietário (perda de celular). */
    public function gerarDoisFatores(Request $request, User $usuario)
    {
        abort_unless($usuario->exigeDoisFatores() && ! $usuario->is($request->user()), 403);
        $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']], [], ['motivo' => 'motivo']);
        DB::transaction(function () use ($usuario, $request) {
            $usuario->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
            $this->entregarChaveSeNecessario($usuario, $request->input('motivo'));
        });

        return redirect()->route('usuarios.edit', $usuario);
    }

    /**
     * Sem autocadastro pela web: a chave é gerada aqui por quem já passou pelo 2FA e pela
     * reautenticação, exibida uma única vez e entregue pessoalmente.
     */
    private function entregarChaveSeNecessario(User $usuario, ?string $motivo = null): void
    {
        if (! $usuario->exigeDoisFatores() || $usuario->two_factor_confirmed_at) {
            return;
        }
        $segredo = Totp::novoSegredo();
        $usuario->forceFill(['two_factor_secret' => $segredo, 'two_factor_confirmed_at' => now()])->save();
        Auditoria::registrar('dois_fatores_chave_gerada', $usuario, motivo: $motivo ?? 'conta com acesso de proprietário');
        session()->flash('chave_2fa', ['email' => $usuario->email, 'segredo' => $segredo, 'uri' => Totp::uri($segredo, $usuario->email)]);
    }

    private function validar(Request $request, ?User $usuario = null): array
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($usuario)],
            'papel' => ['required', new Enum(Papel::class)],
            'unidade_id' => [Rule::requiredIf(! in_array($request->input('papel'), [Papel::Proprietario->value, Papel::Supervisor->value], true)), 'nullable', 'integer', 'exists:unidades,id'],
            // Supervisor multiunidade: ao menos uma unidade; a principal (unidade_id) entra automaticamente no conjunto.
            'unidades' => [Rule::requiredIf($request->input('papel') === Papel::Supervisor->value), 'array'],
            'unidades.*' => ['integer', 'distinct', Rule::exists('unidades', 'id')],
            'permissoes_extras' => ['array'],
            'permissoes_extras.*' => [Rule::in(array_keys(Papel::PERMISSOES_CONCEDIVEIS))],
            'password' => [$usuario ? 'nullable' : 'required', 'confirmed', Password::min(10)->letters()->numbers()],
        ], [], ['name' => 'nome', 'email' => 'e-mail', 'unidade_id' => 'unidade', 'unidades' => 'unidades supervisionadas', 'password' => 'senha', 'permissoes_extras' => 'permissões']);
    }

    /** Pivô usuario_unidades: só o supervisor usa; demais papéis ficam sem linhas. */
    private function sincronizarUnidades(User $usuario, array $dados): void
    {
        $ids = $usuario->papel === Papel::Supervisor
            ? array_values(array_unique([...array_map('intval', $dados['unidades'] ?? []), (int) $usuario->unidade_id]))
            : [];
        $usuario->unidadesSupervisionadas()->sync($ids);
        $usuario->esquecerUnidades();
    }

    private function auditado(User $usuario): array
    {
        $dados = $usuario->only(self::CAMPOS_AUDITADOS);
        if ($usuario->exists) {
            $ids = $usuario->unidadesSupervisionadas()->orderBy('unidades.id')->pluck('unidades.id')->map(fn ($i) => (int) $i)->all();
            if ($ids !== [] || $usuario->papel === Papel::Supervisor) {
                $dados['unidades_supervisionadas'] = $ids;
            }
        }

        return $dados;
    }

    private function preencher(User $usuario, array $dados): void
    {
        $papel = Papel::from($dados['papel']);
        $usuario->name = $dados['name'];
        $usuario->email = $dados['email'];
        $usuario->papel = $papel;
        $usuario->unidade_id = match ($papel) {
            Papel::Proprietario => null,
            Papel::Supervisor => (int) ($dados['unidade_id'] ?? min(array_map('intval', $dados['unidades']))),
            default => $dados['unidade_id'],
        };
        $usuario->permissoes_extras = array_values(array_diff($dados['permissoes_extras'] ?? [], $papel->permissoes()));
    }
}
