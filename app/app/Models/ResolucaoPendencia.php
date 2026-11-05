<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResolucaoPendencia extends Model
{
    protected $table = 'resolucoes_pendencias';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['resolvido_em' => 'datetime'];
    }

    public function resolvidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolvido_por');
    }
}
