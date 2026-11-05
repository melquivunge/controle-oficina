---
name: Controle de Oficina
description: Sistema operacional de uma rede de oficinas e estética automotiva (balcão, mecânico no celular, dono no painel).
colors:
  navy-sidebar: "#0f1b2d"
  navy-ativo: "#1d3150"
  navy-hover: "#16253b"
  nav-texto: "#c7d1de"
  nav-rotulo: "#8d9bb1"
  verde-marca: "#1f9d6b"
  verde-claro: "#3ccf91"
  verde-texto: "#157a52"
  fundo: "#f3f5f9"
  superficie: "#ffffff"
  borda: "#e4e8ef"
  borda-controle: "#d8dee8"
  texto: "#24262a"
  texto-apoio: "#667085"
  rotulo-campo: "#344054"
  link: "#2563c4"
  foco: "#3b6fd0"
  sucesso-fundo: "#e3f6ee"
  sucesso-texto: "#146c4a"
  alerta-fundo: "#fff4dc"
  alerta-texto: "#93590b"
  erro-fundo: "#fdecea"
  erro-texto: "#b42318"
  info-fundo: "#eaf2ff"
  info-texto: "#1d4f8f"
  neutro-fundo: "#eef1f6"
  neutro-texto: "#344054"
  concluido-fundo: "#e8eef7"
  concluido-texto: "#1d3150"
typography:
  headline:
    fontFamily: "Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif"
    fontSize: "26px"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "-0.027em"
  value:
    fontFamily: "inherit"
    fontSize: "24px"
    fontWeight: 700
    fontFeature: "tnum"
  title:
    fontSize: "15px"
    fontWeight: 600
  body:
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.6
  support:
    fontSize: "13px"
    fontWeight: 400
  meta:
    fontSize: "12px"
    fontWeight: 500
  label:
    fontSize: "11px"
    fontWeight: 600
    letterSpacing: "0.05em"
  plate:
    fontFamily: "ui-monospace, SFMono-Regular, Menlo, monospace"
    fontSize: "12px"
    fontWeight: 600
    letterSpacing: "0.6px"
rounded:
  controle: "10px"
  cartao: "14px"
  pilula: "99px"
spacing:
  "1": "4px"
  "2": "8px"
  "3": "12px"
  "4": "16px"
  "5": "20px"
  "6": "24px"
  "8": "32px"
  "10": "40px"
components:
  button:
    backgroundColor: "{colors.superficie}"
    textColor: "{colors.texto}"
    rounded: "{rounded.controle}"
    padding: "10px 16px"
    height: "40px (44px no celular)"
  button-primary:
    backgroundColor: "{colors.navy-sidebar}"
    textColor: "{colors.superficie}"
    rounded: "{rounded.controle}"
  button-primary-hover:
    backgroundColor: "{colors.navy-ativo}"
  button-perigo:
    backgroundColor: "{colors.superficie}"
    textColor: "{colors.erro-texto}"
  card:
    backgroundColor: "{colors.superficie}"
    rounded: "{rounded.cartao}"
    padding: "16px–24px"
  status-pronto:
    backgroundColor: "{colors.sucesso-fundo}"
    textColor: "{colors.sucesso-texto}"
    rounded: "{rounded.pilula}"
  status-entregue:
    backgroundColor: "{colors.concluido-fundo}"
    textColor: "{colors.concluido-texto}"
    rounded: "{rounded.pilula}"
---

# Design System: Controle de Oficina

Fonte da verdade no código: camada final "Refinamento de design (impeccable, 31/10/2026)" no fim de `app/resources/css/app.css` (tokens `--esp-*`, `--fs-*`, `--raio-*`, cores semânticas). Camadas anteriores do arquivo continuam valendo onde a camada final não as substitui.

## Overview

Ferramenta de balcão e de oficina, no modo Operate: o usuário quer terminar a tarefa (abrir OS, receber, ligar no pós-venda), não admirar a tela. A identidade foi aprovada pelo dono: menu lateral navy escuro agrupado, conteúdo claro em cartões brancos arredondados, verde da marca usado com parcimônia (referências "Loud Pro" e "Coinspace" em `referencia design dashboard/`). A marca aparece nos detalhes precisos (placa em monoespaçada, pílulas de situação, número tabular), não em decoração.

