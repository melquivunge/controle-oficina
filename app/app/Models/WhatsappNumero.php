<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Número oficial de WhatsApp da empresa: central (unidade_id null → triagem) ou de uma unidade. */
class WhatsappNumero extends Model
{
    protected $table = 'whatsapp_numeros';

    protected $guarded = ['id'];

    protected $hidden = ['credenciais'];

    protected function casts(): array
    {
        return ['credenciais' => 'encrypted:array', 'modelos' => 'array', 'ativo' => 'boolean', 'minutos_alerta' => 'integer'];
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function conversas(): HasMany
    {
        return $this->hasMany(WhatsappConversa::class, 'numero_id');
    }

    public function central(): bool
    {
        return $this->unidade_id === null;
    }

    public function credencial(string $chave): ?string
    {
        $v = $this->credenciais[$chave] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    public function temCredenciais(): bool
    {
        return $this->credencial('token') !== null && $this->credencial($this->provedor === 'drope' ? 'signing_secret' : 'app_secret') !== null;
    }

    public function rotuloEscopo(): string
    {
        return $this->central() ? 'Central (triagem)' : ($this->unidade?->nome ?? 'Unidade');
    }

    public function telefoneFormatado(): string
    {
        return \App\Support\Normaliza::telefoneExibicao($this->telefone);
    }
}
