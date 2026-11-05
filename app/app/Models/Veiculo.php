<?php

namespace App\Models;

use App\Support\Normaliza;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Veiculo extends Model
{
    use HasFactory;

    protected $fillable = ['placa', 'modelo', 'ano', 'cor'];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function ordens(): HasMany
    {
        return $this->hasMany(Ordem::class);
    }

    public function placaFormatada(): string
    {
        return Normaliza::placaExibicao($this->placa);
    }
}
