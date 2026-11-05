<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Evento de auditoria append-only (o banco também rejeita UPDATE/DELETE). */
class AuditEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['antes' => 'array', 'depois' => 'array', 'ocorrido_em' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Eventos de auditoria não podem ser alterados.'));
        static::deleting(fn () => throw new LogicException('Eventos de auditoria não podem ser removidos.'));
    }

    public function ator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ator_id');
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function scopeVisiveisPara(Builder $query, User $user): Builder
    {
        return $user->restringirUnidades($query, 'unidade_id');
    }
}
