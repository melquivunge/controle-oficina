<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

class Auditoria
{
    /** Campos nunca gravados na auditoria. */
    private const SENSIVEIS = ['password', 'remember_token', 'two_factor_secret', 'token', 'token_hash', 'segredo'];

    public static function registrar(
        string $acao,
        ?Model $entidade = null,
        ?array $antes = null,
        ?array $depois = null,
        ?string $motivo = null,
        ?int $unidadeId = null,
        ?User $ator = null,
        string $atorTipo = 'usuario',
    ): AuditEvent {
        // Ações do cliente (link) nunca herdam o usuário logado no mesmo navegador.
        $ator ??= $atorTipo === 'usuario' ? Auth::user() : null;

        return AuditEvent::create([
            'ocorrido_em' => now(), // relógio da aplicação (UTC), consistente com os demais registros
            'ator_id' => $ator?->id,
            'ator_tipo' => $ator ? $atorTipo : ($atorTipo === 'usuario' ? 'sistema' : $atorTipo),
            'unidade_id' => $unidadeId ?? $entidade?->getAttribute('unidade_id') ?? $ator?->unidade_id,
            'acao' => $acao,
            'entidade_tipo' => $entidade ? Str::snake(class_basename($entidade)) : null,
            'entidade_id' => $entidade?->getKey(),
            'antes' => $antes === null ? null : self::semSensiveis($antes),
            'depois' => $depois === null ? null : self::semSensiveis($depois),
            'motivo' => $motivo,
            'correlacao' => Context::get('correlacao'),
        ]);
    }

    private static function semSensiveis(array $dados): array
    {
        $saida = [];
        foreach ($dados as $chave => $valor) {
            if (! in_array($chave, self::SENSIVEIS, true)) {
                $saida[$chave] = is_array($valor) ? self::semSensiveis($valor) : $valor;
            }
        }

        return $saida;
    }
}
