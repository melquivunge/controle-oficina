<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Aprovação interna de desconto acima do limite, por versão (rascunho) de orçamento. */
class AprovacaoInterna extends Model
{
    protected $table = 'aprovacoes_internas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['excessos' => 'array', 'solicitado_em' => 'datetime', 'decidido_em' => 'datetime'];
    }

    public function orcamento(): BelongsTo
    {
        return $this->belongsTo(Orcamento::class);
    }

    public function ordem(): BelongsTo
    {
        return $this->belongsTo(Ordem::class);
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    public function decisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidido_por');
    }

    public function rotuloStatus(): string
    {
        return match ($this->status) {
            'pendente' => 'Aguardando aprovação interna',
            'aprovada' => 'Desconto aprovado',
            'recusada' => 'Desconto recusado',
            'cancelada' => 'Cancelado (orçamento alterado)',
        };
    }
}
