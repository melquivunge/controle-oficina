<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** NF-e de peças sem OS (19/11/2026): venda ou remessa. A emissão é a mesma das notas da OS (App\Services\Fiscal). */
class NotaAvulsa extends Model
{
    protected $table = 'notas_avulsas';

    protected $guarded = ['id'];

    public const OPERACOES = ['venda' => 'Venda', 'remessa' => 'Remessa'];

    /** Como a venda aconteceu: define indPres e o intermediador na NF-e. */
    public const CANAIS = ['presencial' => 'Na loja (presencial)', 'internet' => 'Pela internet (site próprio, WhatsApp)', 'mercado_livre' => 'Mercado Livre'];

    public const FRETES = [9 => 'Sem frete', 0 => 'Por conta de quem envia (loja)', 1 => 'Por conta de quem recebe (cliente)'];

    public const PAGAMENTOS = ['17' => 'Pix', '01' => 'Dinheiro', '03' => 'Cartão de crédito', '04' => 'Cartão de débito', '15' => 'Boleto', '99' => 'Outros', '90' => 'Sem pagamento'];

    protected function casts(): array
    {
        return ['itens' => 'array', 'frete' => 'integer', 'valor_centavos' => 'integer'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(FiscalDocument::class)->orderByDesc('id');
    }

    /** Referência curta impressa na nota e nas telas. */
    public function referencia(): string
    {
        return 'NA-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }
}
