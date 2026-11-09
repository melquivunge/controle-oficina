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

    protected $fillable = ['placa', 'marca', 'modelo', 'ano', 'cor'];

    protected function casts(): array
    {
        return ['legado' => 'array'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function ordens(): HasMany
    {
        return $this->hasMany(Ordem::class);
    }

    /** "Volkswagen Gol 1.0 · 2018 · Prata" (só o que estiver preenchido). */
    public function descricao(): string
    {
        return collect([trim($this->marca.' '.$this->modelo), $this->ano, $this->cor])->filter()->join(' · ');
    }

    public function placaFormatada(): string
    {
        return Normaliza::placaExibicao($this->placa);
    }
}
