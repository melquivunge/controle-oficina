<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Aprovacao extends Model
{
    protected $table = 'aprovacoes';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['decidido_em' => 'datetime'];
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
