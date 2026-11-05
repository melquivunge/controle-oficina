<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Somente inserção (trigger no banco). */
class CatalogoPrecoHistorico extends Model
{
    protected $table = 'catalogo_precos_historico';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['alterado_em' => 'datetime'];
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'alterado_por');
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }
}
