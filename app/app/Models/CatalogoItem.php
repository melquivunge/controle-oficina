<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Item do catálogo de serviços e produtos. Valores em centavos; limite de desconto em % (string decimal). */
class CatalogoItem extends Model
{
    protected $table = 'catalogo_itens';

    protected $guarded = ['id'];

    public const UNIDADES_VENDA = ['roda' => 'por roda', 'jogo' => 'por jogo', 'unidade' => 'unidade', 'hora' => 'hora'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'preco_centavos' => 'integer'];
    }

    public function adicionais(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'catalogo_adicionais', 'catalogo_item_id', 'adicional_id')->orderBy('nome');
    }

    public function precosUnidade(): HasMany
    {
        return $this->hasMany(CatalogoPrecoUnidade::class);
    }

    public function historico(): HasMany
    {
        return $this->hasMany(CatalogoPrecoHistorico::class)->orderByDesc('alterado_em')->orderByDesc('id');
    }

    /** Preço de tabela na unidade: override da unidade, se houver; senão o padrão. */
    public function precoNaUnidade(?int $unidadeId): int
    {
        if ($unidadeId !== null) {
            $override = $this->relationLoaded('precosUnidade')
                ? $this->precosUnidade->firstWhere('unidade_id', $unidadeId)
                : $this->precosUnidade()->where('unidade_id', $unidadeId)->first();
            if ($override) {
                return (int) $override->preco_centavos;
            }
        }

        return (int) $this->preco_centavos;
    }

    /** Limite efetivo (%): o do item ou, se não definido, a regra geral. */
    public function limiteDesconto(): string
    {
        return bcadd((string) ($this->desconto_max_percentual ?? config('oficina.desconto_max_geral_percentual', '10')), '0', 2);
    }

    public function rotuloUnidade(): string
    {
        return self::UNIDADES_VENDA[$this->unidade_venda] ?? $this->unidade_venda;
    }
}
