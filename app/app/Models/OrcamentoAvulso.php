<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Orçamento sem entrada do veículo. Número da mesma sequência das OS da unidade; vira OS ao dar entrada. */
class OrcamentoAvulso extends Model
{
    protected $table = 'orcamentos_avulsos';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    public const SITUACOES = ['rascunho' => 'Rascunho', 'enviado' => 'Enviado ao cliente', 'aprovado' => 'Aprovado', 'recusado' => 'Recusado',
        'convertido' => 'Virou OS', 'cancelado' => 'Cancelado'];

    public const CANAIS = ['whatsapp' => 'WhatsApp', 'telefone' => 'Telefone', 'presencial' => 'Pessoalmente'];

    protected function casts(): array
    {
        return ['itens' => 'array', 'validade' => 'date', 'link_expira_em' => 'datetime', 'enviado_em' => 'datetime', 'decidido_em' => 'datetime',
            'total_centavos' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'numero';
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function ordem(): BelongsTo
    {
        return $this->belongsTo(Ordem::class);
    }

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(WhatsappConversa::class, 'conversa_id');
    }

    public function scopeVisiveisPara(Builder $q, User $user): Builder
    {
        return $q->when(! $user->visaoGlobal(), fn ($q) => $q->whereIn('unidade_id', $user->unidadeIds()));
    }

    public function rotuloSituacao(): string
    {
        return self::SITUACOES[$this->status] ?? $this->status;
    }

    public function vencido(): bool
    {
        return $this->validade->endOfDay()->isPast();
    }

    public function linkValido(): bool
    {
        return $this->token_hash !== null && $this->link_expira_em?->isFuture() && ! in_array($this->status, ['cancelado', 'rascunho'], true);
    }

    public function veiculoTexto(): string
    {
        return collect([$this->placa ? \App\Support\Normaliza::placaExibicao($this->placa) : null, $this->veiculo_descricao])->filter()->implode(' · ') ?: 'Veículo não informado';
    }
}
