<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Anexo extends Model
{
    protected $guarded = ['id'];

    public function ordem(): BelongsTo
    {
        return $this->belongsTo(Ordem::class);
    }
}
