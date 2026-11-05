<?php

namespace App\Services;

use App\Enums\Papel;
use App\Models\Cobranca;
use App\Models\Ordem;
use App\Models\Pagamento;
use App\Models\Recebedor;
use App\Models\User;
use App\Support\Fuso;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Parte agora, parte depois" num único envio (decisão 7 da reunião de 30/10/2026).
 * Orquestra os serviços existentes — não duplica regra financeira nem auditoria:
 *  1. Combinados::registrar cria o combinado: parcela 1 = valor de agora (vencimento hoje) + parcelas futuras; soma = saldo (verificado sob lock).
 *  2. Dinheiro/cartão: Financeiro::registrarManual na MESMA transação (falhou um, nada fica gravado).
 *     Pix: Financeiro::gerarCobranca depois do commit do combinado (a chamada ao provedor nunca roda dentro de transação);
 *     a cobrança fica pendente até o provedor confirmar. Conta oficial é verificada antes para não sobrar combinado sem cobrança.
 */
class PagamentoDividido
{
    public const MAX_FUTURAS = 3;

    public function __construct(private Combinados $combinados, private Financeiro $financeiro) {}

    /**
     * @param  array<int, array{valor_centavos:int, vencimento:string, meio_previsto:?string}>  $futuras
     * @return Pagamento|Cobranca  recebimento registrado (dinheiro/cartão) ou cobrança Pix gerada
     */
    public function registrar(Ordem $ordem, User $ator, int $agoraCentavos, string $forma, array $futuras, ?string $observacao = null): Pagamento|Cobranca
    {
        if (! in_array($forma, ['pix', 'dinheiro', 'cartao_externo'], true)) {
            throw ValidationException::withMessages(['forma_agora' => 'Escolha como o cliente paga agora: Pix, dinheiro ou cartão.']);
        }
        $permissaoAgora = $forma === 'pix' ? 'cobrar' : 'receber_manual';
        if ($ator->papel === Papel::Mecanico || ! $ator->pode('combinar_pagamento') || ! $ator->pode($permissaoAgora) || ! $ator->acessaUnidade($ordem->unidade_id)) {
            throw new AuthorizationException('Ação não permitida para esta ordem.');
        }
        if ($agoraCentavos <= 0) {
            throw ValidationException::withMessages(['valor_agora' => 'Informe quanto o cliente paga agora (maior que zero).']);
        }
        if ($futuras === [] || count($futuras) > self::MAX_FUTURAS) {
            throw ValidationException::withMessages(['futuras' => 'Informe de 1 a '.self::MAX_FUTURAS.' datas para o restante.']);
        }
        if ($this->combinados->vigente($ordem)) {
            throw ValidationException::withMessages(['futuras' => 'Esta ordem já tem pagamento combinado. Receba a próxima parcela ou ajuste o combinado.']);
        }
        if ($forma === 'pix' && ! Recebedor::where('unidade_id', $ordem->unidade_id)->where('ativo', true)->exists()) {
            throw ValidationException::withMessages(['forma_agora' => 'A unidade não tem conta oficial de recebimento Pix configurada. Peça ao proprietário.']);
        }
        $parcelas = array_merge(
            [['valor_centavos' => $agoraCentavos, 'vencimento' => Fuso::exibir(now(), 'Y-m-d'), 'meio_previsto' => $forma, 'observacao' => 'Paga na hora']],
            array_map(fn ($p) => $p + ['observacao' => null], array_values($futuras)),
        );

        if ($forma === 'pix') {
            $this->combinados->registrar($ordem, $ator, $parcelas);

            return $this->financeiro->gerarCobranca($ordem, $ator, $agoraCentavos);
        }

        return DB::transaction(function () use ($ordem, $ator, $parcelas, $forma, $agoraCentavos, $observacao) {
            $this->combinados->registrar($ordem, $ator, $parcelas);

            return $this->financeiro->registrarManual($ordem, $ator, $forma, $agoraCentavos, $observacao);
        });
    }
}
