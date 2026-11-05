<?php

namespace App\Models;

use App\Enums\Papel;
use App\Enums\StatusOrdem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ordem extends Model
{
    use HasFactory;

    protected $table = 'ordens';

    // Status, unidade e número só mudam pelos serviços de domínio.
    protected $fillable = ['motivo', 'quilometragem', 'observacoes'];

    protected function casts(): array
    {
        return [
            'status' => StatusOrdem::class,
            'entrada_em' => 'datetime',
            'contingencia' => 'boolean',
            'entrega_prevista_em' => 'immutable_date',
            'entrega_prevista_definida_em' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'numero';
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function veiculo(): BelongsTo
    {
        return $this->belongsTo(Veiculo::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function mecanico(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mecanico_id');
    }

    /** Vendedor (gerente/proprietário que fechou a venda); ver App\Services\Vendedores. */
    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }

    /** Entrada sem veículo/placa (só o jogo de rodas ou a peça): identificada por item_descricao. */
    public function semVeiculo(): bool
    {
        return $this->veiculo_id === null;
    }

    /** Placa formatada ou, sem veículo, a descrição do item (para listas, fila, mensagens e busca visual). */
    public function identificacao(): string
    {
        return $this->veiculo ? $this->veiculo->placaFormatada() : (string) $this->item_descricao;
    }

    public function prazoDefinidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entrega_prevista_por');
    }

    /** Prazo de entrega vencido e ainda não pronta/entregue/cancelada. */
    public function entregaAtrasada(): bool
    {
        return \App\Services\PrazosEntrega::atrasada($this);
    }

    public function orcamentos(): HasMany
    {
        return $this->hasMany(Orcamento::class)->orderByDesc('versao');
    }

    public function orcamentoAprovado(): HasOne
    {
        return $this->hasOne(Orcamento::class)->where('status', 'aprovado');
    }

    public function cobrancas(): HasMany
    {
        return $this->hasMany(Cobranca::class)->orderByDesc('id');
    }

    public function pagamentos(): HasMany
    {
        return $this->hasMany(Pagamento::class)->orderBy('recebido_em');
    }

    public function entrega(): HasOne
    {
        return $this->hasOne(Entrega::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(LinkCliente::class);
    }

    public function anexos(): HasMany
    {
        return $this->hasMany(Anexo::class);
    }

    /** Escopo de unidade e, para mecânico, apenas fila própria ou sem responsável. */
    public function scopeVisiveisPara(Builder $q, User $user): Builder
    {
        if (! $user->visaoGlobal()) {
            $q->whereIn('ordens.unidade_id', $user->unidadeIds()); // supervisor: várias unidades
        }
        if ($user->papel === Papel::Mecanico) {
            $q->where(fn (Builder $q) => $q->where('mecanico_id', $user->id)->orWhereNull('mecanico_id'));
        }

        return $q;
    }
}
