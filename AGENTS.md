# Instruções do projeto

Leia os quatro arquivos em contexto antes de implementar. São especificações, não funcionalidades existentes.
Leia .devkit/core/CORE.md, .devkit/PROJECT.md e .devkit/core/ROUTING.md. Carregue somente os procedimentos pertinentes.
Preserve as regras e documentos existentes. Não modifique o toolkit central.
Todo runtime, build e teste da aplicação deve executar em Docker. Nunca carregar demo no startup.
Autorizações por unidade são verificadas no servidor. Integrações demo nunca enviam pagamentos, mensagens ou documentos reais.
Registre evidências e pendências por etapa em .devkit/STATE.md. Não declare o MVP pronto com módulos pendentes.

Toolkit fixado em 0.1.0; origem, arquivos e baseline em `.devkit/manifest.json`.
Comandos Docker validados em 30/10/2026: ver `README.md` (subir, testes, demo, backup). Consulte `.devkit/STATE.md` antes de retomar.

## Economia de contexto sem perda de regras
- Economia de tokens nunca autoriza remover, enfraquecer ou rebaixar regras, requisitos, prioridades ou verificações obrigatórias.
- Faça a leitura inicial dos quatro documentos exigida pelo usuário. Enquanto essa leitura permanecer no contexto, reutilize-a; releia seções pertinentes quando houver mudança, dúvida ou perda do contexto necessário.
- Consulte `.devkit/INDEX.md` para localizar a fonte de cada assunto. Todas as regras aplicáveis continuam obrigatórias, mesmo fora do trecho consultado.
- Use buscas direcionadas, saídas limitadas e referências em vez de repetir documentos inteiros. Não carregue módulos ou skills redundantes para a mesma orientação.
- Preserve verificações obrigatórias; repita-as quando mudanças, falhas ou riscos justificarem. Registre evidências e pendências de forma curta, sem transcrever conversas.
- Não alegue percentual de economia sem medição. Não altere modelo ou configuração de cobrança por iniciativa própria.
- As instruções de instalação no host presentes no scaffold Laravel conflitam com a exigência explícita de Docker. Essa exigência do usuário prevalece; nenhuma instalação no host é autorizada por esses arquivos.

## Skills e agentes especializados
- O usuário autoriza delegação pertinente e exige especialização nas tarefas que dela necessitam. Economia de tokens não justifica dispensar especialistas ou reduzir qualidade, escopo e critérios de aceite.
- Antes de cada etapa, avaliar as skills disponíveis e aplicar as pertinentes, lendo suas instruções. Design e frontend exigem consulta às skills especializadas adequadas disponíveis; registrar lacunas se não existirem.
- Delegar subtarefas que necessitem especialização a agentes adequados (por exemplo, design/UX, frontend, segurança ou testes), com objetivo, responsabilidade e critérios claros. Não criar agentes apenas para cumprir quantidade.
- O agente principal fornece contexto suficiente e direcionado, evita exploração duplicada, integra os resultados e verifica a entrega. Delegação não substitui validação nem transfere a responsabilidade final.
- Registrar por etapa as skills utilizadas, especialidades delegadas e evidências relevantes. Preservar as regras do ambiente e todas as exigências anteriores.

## Conclusão sustentada por evidências
- Não declarar bug resolvido ou funcionalidade concluída apenas porque o código foi alterado. Exigir verificação executada do comportamento e dos critérios de aceite afetados.
- Para bugs, reproduzir o sintoma e, quando viável, demonstrar um teste que falha antes e passa após a correção. Se a reprodução não for possível, registrar a limitação; não alegar comprovação definitiva.
- Executar testes relevantes dentro de Docker, com dados isolados. Incluir regressão automatizada para comportamento relevante e testes dos fluxos críticos exigidos nos documentos.
- Relatar: comportamento verificado, comando efetivamente executado, resultado observado e evidência localizável, além de limitações e verificações pendentes. Guardar saídas relevantes sem segredos; evitar logs extensos sem necessidade.
- Verificações devem corresponder à alegação: build/lint não comprovam funcionamento; inspeção de código não é teste executado; screenshot não comprova autorização no servidor; simulador não comprova integração real.
- Se testes não puderem executar ou falharem, apresentar como implementado mas não validado, ou como falha pendente, conforme o caso. Não esconder falhas, desativar testes para obter aprovação ou inventar resultados.
- Economia de tokens e relatos de subagentes não dispensam evidências. Conferir os resultados pertinentes antes de comunicar conclusão.

## Diagnóstico causal antes de corrigir
- Não apresentar suposição como causa. Distinguir fatos observados, hipóteses em investigação e causas demonstradas.
- Antes de corrigir, reunir evidências do sintoma, ambiente e caminho executado; formular hipóteses verificáveis e usar experimentos direcionados para isolá-las. Não editar código por tentativa aleatória.
- Sustentar a causa com uma cadeia verificável entre condição desencadeadora, mecanismo da falha e sintoma. Sempre que viável, reproduzir e alterar uma variável por vez para confirmar a relação causal.
- Um teste que passa depois da edição não comprova sozinho a causa. Mostrar por que a alteração corrige o mecanismo identificado e verificar regressões relevantes.
- Não acumular remendos, repetir tentativas idênticas ou ampliar refatorações sem nova evidência. Quando uma hipótese for refutada, registrar sucintamente o resultado para evitar retrabalho.
- Se faltarem dados para demonstrar a causa, informar causa ainda não confirmada e identificar a menor observação ou experimento necessário. Não prometer certeza inexistente nem declarar investigação concluída.
- No relato de correção, incluir causa demonstrada, evidência, mudança aplicada, testes e limitações. Mitigação temporária deve ser identificada como mitigação, não como resolução da causa.

## Entrega por funcionalidade completa
- Planejar e entregar funcionalidades completas, com escopo e critérios de aceite definidos antes da implementação. Concluir a funcionalidade atual antes de iniciar outra independente.
- Subtarefas técnicas e delegação são organização interna, não entregas incompletas apresentadas como concluídas. Evitar proliferação de etapas 1A/1B/1C ou expansão indefinida do plano.
- A conclusão abrange interface aplicável, regras de domínio, persistência, autorização, erros, auditoria exigida e testes com evidências. Vincular a entrega aos requisitos pertinentes.
- Identificar dependências antes de começar; implementar os pré-requisitos necessários no fluxo de trabalho sem esconder pendências. Se houver bloqueio externo real, explicitar o impedimento e alinhar mudança de prioridade, sem declarar a funcionalidade pronta.
- Novas descobertas necessárias ao aceite pertencem à funcionalidade; melhorias independentes ficam para depois. Não reduzir o escopo acordado para encerrar uma etapa nem aumentá-lo silenciosamente.
- Manter atualizações breves durante o trabalho, mas reservar declaração de entrega para o fluxo completo verificado. Os marcos técnicos obrigatórios, como ambiente Docker, continuam com seus próprios critérios de aceite.
