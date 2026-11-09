<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Ordem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Unifica um cadastro duplicado no principal SEM PERDER DADOS (09/11/2026):
 * move OS, veículos, conversas de WhatsApp e histórico do sistema antigo; completa campos vazios do principal;
 * guarda no principal uma cópia integral do duplicado (legado._unificados); o duplicado fica marcado, nunca apagado.
 */
class UnificacaoClientes
{
    private const COMPLETAR = ['telefone', 'documento', 'email', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf'];

    /** @return array<string, int> quantidades movidas */
    public function unificar(Cliente $principal, Cliente $duplicado, ?User $ator, string $motivo): array
    {
        return DB::transaction(function () use ($principal, $duplicado, $ator, $motivo) {
            [$destino, $origem] = [Cliente::lockForUpdate()->findOrFail($principal->id), Cliente::lockForUpdate()->findOrFail($duplicado->id)];
            if ($destino->is($origem) || $destino->mesclado_em_id || $origem->mesclado_em_id) {
                throw ValidationException::withMessages(['duplicado_id' => 'Escolha outro cadastro ativo para unificar.']);
            }
            $movidos = [
                'ordens' => Ordem::where('cliente_id', $origem->id)->update(['cliente_id' => $destino->id]),
                'veiculos' => $origem->veiculos()->update(['cliente_id' => $destino->id]),
                'conversas' => DB::table('whatsapp_conversas')->where('cliente_id', $origem->id)->update(['cliente_id' => $destino->id]),
                'historico_antigo' => DB::table('legado_registros')->where('cliente_id', $origem->id)->update(['cliente_id' => $destino->id]),
            ];
            $antes = $destino->only(self::COMPLETAR);
            // Endereço vai em bloco (não mistura rua de um com cidade de outro).
            $semEndereco = ! collect(['cep', 'logradouro', 'numero', 'bairro', 'cidade'])->contains(fn ($c) => filled($destino->{$c}));
            foreach (self::COMPLETAR as $c) {
                $ehEndereco = ! in_array($c, ['telefone', 'documento', 'email'], true);
                if (blank($destino->{$c}) && filled($origem->{$c}) && (! $ehEndereco || $semEndereco)) {
                    $destino->{$c} = $origem->{$c};
                }
            }
            if (filled($origem->observacoes) && ! str_contains((string) $destino->observacoes, $origem->observacoes)) {
                $destino->observacoes = trim($destino->observacoes."\n".$origem->observacoes);
            }
            $legado = $destino->legado ?? [];
            $legado['_unificados'][] = ['cliente_id' => $origem->id, 'unificado_em' => now()->toIso8601String(),
                'cadastro' => $origem->only(['nome', 'telefone', 'documento', 'email', 'observacoes', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'legado_codigo', 'created_at']),
                'legado' => $origem->legado];
            $destino->legado = $legado;
            $destino->save();
            $origem->mesclado_em_id = $destino->id;
            $origem->save();
            Auditoria::registrar('cliente_mesclado', $destino, ['cliente_origem' => $origem->id] + $antes,
                $movidos + ['campos_completados' => array_keys(array_diff_assoc(array_map('strval', $destino->only(self::COMPLETAR)), array_map('strval', $antes)))],
                $motivo, unidadeId: $ator?->unidade_id, ator: $ator);

            return $movidos;
        });
    }
}
