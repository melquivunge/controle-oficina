<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Devolução de um recebimento. Pix: solicitada ao provedor (processando → concluida | falhou).
 * Dinheiro/cartão externo: registro manual, nasce concluída. Só "concluida" abate valores.
 */
class Devolucao extends Model
{
    public const RESERVAM_LIMITE = ['processando', 'concluida'];

    protected $table = 'devolucoes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['concluida_em' => 'datetime'];
    }

    public function pagamento(): BelongsTo
    {
        return $this->belongsTo(Pagamento::class);
    }

    public function ordem(): BelongsTo
    {
        return $this->belongsTo(Ordem::class);
    }

    public function autorizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizado_por');
    }

    public function rotuloStatus(): string
    {
        return match ($this->status) {
            'processando' => 'Processando no provedor',
            'concluida' => 'Concluída',
            'falhou' => 'Falhou',
            default => $this->status,
        };
    }
}
