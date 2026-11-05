# Roteiro de demonstração

Dados fictícios de 30/09/2026 a 29/10/2026 (referência `OFICINA_DEMO_REFERENCIA=2026-10-29`), três unidades (ASN, TAG, AGC).
Banco separado `oficina_demo`. Nenhum pagamento, mensagem ou documento fiscal é real: tudo usa simuladores e é marcado DEMONSTRAÇÃO / SEM VALIDADE FISCAL.

## Preparar
```sh
docker compose exec app php artisan demo:load      # explícito e idempotente; repetir não duplica
# para navegar na demo:
OFICINA_BANCO=demo docker compose up -d            # ou defina OFICINA_BANCO=demo no .env
# para voltar ao banco principal:
docker compose up -d                               # com OFICINA_BANCO=principal no .env
docker compose exec app php artisan demo:reset     # apaga e recarrega SOMENTE a demo; pede "RESETAR-DEMO"
```
Senha de todas as contas fictícias: `demo2026oficina`. Proprietária (`dono@demo.oficina.test`) usa verificação em duas etapas; a chave aparece no resumo do `demo:load`.

## Percurso sugerido (15 min)
1. **Proprietária → Painel central**: período 30/09–29/10; entradas/saídas, orçado × aprovado × recebido × a receber; pontos de atenção (descontos, liberações sem quitação, falhas, divergências). Abrir uma unidade.
2. **Histórico de ações**: filtrar "Entrega sem quitação (exceção)" e "Pix em revisão (divergência)".
3. **recepcao.asn → Nova entrada**: cadastrar cliente e placa; ver o cadastro sugerido ao digitar uma placa existente (ex.: abrir Ordens e copiar uma placa DMA…).
4. **mecanico.asn → Minha fila**: iniciar diagnóstico e registrar.
5. **recepcao.asn → Orçamento**: montar itens (quantidade 2,5 mostra o arredondamento), enviar, abrir o link exibido em janela anônima (área do cliente) e aprovar.
6. **Execução** (mecânico): concluir serviços e liberar para entrega; tentar liberar com item pendente (bloqueado).
7. **Pagamento**: gerar cobrança Pix → "Simular pagamento" → confirmação automática pela fila; tentar registrar Pix manualmente (bloqueado).
8. **Entrega**: com saldo, recepção é bloqueada; gerente libera com motivo (gera exceção).
9. **gerente.asn → Caixa e fechamento / Conferência diária / Indicadores** (só a própria unidade).
10. Tentativas de acesso indevido: gerente.asn abrindo ordem de TAG ou `/configuracoes/usuarios` → acesso negado.

Cenários presentes: aprovação (link e presencial), recusa, extras (aprovados e recusados), descontos, parcial, atraso, cancelamento, devolução de valor pago a mais, Pix divergente em revisão, liberação sem quitação, rejeição fiscal simulada, fechamentos com diferença, divergências de conferência abertas e resolvidas.
