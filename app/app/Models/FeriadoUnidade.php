<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Feriado estadual/municipal de uma unidade; somado aos nacionais em App\Support\DiasUteis. */
class FeriadoUnidade extends Model
{
    protected $table = 'feriados_unidade';

    protected $fillable = ['unidade_id', 'data', 'descricao', 'criado_por'];

    protected function casts(): array
    {
        return ['data' => 'date:Y-m-d'];
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }
}
