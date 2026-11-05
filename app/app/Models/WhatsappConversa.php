<?php

namespace App\Models;

use App\Enums\Papel;
use App\Support\Normaliza;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WhatsappConversa extends Model
{
    public const SITUACOES = ['nova' => 'Nova', 'em_atendimento' => 'Em atendimento', 'aguardando_cliente' => 'Aguardando cliente', 'resolvida' => 'Resolvida'];

    public const JANELA_HORAS = 24;

    protected $table = 'whatsapp_conversas';

    // Situação, unidade, vínculos e responsável só mudam pelos serviços da Central (auditados).
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ultima_mensagem_em' => 'datetime', 'ultima_entrada_em' => 'datetime', 'aguardando_desde' => 'datetime', 'resolvida_em' => 'datetime'];
    }

    public function numero(): BelongsTo
    {
        return $this->belongsTo(WhatsappNumero::class, 'numero_id');
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function ordem(): BelongsTo
    {
        return $this->belongsTo(Ordem::class);
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(WhatsappMensagem::class, 'conversa_id')->orderBy('ocorrida_em')->orderBy('id');
    }

    public function ultimaMensagem(): HasOne
    {
        return $this->hasOne(WhatsappMensagem::class, 'conversa_id')->latestOfMany('ocorrida_em');
    }

    public function emTriagem(): bool
    {
        return $this->unidade_id === null;
    }

    public function rotuloSituacao(): string
    {
        return self::SITUACOES[$this->situacao] ?? $this->situacao;
    }

    /** Janela de atendimento do WhatsApp: texto livre só até 24 h após a última mensagem do cliente. */
    public function janelaAberta(): bool
    {
        return $this->ultima_entrada_em !== null && $this->ultima_entrada_em->greaterThan(now()->subHours(self::JANELA_HORAS));
    }

    public function janelaFechaEm(): ?\Carbon\CarbonInterface
    {
        return $this->ultima_entrada_em?->copy()->addHours(self::JANELA_HORAS);
    }

    /**
     * Conversas que o usuário pode ver e responder (autorização no servidor):
     * de unidade → quem atende WhatsApp e acessa a unidade; triagem (número central) → quem tem triagem_whatsapp. Mecânico nunca.
     */
    public function scopeVisiveisPara(Builder $q, User $user): Builder
    {
        $atende = self::atende($user);
        $triagem = self::fazTriagem($user);
        if (! $atende && ! $triagem) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(function (Builder $q) use ($user, $atende, $triagem) {
            if ($atende) {
                $user->visaoGlobal() ? $q->whereNotNull('whatsapp_conversas.unidade_id') : $q->whereIn('whatsapp_conversas.unidade_id', $user->unidadeIds());
            }
            if ($triagem) {
                $q->orWhereNull('whatsapp_conversas.unidade_id');
            }
        });
    }

    public static function atende(User $user): bool
    {
        return $user->papel !== Papel::Mecanico && $user->pode('atender_whatsapp');
    }

    public static function fazTriagem(User $user): bool
    {
        return $user->papel !== Papel::Mecanico && $user->pode('triagem_whatsapp');
    }

    public static function usaCentral(User $user): bool
    {
        return self::atende($user) || self::fazTriagem($user);
    }

    public function visivelPara(User $user): bool
    {
        if ($this->emTriagem()) {
            return self::fazTriagem($user);
        }

        return self::atende($user) && $user->acessaUnidade($this->unidade_id);
    }

    /** Telefone completo só para o responsável pela conversa ou gestores; demais veem mascarado. */
    public function telefonePara(User $user): string
    {
        $completo = Normaliza::telefoneExibicao($this->contato_telefone);
        if ($this->responsavel_id === $user->id || $user->pode('ver_auditoria')) {
            return $completo;
        }

        return self::mascarar($this->contato_telefone);
    }

    public static function mascarar(string $digitos): string
    {
        $fim = substr($digitos, -4);
        $ddd = strlen($digitos) >= 10 ? '('.substr($digitos, 0, 2).') ' : '';

        return $ddd.'•••••-'.$fim;
    }

    public function nomeExibicao(): string
    {
        return $this->cliente?->nome ?? $this->contato_nome ?? 'Contato sem nome';
    }

    public function iniciais(): string
    {
        $partes = preg_split('/\s+/', trim($this->nomeExibicao())) ?: ['?'];

        return mb_strtoupper(mb_substr($partes[0], 0, 1).(count($partes) > 1 ? mb_substr(end($partes), 0, 1) : ''));
    }
}
