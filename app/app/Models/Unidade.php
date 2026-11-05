<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unidade extends Model
{
    use HasFactory;

    protected $table = 'unidades';

    protected $fillable = ['codigo', 'nome', 'ativa'];

    protected function casts(): array
    {
        return ['ativa' => 'boolean'];
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function recebedores(): HasMany
    {
        return $this->hasMany(Recebedor::class);
    }

    public function scopeVisiveisPara(Builder $query, User $user): Builder
    {
        return $user->visaoGlobal() ? $query : $query->whereKey($user->unidadeIds());
    }
}
