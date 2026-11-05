<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Divergencia extends Model
{
    protected $table = 'divergencias';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['resolvido_em' => 'datetime'];
    }

    public function ordem(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ordem::class);
    }

    public function revisor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'revisor_id');
    }

    public function resolvidoPor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'resolvido_por');
    }

    public function rotulo(): string
    {
        return $this->tipo === 'veiculo_sem_ordem' ? 'Veículo no pátio sem ordem aberta' : 'Ordem aberta sem veículo no pátio';
    }
}
