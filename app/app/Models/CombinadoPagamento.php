<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Combinado de pagamento do saldo de uma OS (vigente | substituido | cancelado). */
class CombinadoPagamento extends Model
{
    protected $table = 'combinados_pagamento';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['encerrado_em' => 'datetime'];
    }

    public function parcelas(): HasMany
    {
        return $this->hasMany(ParcelaPrevista::class, 'combinado_id')->orderBy('vencimento')->orderBy('sequencia');
    }

    public function ordem(): BelongsTo
    {
        return $this->belongsTo(Ordem::class);
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }
}
