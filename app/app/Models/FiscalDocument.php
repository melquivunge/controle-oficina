<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalDocument extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['autorizado_em' => 'datetime', 'cancelado_em' => 'datetime'];
    }

    public function ordem(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ordem::class);
    }

    public function rotuloStatus(): string
    {
        return ['pendente' => 'Pendente', 'processando' => 'Processando', 'autorizado' => 'Autorizado', 'rejeitado' => 'Rejeitado', 'cancelado' => 'Cancelado'][$this->status];
    }
}
