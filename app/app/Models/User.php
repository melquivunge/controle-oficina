<?php

namespace App\Models;

use App\Enums\Papel;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// papel, unidade_id, ativo e permissões só mudam por atribuição explícita no fluxo de configuração.
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // Iguais aos defaults do banco, para que o modelo recém-criado tenha o mesmo estado.
    protected $attributes = ['ativo' => true, 'permissoes_extras' => '[]'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'dois_fatores_dispensado' => 'boolean',
            'password' => 'hashed',
            'papel' => Papel::class,
            'ativo' => 'boolean',
            'permissoes_extras' => 'array',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'receber_avisos_whatsapp' => 'boolean', // aviso de serviço ao colaborador (opcional, desligado por padrão)
            'painel_simples' => 'boolean', // versão simples do painel (só quem vê indicadores)
        ];
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function pode(string $permissao): bool
    {
        if (! $this->ativo || $this->unidadeInativa()) {
            return false;
        }
        if ($this->papel === Papel::Mecanico && in_array($permissao, Papel::VEDADAS_AO_MECANICO, true)) {
            return false;
        }
        if (in_array($permissao, $this->papel->permissoes(), true)) {
            return true;
        }

        return array_key_exists($permissao, Papel::PERMISSOES_CONCEDIVEIS)
            && in_array($permissao, $this->permissoes_extras ?? [], true);
    }

    /** Desativar a unidade revoga o acesso de quem pertence somente a ela (supervisor: quando nenhuma das suas unidades está ativa). */
    public function unidadeInativa(): bool
    {
        if ($this->papel === Papel::Supervisor) {
            return $this->unidadeIds() === [];
        }

        return $this->papel !== Papel::Proprietario && $this->unidade_id !== null && $this->unidade?->ativa === false;
    }

    /** Supervisor multiunidade: unidades atribuídas pelo proprietário (a principal, unidade_id, também consta aqui). */
    public function unidadesSupervisionadas(): BelongsToMany
    {
        return $this->belongsToMany(Unidade::class, 'usuario_unidades', 'user_id', 'unidade_id');
    }

    /** @var array{0: string, 1: list<int>}|null cache por instância (chave = papel + unidade principal) */
    private ?array $cacheUnidadeIds = null;

    /**
     * Unidades ativas às quais o usuário pertence (não considera visão global: use visaoGlobal() antes).
     * Supervisor: as da tabela usuario_unidades; demais papéis: a própria unidade.
     *
     * @return list<int>
     */
    public function unidadeIds(): array
    {
        $chave = ($this->papel?->value ?? '').'|'.$this->unidade_id;
        if ($this->cacheUnidadeIds !== null && $this->cacheUnidadeIds[0] === $chave) {
            return $this->cacheUnidadeIds[1];
        }
        if ($this->papel === Papel::Supervisor) {
            $ids = $this->exists ? $this->unidadesSupervisionadas()->where('unidades.ativa', true)->orderBy('unidades.id')->pluck('unidades.id')->map(fn ($i) => (int) $i)->all() : [];
        } else {
            $ids = $this->unidade_id !== null && $this->unidade?->ativa !== false ? [(int) $this->unidade_id] : [];
        }
        $this->cacheUnidadeIds = [$chave, $ids];

        return $ids;
    }

    /** Descarta o cache de unidades (após alterar usuario_unidades). */
    public function esquecerUnidades(): void
    {
        $this->cacheUnidadeIds = null;
    }

    /**
     * Restringe uma consulta às unidades do usuário (proprietário: sem restrição).
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  T  $q
     * @return T
     */
    public function restringirUnidades($q, string $coluna = 'unidade_id')
    {
        return $this->visaoGlobal() ? $q : $q->whereIn($coluna, $this->unidadeIds());
    }

    public function visaoGlobal(): bool
    {
        return $this->pode('ver_todas_unidades');
    }

    public function acessaUnidade(?int $unidadeId): bool
    {
        return $this->ativo && ! $this->unidadeInativa() && ($this->visaoGlobal() || ($unidadeId !== null && in_array($unidadeId, $this->unidadeIds(), true)));
    }

    /** 2FA obrigatório para quem configura o sistema (proprietário/administrador). */
    /** Proprietário/administrador exige duas etapas, salvo dispensa individual dada por outro proprietário (auditada). */
    public function exigeDoisFatores(): bool
    {
        return $this->pode('configurar') && ! $this->dois_fatores_dispensado;
    }

    public function iniciais(): string
    {
        $partes = preg_split('/\s+/', trim($this->name));

        return mb_strtoupper(mb_substr($partes[0], 0, 1).(count($partes) > 1 ? mb_substr(end($partes), 0, 1) : ''));
    }
}
