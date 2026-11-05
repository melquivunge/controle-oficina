<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogoPrecoUnidade extends Model
{
    protected $table = 'catalogo_precos_unidade';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['preco_centavos' => 'integer'];
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }
}
