<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Link do cliente: só o hash SHA-256 do token fica no banco. */
class LinkCliente extends Model
{
    protected $table = 'links_cliente';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expira_em' => 'datetime', 'revogado_em' => 'datetime', 'ultimo_acesso_em' => 'datetime'];
    }

    public function ordem(): BelongsTo
    {
        return $this->belongsTo(Ordem::class);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function valido(): bool
    {
        return ! $this->revogado_em && $this->expira_em->isFuture();
    }
}
