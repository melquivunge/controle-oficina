<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pagamento extends Model
{
    protected $table = 'pagamentos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['recebido_em' => 'datetime', 'revisado_em' => 'datetime'];
    }

    public function ordem(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ordem::class);
    }

    public function registradoPor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function devolucoes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Devolucao::class);
    }

    public function rotuloMeio(): string
    {
        return ['pix' => 'Pix (confirmado pelo banco)', 'dinheiro' => 'Dinheiro (registro manual)', 'cartao_externo' => 'Cartão na maquininha (registro manual)'][$this->meio];
    }

    public function rotuloStatus(): string
    {
        return ['confirmado' => 'Confirmado pelo provedor', 'registrado' => 'Registrado — revisar no fechamento', 'em_revisao' => 'Em revisão'][$this->status];
    }
}
