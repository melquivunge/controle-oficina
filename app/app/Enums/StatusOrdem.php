<?php

namespace App\Enums;

enum StatusOrdem: string
{
    case Entrada = 'entrada';
    case Diagnostico = 'diagnostico';
    case AguardandoAprovacao = 'aguardando_aprovacao';
    case Autorizado = 'autorizado';
    case EmExecucao = 'em_execucao';
    case ProntoParaEntrega = 'pronto_para_entrega';
    case Entregue = 'entregue';
    case Cancelado = 'cancelado';

    public function rotulo(): string
    {
        return match ($this) {
            self::Entrada => 'Aguardando diagnóstico',
            self::Diagnostico => 'Em diagnóstico',
            self::AguardandoAprovacao => 'Aguardando aprovação',
            self::Autorizado => 'Autorizado',
            self::EmExecucao => 'Em execução',
            self::ProntoParaEntrega => 'Pronto para entrega',
            self::Entregue => 'Entregue',
            self::Cancelado => 'Cancelado',
        };
    }

    /** Classe visual do protótipo. */
    public function classe(): string
    {
        return match ($this) {
            self::Entrada, self::AguardandoAprovacao => 'wait',
            self::ProntoParaEntrega => 'ready',
            self::Entregue => 'done',
            self::Cancelado => 'off',
            default => '',
        };
    }

    /** Transições permitidas. Qualquer outra é rejeitada no servidor. */
    public function podeIrPara(self $destino): bool
    {
        return in_array($destino, match ($this) {
            self::Entrada => [self::Diagnostico, self::Cancelado],
            self::Diagnostico => [self::AguardandoAprovacao, self::Cancelado],
            self::AguardandoAprovacao => [self::Autorizado, self::Diagnostico, self::Cancelado],
            self::Autorizado => [self::EmExecucao, self::AguardandoAprovacao, self::Cancelado],
            self::EmExecucao => [self::ProntoParaEntrega, self::Cancelado],
            self::ProntoParaEntrega => [self::Entregue, self::EmExecucao, self::Cancelado],
            self::Entregue, self::Cancelado => [],
        }, true);
    }

    public function encerrada(): bool
    {
        return in_array($this, [self::Entregue, self::Cancelado], true);
    }

    /** Aprovadas e não canceladas: entrega com exceção encerra a operação, mas o saldo continua cobrável. */
    public static function recebiveis(): array
    {
        return [self::Autorizado, self::EmExecucao, self::ProntoParaEntrega, self::Entregue];
    }

    /** @return list<self> */
    public static function abertas(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => ! $s->encerrada()));
    }
}
