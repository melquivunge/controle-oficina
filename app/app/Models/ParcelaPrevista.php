<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parcela prevista de um combinado. Não guarda situação: "paga/parcial/vencida/aberta" é derivada
 * dos recebimentos efetivos por App\Services\Combinados (atributos transitórios pago_centavos/situacao).
 */
class ParcelaPrevista extends Model
{
    public const MEIOS = ['pix' => 'Pix', 'dinheiro' => 'Dinheiro', 'cartao_externo' => 'Cartão (maquininha)'];

    public const SITUACOES = ['aberta' => 'Em aberto', 'parcial' => 'Parcialmente paga', 'paga' => 'Paga', 'vencida' => 'Vencida'];

    protected $table = 'parcelas_previstas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['vencimento' => 'date'];
    }

    public function combinado(): BelongsTo
    {
        return $this->belongsTo(CombinadoPagamento::class, 'combinado_id');
    }

    public function ordem(): BelongsTo
    {
        return $this->belongsTo(Ordem::class);
    }

    public function emAbertoCentavos(): int
    {
        return max(0, (int) $this->valor_centavos - (int) ($this->pago_centavos ?? 0));
    }

    public function rotuloSituacao(): string
    {
        return self::SITUACOES[$this->situacao ?? 'aberta'];
    }

    public function rotuloMeio(): ?string
    {
        return $this->meio_previsto ? self::MEIOS[$this->meio_previsto] : null;
    }
}