## Colors

- **Navy do menu** (#0f1b2d): menu lateral, botão primário, topo do portal e do login. Hover #16253b, item ativo #1d3150.
- **Verde da marca** (#1f9d6b / #3ccf91): marca, ícone, pontos de situação "Pronto". Em texto sobre branco use **#157a52** (5,1:1). #1f9d6b em texto pequeno reprova (3,45:1).
- **Fundo** #f3f5f9, **superfície** #fff, **borda** #e4e8ef, **borda de controle** #d8dee8.
- **Texto** #24262a, **apoio** #667085, **rótulo de campo** #344054. Rótulos no menu escuro usam #8d9bb1 (≥4,5:1 sobre #132239).
- **Link** #2563c4 (5,2:1). **Foco** #3b6fd0, sempre anel de 2px.
- **Estados** (fundo / texto, todos AA): sucesso #e3f6ee/#146c4a; alerta #fff4dc/#93590b; erro #fdecea/#b42318; info #eaf2ff/#1d4f8f; neutro #eef1f6/#344054; concluído #e8eef7/#1d3150.
- **Semântica das situações da OS**: verde significa "pronto, chame o cliente" (Pronto para entrega). Entregue é "concluído" (navy neutro). Cancelado usa erro. Entrada e Aguardando aprovação usam alerta. As demais (diagnóstico, autorizado, em execução) ficam neutras.
- Roxo e azul não carregam significado: em gráficos do painel, `--roxo` passou a navy (#1d3150) e `--azul` virou o azul de link.

## Typography

- Família: `Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`. **Inter é empacotada** (dependência `@fontsource/inter`, OFL, aprovada em 31/10/2026): só o subconjunto latin, pesos 400/500/600/700, `font-display: swap`, importada no topo de `app.css` e incluída pelo build do Vite na imagem Docker. Sem CDN.
- Escala de papéis, sem meio pixel: 11 (rótulo em caixa alta, cabeçalho de tabela), 12 (metadados, pílulas), 13 (apoio, botões), 14 (corpo), 15 (título de cartão; botão no celular), 16 (campos no celular, sem zoom no iOS), 20/22 (títulos de seção no painel), 24 (valor), 26 (h1).
- Pesos: só 400, 500, 600, 700.
- `font-variant-numeric: tabular-nums` no corpo inteiro (`body.app`, `body.guest`, portal): valores em R$, horas e contagens alinham em coluna.
- Placa: monoespaçada, 600, espaçamento de 0,6px. É dado, não decoração.

## Layout

- Espaçamento em base 4: 4, 8, 12, 16, 20, 24, 32, 40 (`--esp-1` a `--esp-10`). Grupos apertados (8–12), separação entre grupos de 16–24.
- Menu lateral de 252px. Abaixo de 850px o menu vira gaveta com hambúrguer. Conteúdo com até 1376px.
- Listas de ordens: cabeçalho e linhas usam **a mesma grade** (`minmax(180px,1.4fr) minmax(150px,1fr) minmax(170px,1fr) 104px`). A linha inteira abre a OS; "Ver ordem" é um link discreto com seta.
- Tabelas no celular (≤767px) viram um cartão por registro: o cartão é o `tr`. `td` sem padding e sem borda (nada de cartão dentro de cartão); ações empilhadas com 8px de intervalo.
- Grades lado a lado (`.catalogo-paineis`, `.painel-grade`, `.kpis`…) não herdam a margem de seções empilhadas.
- Sem rolagem horizontal da página em 360–1440px. Tabelas largas rolam dentro do `.table-wrap`.

## Elevation & Depth

Interface plana com cartões: borda de 1px #e4e8ef mais sombra mínima `0 1px 2px rgba(15,27,45,.04)`. Elevação maior só em popovers e no hover das fichas de cliente. Sem halos coloridos.

## Shapes

- Cartões e painéis: **14px** (`--raio-cartao`), um raio só.
- Botões e campos: 10px. Pílulas de situação e etiquetas: 99px.
- Faixas laterais coloridas não são usadas em cartões. Exceções que carregam estado: pendência crítica/atenção, item selecionado ou atrasado na Central de WhatsApp.

## Components

- **Botão**: 40px de altura no desktop e 44px no celular, padding 10/16, peso 600, 13px. Primário navy. **Perigo** (`.button.perigo`): texto #b42318 e borda #f3c7c1, para "Cancelar ordem". O resumo "Cancelar este documento" também usa a cor de erro.
- **Pílula de situação** (`.status` + `wait|ready|done|off`): ponto colorido mais texto de 12px.
- **Linha de lista** (`.queue-row`): 68px no mínimo. Atrasada ganha fundo #fffaf8, e o texto "Atrasada" diz o estado (a cor nunca é o único sinal).
- **Pós-venda**: o número da OS é o link para a OS. As ações por linha são "Registrar contato" (primário) e "WhatsApp". O serviço fica limitado a 2 linhas.
- **Documento fiscal**: cartão com borda uniforme, estado na pílula. Cancelados aparecem em tom recuado.
- **Abas da OS**: no celular, a barra rola com dica de esmaecimento à direita e a aba atual é rolada para a vista.
- **Foco**: anel de 2px #3b6fd0 em links, botões e summary (`:focus-visible`), e em campos (`:focus`; data/hora também em `:focus-within`, porque o ícone do calendário fica no shadow DOM).

## Temas (31/10/2026)

- **Claro** é o padrão e continua idêntico ao aprovado (comparação pixel a pixel antes/depois sem a fonte: diferença só nos contadores, na seta da ficha e nos números de atenção, corrigidos por contraste).
- **Escuro**: navy profundo da mesma família do menu. Fundo #0b1220, superfície #131c2c, degrau #182336, neutro #1e2a3f, borda #263349; texto #e5e9f0 / apoio #9ba7b9; link e foco #86b4ff. Estados (fundo/texto): sucesso #0f2e23/#74e0b0, alerta #33260b/#f4c26b, erro #3a1816/#ffa094, info #142a4b/#a3c6ff. Botão primário no escuro: verde claro da marca #3ccf91 com texto #0b1a14.
- **Seguir o aparelho** (`data-tema="sistema"`): igual ao escuro quando `prefers-color-scheme: dark`.
- **Mecânica** (fim de `app.css`): as cores fixas das camadas antigas viraram `var(--t-PAPEL,#valor-claro)`; as `--t-*` só existem com o interruptor `--escuro` ligado (técnica "space toggle"), ligado por `data-tema`. A paleta escura é declarada uma vez. `color-scheme` acompanha (controles nativos).
- **Personalizado**: o servidor manda `--marca`, `--marca-contraste`, `--menu-fundo`, `--menu-texto`. Menu usa a cor de texto calculada para itens e rótulos (misturas reduzem contraste em menus de tom médio); hover e ativo são degraus com `color-mix()`. Botão primário e quadrado da marca usam a marca.
- Portal do cliente e OS impressa ficam sempre claros. Logo da empresa no quadrado da marca: fundo branco, `object-fit: contain`.
- Novas cores de tema: só nos tokens `--t-*`, nunca hex solto em regra.

## Do's and Don'ts

- Faça: use os tokens da camada final. Mantenha alvos de 44px no celular (botões, rádio pelo `label`, links soltos). Mantenha números tabulares. Mantenha estado com texto e cor juntos.
- Faça: confira contraste ≥4,5:1 em texto pequeno, inclusive sobre o navy.
- Não faça: tamanhos com meio pixel, pesos 450/550/650, novos hex fora da paleta, raio de cartão diferente de 14px.
- Não faça: botão repetido em cada linha quando a linha inteira já é o link. Um mesmo verde para "pronto" e "entregue". Remover o contorno de foco sem um substituto visível.
- Não faça: carregar fontes por CDN ou instalar algo no host. Toda build roda em Docker.
