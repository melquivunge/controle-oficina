<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recebedor extends Model
{
    protected $table = 'recebedores';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function unidade(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }
}
