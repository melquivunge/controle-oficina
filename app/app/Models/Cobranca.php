<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cobranca extends Model
{
    protected $table = 'cobrancas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expira_em' => 'datetime'];
    }

    public function ordem(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ordem::class);
    }

    public function recebedor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Recebedor::class);
    }

    public function rotuloStatus(): string
    {
        return ['criando' => 'Gerando', 'pendente' => 'Aguardando pagamento', 'paga' => 'Paga', 'expirada' => 'Expirada', 'cancelada' => 'Cancelada', 'falha' => 'Falha ao gerar'][$this->status];
    }
}
