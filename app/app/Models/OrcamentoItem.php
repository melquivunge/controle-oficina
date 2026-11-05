<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrcamentoItem extends Model
{
    protected $table = 'orcamento_itens';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['extra' => 'boolean', 'concluido_em' => 'datetime'];
    }

    public function orcamento(): BelongsTo
    {
        return $this->belongsTo(Orcamento::class);
    }

    public function catalogoItem(): BelongsTo
    {
        return $this->belongsTo(CatalogoItem::class);
    }

    /** Desconto do item sobre o preço de tabela (%), ou null para item livre / sem desconto. */
    public function descontoPercentual(): ?string
    {
        if ($this->preco_tabela_centavos === null || (int) $this->preco_tabela_centavos === 0 || $this->preco_unitario_centavos >= $this->preco_tabela_centavos) {
            return null;
        }

        return bcdiv(bcmul((string) ($this->preco_tabela_centavos - $this->preco_unitario_centavos), '100', 4), (string) $this->preco_tabela_centavos, 2);
    }

    public function concluidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concluido_por');
    }
}
