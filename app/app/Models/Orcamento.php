<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Orcamento extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enviado_em' => 'datetime'];
    }

    public function ordem(): BelongsTo
    {
        return $this->belongsTo(Ordem::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(OrcamentoItem::class)->orderBy('posicao');
    }

    public function aprovacao(): HasOne
    {
        return $this->hasOne(Aprovacao::class);
    }

    public function aprovacoesInternas(): HasMany
    {
        return $this->hasMany(AprovacaoInterna::class)->orderByDesc('id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function rotuloStatus(): string
    {
        return match ($this->status) {
            'rascunho' => 'Rascunho',
            'enviado' => 'Aguardando aprovação',
            'aprovado' => 'Aprovado',
            'recusado' => 'Recusado',
            'substituido' => 'Substituído por versão mais nova',
        };
    }
}
