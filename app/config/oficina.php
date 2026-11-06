<?php

return [
    // simulado: nenhum pagamento, mensagem ou documento real. real: exige adaptador homologado (ainda não existe).
    'integracoes' => env('OFICINA_INTEGRACOES', 'simulado'),
    'pix' => [
        // Botão "simular pagamento": só com flag explícita (falha fechado). Nunca em ambiente com dados reais.
        'permite_simular_pagamento' => (bool) env('OFICINA_PERMITE_SIMULAR_PAGAMENTO', false),
        'webhook_segredo' => env('OFICINA_PIX_WEBHOOK_SEGREDO'),
        'webhook_url_interna' => env('OFICINA_PIX_WEBHOOK_URL_INTERNA', 'http://app:8080/webhooks/pix/simulado'),
        // Somente simulação: força recusa das devoluções Pix pelo provedor simulado (testes/demonstração).
        'simular_falha_devolucao' => (bool) env('OFICINA_PIX_SIMULAR_FALHA_DEVOLUCAO', false),
        'validade_minutos' => (int) env('OFICINA_PIX_VALIDADE_MINUTOS', 60),
    ],
    // Prazo de entrega em dias úteis (seg–sex). Feriados ignorados na contagem: lista local editável (Y-m-d).
    // Nacionais de 2026–2027, incluindo Carnaval e Corpus Christi (pontos facultativos que a operação costuma fechar).
    // Feriados estaduais/municipais de cada unidade: acrescentar aqui (decisão em aberto na reunião de 30/10/2026).
    'feriados' => [
        '2026-01-01', '2026-02-16', '2026-02-17', '2026-04-03', '2026-04-21', '2026-05-01', '2026-06-04',
        '2026-09-07', '2026-10-12', '2026-11-02', '2026-11-15', '2026-11-20', '2026-12-25',
        '2027-01-01', '2027-02-08', '2027-02-09', '2027-03-26', '2027-04-21', '2027-05-01', '2027-05-27',
        '2027-09-07', '2027-10-12', '2027-11-02', '2027-11-15', '2027-11-20', '2027-12-25',
    ],
    'prazo_entrega_max_dias_uteis' => 120,
    // Catálogo (31/10/2026): desconto máximo sem aprovação interna para itens livres e itens do catálogo sem limite próprio (%).
    'desconto_max_geral_percentual' => env('OFICINA_DESCONTO_MAX_GERAL', '10'),
    // 06/11/2026: quem orça (dono, gerente, supervisor) dá desconto livre. true religa o limite + aprovação interna.
    // Consulta de placa (06/11/2026). 'integrasist' = fonte NÃO oficial (SINESP via terceiro); vazio = simulador fora de produção / indisponível em produção.
    'placa' => [
        'provedor' => env('OFICINA_PLACA_PROVEDOR'),
        'url' => env('OFICINA_PLACA_URL', 'http://consulta.integrasist.com.br:3136'),
    ],
    'desconto_exige_aprovacao' => (bool) env('OFICINA_DESCONTO_EXIGE_APROVACAO', false),
    // Demonstração: datas ancoradas nesta referência (resultados reproduzíveis).
    'demo' => [
        'referencia' => env('OFICINA_DEMO_REFERENCIA', '2026-10-29'),
        'versao' => '1',
        'dias' => (int) env('OFICINA_DEMO_DIAS', 30),
        'senha' => 'demo2026oficina', // somente para contas fictícias do banco de demonstração
    ],
    'fiscal' => [
        'simular_indisponivel' => (bool) env('OFICINA_FISCAL_SIMULAR_INDISPONIVEL', false),
    ],
    // Central de WhatsApp (RF23). Simulado: nada é enviado/recebido de verdade. Adaptador Meta: NÃO homologado com conta real.
    'whatsapp' => [
        'webhook_segredo' => env('OFICINA_WHATSAPP_WEBHOOK_SEGREDO'), // HMAC do webhook do simulador
        'meta_versao' => env('OFICINA_WHATSAPP_META_VERSAO', 'v21.0'),
        'midia_max_bytes' => (int) env('OFICINA_WHATSAPP_MIDIA_MAX_BYTES', 16 * 1024 * 1024),
        'anexo_max_kb' => (int) env('OFICINA_WHATSAPP_ANEXO_MAX_KB', 5120),
    ],
];
