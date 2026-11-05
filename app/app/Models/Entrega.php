<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Entrega extends Model
{
    protected $table = 'entregas';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['entregue_em' => 'datetime', 'excecao' => 'boolean'];
    }

    public function combinado(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CombinadoPagamento::class, 'combinado_id');
    }

    public function entreguePor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'entregue_por');
    }
}
