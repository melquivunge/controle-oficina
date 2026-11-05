<?php

namespace App\Enums;

enum Papel: string
{
    case Proprietario = 'proprietario';
    case Gerente = 'gerente';
    // Supervisor multiunidade (decisões de 30/10/2026): visão de gerente nas unidades de usuario_unidades.
    case Supervisor = 'supervisor';
    case Recepcao = 'recepcao';
    case Mecanico = 'mecanico';

    /** Permissões que o proprietário pode conceder individualmente a outros papéis (financeiras e Central de WhatsApp). */
    public const PERMISSOES_CONCEDIVEIS = [
        'aplicar_desconto' => 'Aplicar descontos',
        'cancelar_ordem' => 'Cancelar ordens',
        'registrar_devolucao' => 'Registrar devoluções',
        'liberar_sem_quitacao' => 'Liberar veículo sem quitação',
        'combinar_pagamento' => 'Combinar pagamento do saldo (parcelas com vencimento)',
        // Central de WhatsApp (RF23). Mecânico nunca acessa a Central, mesmo com concessão (conferido no servidor).
        'triagem_whatsapp' => 'WhatsApp: triagem do número central',
        'atender_whatsapp' => 'WhatsApp: atender conversas da unidade',
        // Catálogo (31/10/2026): aprovar desconto acima do limite (padrão só Proprietário; supervisor/gerente podem receber).
        'aprovar_desconto' => 'Aprovar desconto acima do limite do catálogo',
    ];

    /**
     * Bloco A (decisões de 30/10/2026): colaborador/mecânico nunca vê preço, valores, pagamentos nem contato do cliente.
     * Estas permissões não são concedíveis e são negadas ao mecânico mesmo que alguém as inclua em permissoes_extras.
     */
    public const VEDADAS_AO_MECANICO = ['ver_valores', 'ver_contato_cliente', 'pos_venda'];

    public function rotulo(): string
    {
        return match ($this) {
            self::Proprietario => 'Proprietário',
            self::Gerente => 'Gerente',
            self::Supervisor => 'Supervisor',
            self::Recepcao => 'Recepção/caixa',
            self::Mecanico => 'Mecânico',
        };
    }

    /** Permissões do papel. Negar por padrão: o que não está aqui não é permitido. */
    public function permissoes(): array
    {
        return match ($this) {
            self::Proprietario => [
                'ver_todas_unidades', 'configurar', 'ver_auditoria', 'mesclar_clientes', 'fechar_caixa', 'resolver_divergencias',
                'registrar_entrada', 'orcar', 'cobrar', 'receber_manual', 'entregar', 'executar', 'conferir',
                'aplicar_desconto', 'cancelar_ordem', 'registrar_devolucao', 'liberar_sem_quitacao', 'combinar_pagamento',
                'atender_whatsapp', 'triagem_whatsapp', 'ver_valores', 'ver_contato_cliente',
                'gerir_catalogo', 'aprovar_desconto',
                'pos_venda',
            ],
            self::Gerente => [
                'ver_auditoria', 'mesclar_clientes', 'fechar_caixa', 'resolver_divergencias', 'registrar_entrada', 'orcar', 'cobrar', 'receber_manual', 'entregar', 'executar', 'conferir',
                'aplicar_desconto', 'cancelar_ordem', 'registrar_devolucao', 'liberar_sem_quitacao', 'combinar_pagamento', 'atender_whatsapp',
                'ver_valores', 'ver_contato_cliente', 'gerir_catalogo',
                'pos_venda', // pós-venda: ligar para clientes atendidos (mecânico nunca)
            ],
            // Só gerente/proprietário orçam (decisão de 30/10/2026): recepção vê valores e informa o cliente, não orça.
            self::Recepcao => ['registrar_entrada', 'cobrar', 'receber_manual', 'entregar', 'conferir', 'atender_whatsapp', 'ver_valores', 'ver_contato_cliente', 'pos_venda'],
            self::Mecanico => ['executar'],
            // Mesmas permissões do gerente (inclusive as que vierem a ser acrescentadas a ele) + feriados locais das suas unidades.
            self::Supervisor => [...self::Gerente->permissoes(), 'gerir_feriados'],
        };
    }
}
