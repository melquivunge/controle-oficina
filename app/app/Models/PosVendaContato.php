<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Contato de pós-venda (tentativa de ligação/mensagem após a entrega). Só é criado por App\Services\PosVenda. */
class PosVendaContato extends Model
{
    protected $table = 'pos_venda_contatos';

    protected $guarded = ['id'];

    public $timestamps = false;

    public const TENTATIVAS = ['ligacao' => 'Ligação', 'mensagem' => 'Mensagem'];

    public const RESULTADOS = ['atendeu' => 'Atendeu', 'nao_atendeu' => 'Não atendeu', 'numero_errado' => 'Número errado'];

    protected function casts(): array
    {
        return [
            'problema_servico' => 'boolean',
            'negociacao_fora_padrao' => 'boolean',
            'retorno_em' => 'immutable_date',
            'registrado_em' => 'datetime',
            'nota' => 'integer',
        ];
    }

    public function ordem(): BelongsTo
    {
        return $this->belongsTo(Ordem::class);
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    /** Mesmo escopo de unidades da aplicação (segue Unidade::visiveisPara, inclusive para supervisor multiunidade). */
    public function scopeVisiveisPara(Builder $q, User $user): Builder
    {
        return $q->whereIn('pos_venda_contatos.unidade_id', Unidade::visiveisPara($user)->select('unidades.id'));
    }

    public function rotuloTentativa(): string
    {
        return self::TENTATIVAS[$this->tentativa] ?? $this->tentativa;
    }

    public function rotuloResultado(): string
    {
        return self::RESULTADOS[$this->resultado] ?? $this->resultado;
    }
}
