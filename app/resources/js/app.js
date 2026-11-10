// Gaveta de navegação no celular: hambúrguer, fundo, Esc e foco acessível.
const corpo = document.body;
const menu = document.getElementById('menu-lateral');
const abrir = document.querySelector('[data-menu-abrir]');
const fundo = document.querySelector('.menu-fundo');
const movel = window.matchMedia('(max-width: 850px)');

function definir(aberto) {
    if (!menu || !abrir) return;
    corpo.classList.toggle('menu-aberto', aberto);
    abrir.setAttribute('aria-expanded', String(aberto));
    fundo.hidden = !aberto;
    if (movel.matches) menu.inert = !aberto;
    if (aberto) menu.querySelector('a, button')?.focus();
    else if (movel.matches) abrir.focus();
}

if (menu && abrir) {
    menu.inert = movel.matches;
    movel.addEventListener('change', (e) => { corpo.classList.remove('menu-aberto'); fundo.hidden = true; menu.inert = e.matches; abrir.setAttribute('aria-expanded', 'false'); });
    abrir.addEventListener('click', () => definir(true));
    document.querySelectorAll('[data-menu-fechar]').forEach((el) => el.addEventListener('click', () => definir(false)));
    menu.querySelectorAll('.nav a').forEach((a) => a.addEventListener('click', () => movel.matches && definir(false)));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && corpo.classList.contains('menu-aberto')) definir(false); });
}

// Conferência: progresso, busca por placa e aviso antes de registrar com veículos sem marcação.
const conferencia = document.querySelector('[data-conferencia]');
if (conferencia) {
    const itens = [...conferencia.querySelectorAll('.conf-item')];
    const progresso = conferencia.querySelector('[data-conf-progresso]');
    const pendentes = () => itens.filter((i) => !i.querySelector('input:checked'));
    const atualizar = () => {
        itens.forEach((i) => { i.dataset.estado = i.querySelector('input:checked')?.value ?? ''; });
        const restam = pendentes().length;
        if (progresso) progresso.textContent = `${itens.length - restam} de ${itens.length} conferidos · ${restam} restantes`;
    };
    conferencia.addEventListener('change', atualizar);
    conferencia.querySelector('[data-conf-busca]')?.addEventListener('input', (e) => {
        const termo = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        itens.forEach((i) => { i.hidden = termo !== '' && !i.dataset.placa.includes(termo); });
    });
    conferencia.addEventListener('submit', (e) => {
        const restam = pendentes();
        if (restam.length && !confirm(`${restam.length} veículo(s) sem marcação serão registrados como AUSENTES:\n${restam.map((i) => (i.querySelector('.plate') || i.querySelector('legend')).textContent.split(' · ')[0].trim()).join(', ')}\n\nRegistrar mesmo assim?`)) e.preventDefault();
    });
    atualizar();
}

// Menus suspensos da barra superior (<details data-pop>): um aberto por vez; fecha com clique fora ou Esc.
const pops = [...document.querySelectorAll('[data-pop]')];
pops.forEach((p) => p.addEventListener('toggle', () => { if (p.open) pops.forEach((o) => o !== p && (o.open = false)); }));
document.addEventListener('click', (e) => pops.forEach((p) => { if (p.open && !p.contains(e.target)) p.open = false; }));
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') pops.forEach((p) => { if (p.open) { p.open = false; p.querySelector('summary').focus(); } });
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        const busca = document.querySelector('[data-busca-global]');
        if (!busca) return;
        e.preventDefault();
        if (movel.matches && !corpo.classList.contains('menu-aberto')) definir(true);
        busca.focus();
    }
});

// Pagamento guiado "Como o cliente vai pagar?": troca de painel sem recarregar (os links funcionam sem JS),
// restante calculado e exemplo em texto. Valores conferidos de novo no servidor.
document.querySelectorAll('[data-pg]').forEach((raiz) => {
    const saldo = Number(raiz.dataset.saldo || 0);
    const brl = (c) => (c / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    const centavos = (t) => {
        const s = String(t || '').trim().replace(/[R$\s]/g, '');
        if (!s) return null;
        const n = Number(s.includes(',') ? s.replace(/\./g, '').replace(',', '.') : s);
        return Number.isFinite(n) && n >= 0 ? Math.round(n * 100) : NaN;
    };
    const nomeForma = { pix: 'no Pix', dinheiro: 'em dinheiro', cartao_externo: 'no cartão' };
    const dataCurta = (v) => (v ? v.split('-').reverse().slice(0, 2).join('/') : 'data a definir');

    raiz.querySelectorAll('[data-pg-modo]').forEach((link) => link.addEventListener('click', (e) => {
        e.preventDefault();
        const modo = link.dataset.pgModo;
        raiz.querySelectorAll('[data-pg-modo]').forEach((l) => (l === link ? l.setAttribute('aria-current', 'true') : l.removeAttribute('aria-current')));
        raiz.querySelectorAll('[data-pg-painel]').forEach((p) => { p.hidden = p.dataset.pgPainel !== modo; });
        history.replaceState(null, '', link.href);
    }));

    // Tudo agora: o botão diz o que acontece.
    const painelAgora = raiz.querySelector('[data-pg-painel="agora"]');
    const botaoAgora = painelAgora?.querySelector('[data-pg-botao]');
    const rotularAgora = () => {
        const f = painelAgora?.querySelector('[data-pg-forma]:checked')?.value;
        if (botaoAgora && f) botaoAgora.textContent = f === 'pix' ? 'Gerar cobrança Pix' : 'Registrar recebimento';
    };
    painelAgora?.addEventListener('change', rotularAgora);
    rotularAgora();

    // Parte agora, parte depois.
    const parte = raiz.querySelector('[data-pg-painel="parte"]');
    if (!parte) return;
    const agoraInput = parte.querySelector('[data-pg-agora]');
    const restanteEl = parte.querySelector('[data-pg-restante]');
    const exemplo = parte.querySelector('[data-pg-exemplo]');
    const pixAviso = parte.querySelector('[data-pg-pix-aviso]');
    const atualizar = () => {
        const agora = centavos(agoraInput.value);
        const forma = parte.querySelector('[data-pg-forma]:checked')?.value;
        if (pixAviso) pixAviso.hidden = forma !== 'pix';
        if (agora === null || Number.isNaN(agora) || agora <= 0 || agora >= saldo) {
            restanteEl.textContent = agora >= saldo ? 'O valor de agora cobre todo o saldo. Use "Tudo agora".' : `Restante: saldo de ${brl(saldo)} menos o valor pago agora.`;
            exemplo.hidden = true;
            return;
        }
        const restante = saldo - agora;
        restanteEl.innerHTML = '';
        restanteEl.append('Restante a combinar: ', Object.assign(document.createElement('strong'), { textContent: brl(restante) }));
        const linhas = [...parte.querySelectorAll('[data-pg-futura]')];
        const usadas = linhas.filter((l, i) => i === 0 || l.querySelector('[data-pg-venc]').value || l.querySelector('[data-pg-valor]').value);
        const primeiroValor = linhas[0].querySelector('[data-pg-valor]');
        primeiroValor.placeholder = usadas.length === 1 ? String((restante / 100).toFixed(2)).replace('.', ',') : '';
        let soma = 0;
        const partes = usadas.map((l, i) => {
            let v = centavos(l.querySelector('[data-pg-valor]').value);
            if ((v === null || Number.isNaN(v)) && usadas.length === 1) v = restante;
            soma += v || 0;
            const meio = l.querySelector('[data-pg-meio]').value;
            return `${v ? brl(v) : 'valor a definir'} em ${dataCurta(l.querySelector('[data-pg-venc]').value)}${meio ? ' ' + nomeForma[meio] : ''}`;
        });
        let texto = `${brl(agora)} agora ${nomeForma[forma] || ''} + ${partes.join(' + ')}`.replace(/\s+/g, ' ');
        if (soma !== restante) texto += ` — atenção: as datas somam ${brl(soma)}, faltam ${brl(restante - soma)} para fechar o restante.`;
        exemplo.textContent = texto;
        exemplo.hidden = false;
    };
    parte.addEventListener('input', atualizar);
    parte.addEventListener('change', atualizar);
    atualizar();
});

// Seção fiscal: acompanha a emissão assíncrona (fila) sem recarregar. Consulta um endpoint autorizado a cada 3 s
// enquanto houver documento pendente/processando e para ao concluir. Sem JS, o link "Atualizar" continua disponível.
const fiscal = document.querySelector('[data-fiscal]');
if (fiscal && fiscal.dataset.acompanhar === '1') {
    const aviso = document.querySelector('[data-fiscal-aviso]');
    const atualizar = document.querySelector('[data-fiscal-atualizar]');
    const frases = { autorizado: 'Documento fiscal autorizado.', rejeitado: 'Documento fiscal rejeitado. Veja o motivo e tente de novo.', pendente: 'Emissão fiscal ainda pendente.' };
    let falhas = 0;
    let tempo = null;
    if (atualizar) atualizar.hidden = true;
    const consultar = async () => {
        if (document.hidden) { tempo = setTimeout(consultar, 3000); return; }
        try {
            const r = await fetch(fiscal.dataset.url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store' });
            if (!r.ok) throw new Error(String(r.status));
            const dados = await r.json();
            falhas = 0;
            if (dados.html !== fiscal.dataset.ultimo) {
                fiscal.innerHTML = dados.html;
                fiscal.dataset.ultimo = dados.html;
            }
            if (!dados.acompanhar) {
                const estado = fiscal.querySelector('.fiscal-doc')?.className.match(/fiscal-doc--(\w+)/)?.[1];
                if (aviso) aviso.textContent = frases[estado] || 'Situação do documento fiscal atualizada.';
                if (atualizar) atualizar.remove();
                return;
            }
        } catch (e) {
            falhas += 1;
            if (falhas >= 5) { if (atualizar) atualizar.hidden = false; return; } // sem conexão: volta ao link manual
        }
        tempo = setTimeout(consultar, 3000);
    };
    tempo = setTimeout(consultar, 3000);
    window.addEventListener('pagehide', () => clearTimeout(tempo));
}

// Central de WhatsApp: a lista e o histórico se atualizam sozinhos (a cada 5 s com a aba visível), sem tocar no
// que está sendo digitado. Busca a própria página (mesma autorização do servidor) e troca só os dois blocos.
const waLista = document.querySelector('.wa-lista');
if (waLista) {
    const aviso = Object.assign(document.createElement('p'), { className: 'sr-only' });
    aviso.setAttribute('aria-live', 'polite');
    document.body.append(aviso);
    let falhas = 0;
    const atualizarWa = async () => {
        if (!document.hidden) {
            try {
                const r = await fetch(location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store' });
                if (!r.ok || r.redirected) throw new Error(String(r.status));
                const novo = new DOMParser().parseFromString(await r.text(), 'text/html');
                falhas = 0;
                const lista = novo.querySelector('.wa-lista');
                if (lista && lista.innerHTML !== waLista.innerHTML) waLista.innerHTML = lista.innerHTML;
                const atual = document.querySelector('.wa-mensagens');
                const chegou = novo.querySelector('.wa-mensagens');
                if (atual && chegou && chegou.innerHTML !== atual.innerHTML) {
                    const noFim = atual.scrollHeight - atual.scrollTop - atual.clientHeight < 80;
                    const antes = atual.querySelectorAll('.wa-msg.entrada').length;
                    atual.innerHTML = chegou.innerHTML;
                    if (noFim) atual.scrollTop = atual.scrollHeight;
                    if (atual.querySelectorAll('.wa-msg.entrada').length > antes) aviso.textContent = 'Nova mensagem do cliente.';
                }
            } catch (e) {
                falhas += 1; // sessão expirada ou sem conexão: para de tentar e a página continua funcionando com F5
                if (falhas >= 5) return;
            }
        }
        setTimeout(atualizarWa, 5000);
    };
    setTimeout(atualizarWa, 5000);
}

// Abas da OS no celular: a aba atual fica visível mesmo quando a barra rola na horizontal.
document.querySelectorAll('nav.abas').forEach((nav) => {
    const atual = nav.querySelector('a[aria-current=page]');
    if (atual && nav.scrollWidth > nav.clientWidth) nav.scrollLeft = Math.max(0, atual.offsetLeft - nav.offsetLeft - 16);
});

// Confirmação simples para ações destrutivas: <button data-confirmar="Pergunta?">.
document.addEventListener('submit', (e) => {
    const msg = e.submitter?.dataset.confirmar;
    if (msg && !confirm(msg)) e.preventDefault();
});

// Gráfico do painel: valor ao passar o mouse, tocar ou navegar pelo teclado; troca de métrica sem recarregar; clique abre
// as ordens do dia/período (links reais, funcionam sem JS). Os dados vêm de <script type="application/json"> gerado no
// servidor, que já exclui as séries em dinheiro de quem não pode vê-las.
document.querySelectorAll('[data-grafico]').forEach((cartao) => {
    const fonte = cartao.querySelector('[data-grafico-dados]');
    const area = cartao.querySelector('[data-grafico-barras]');
    const dica = cartao.querySelector('[data-grafico-dica]');
    if (!fonte || !area || !dica) return;
    let g;
    try { g = JSON.parse(fonte.textContent); } catch (e) { return; }
    const barras = [...area.querySelectorAll('.barra')];
    const titulo = cartao.querySelector('[data-grafico-titulo]');
    const total = cartao.querySelector('[data-grafico-total]');
    const aviso = cartao.querySelector('[data-grafico-aviso]');
    const seletor = cartao.querySelector('[data-grafico-metricas]');
    let metrica = g.padrao;
    let ativa = -1; // barra com a dica aberta
    let foco = 0; // barra que recebe Tab (tabindex móvel)
    let ultimoPonteiro = 'mouse';
    let ativaNoToque = -1; // dica aberta no instante do toque (o foco do toque abre a dica antes do click)

    const entradasTexto = (i) => `${g.entradas[i]} ${g.entradas[i] === 1 ? 'entrada' : 'entradas'}`;
    const m = () => g.metricas[metrica];
    const zero = (i) => m().valores[i] === 0;

    function linhaDica(classe, texto) {
        const el = document.createElement('span');
        el.className = classe;
        el.textContent = texto;
        return el;
    }

    function posicionar(i) {
        const b = barras[i];
        const col = b.querySelector('.barra-coluna');
        const rc = cartao.getBoundingClientRect();
        const rb = (col.offsetHeight > 6 ? col : b).getBoundingClientRect();
        const larg = dica.offsetWidth;
        const alt = dica.offsetHeight;
        // Centro da barra, sem sair do cartão nem da janela (390 px inclusive).
        const minX = Math.max(8, 8 - rc.left);
        const maxX = Math.min(rc.width - larg - 8, window.innerWidth - rc.left - larg - 8);
        let x = Math.min(Math.max(rb.left - rc.left + rb.width / 2 - larg / 2, minX), Math.max(minX, maxX));
        // Nunca acima da área das barras (cobriria os botões de métrica): sem espaço acima da barra, vai para o lado dela.
        const topo = area.getBoundingClientRect().top - rc.top;
        let y = rb.top - rc.top - alt - 10;
        if (y < topo) {
            y = topo;
            const direita = rb.right - rc.left + 8;
            x = direita <= Math.max(minX, maxX) ? direita : Math.max(minX, rb.left - rc.left - larg - 8);
        }
        dica.style.transform = `translate(${Math.round(x)}px, ${Math.round(y)}px)`;
    }

    function mostrar(i) {
        if (i < 0 || i >= barras.length) return;
        if (ativa !== -1 && ativa !== i) barras[ativa].classList.remove('ativa');
        ativa = i;
        barras[i].classList.add('ativa');
        const grupo = g.grupos[i];
        dica.replaceChildren(linhaDica('grafico-dica-periodo', grupo.titulo), linhaDica('grafico-dica-valor', m().textos[i]));
        if (metrica !== 'entradas') dica.append(linhaDica('grafico-dica-extra', entradasTexto(i)));
        if (zero(i)) dica.append(linhaDica('grafico-dica-acao', 'Sem movimento: nada a listar'));
        else if (ultimoPonteiro === 'touch') dica.append(linhaDica('grafico-dica-acao', 'Toque de novo para ver as ordens'));
        else dica.append(linhaDica('grafico-dica-acao', 'Clique para ver as ordens'));
        dica.hidden = false;
        posicionar(i);
    }

    function esconder() {
        ativaNoToque = -1;
        if (ativa !== -1) barras[ativa].classList.remove('ativa');
        ativa = -1;
        dica.hidden = true;
    }

    function rotular(i) {
        const b = barras[i];
        const extra = metrica !== 'entradas' ? `, ${entradasTexto(i)}` : '';
        b.setAttribute('aria-label', `${g.grupos[i].titulo}: ${m().textos[i]}${extra}.${zero(i) ? ' Sem movimento, nada a listar' : ' Abrir ordens'}`);
        if (zero(i)) b.setAttribute('aria-disabled', 'true'); else b.removeAttribute('aria-disabled');
        const url = new URL(b.href, location.href);
        url.searchParams.set('data', m().data);
        b.href = url.pathname + url.search;
    }

    // Redesenha barras, pico, título, total e textos para a métrica atual.
    function desenhar() {
        const valores = m().valores;
        const maior = Math.max(0, ...valores);
        const escala = Math.max(maior, 1);
        const pico = maior > 0 ? valores.indexOf(maior) : -1;
        barras.forEach((b, i) => {
            b.querySelector('.barra-coluna').style.height = `${Math.max(2, Math.round((valores[i] * 100) / escala))}%`;
            b.classList.toggle('pico', i === pico);
            b.querySelector('.barra-valor')?.remove();
            if (i === pico) b.prepend(linhaDica('barra-valor', m().curtos[i]));
            rotular(i);
        });
        if (titulo) titulo.textContent = m().titulo;
        if (total) total.textContent = m().total;
        area.setAttribute('aria-label', `${m().rotulo} por ${g.escala}${pico >= 0 ? `; maior valor ${m().curtos[pico]} em ${g.grupos[pico].titulo}` : '; sem movimento no período'}. Use as setas para percorrer as barras e Enter para abrir as ordens.`);
        if (ativa !== -1) mostrar(ativa);
    }

    function focar(i) {
        barras[foco]?.setAttribute('tabindex', '-1');
        foco = Math.min(Math.max(i, 0), barras.length - 1);
        barras[foco].setAttribute('tabindex', '0');
        barras[foco].focus();
    }

    // Tabindex móvel: uma parada de Tab para o gráfico inteiro; setas, Home e End percorrem as barras.
    barras.forEach((b, i) => {
        b.removeAttribute('title');
        b.setAttribute('tabindex', i === 0 ? '0' : '-1');
        b.addEventListener('focus', () => { foco = i; barras.forEach((o, k) => o.setAttribute('tabindex', k === i ? '0' : '-1')); mostrar(i); });
        b.addEventListener('pointerenter', (e) => { if (e.pointerType === 'mouse') { ultimoPonteiro = 'mouse'; mostrar(i); } });
        b.addEventListener('click', (e) => {
            if (zero(i)) { e.preventDefault(); mostrar(i); return; }
            // Toque: o primeiro toque mostra o valor; o segundo, na mesma barra, abre as ordens.
            if (ultimoPonteiro === 'touch' && ativaNoToque !== i) { e.preventDefault(); ativaNoToque = i; mostrar(i); }
        });
    });
    area.addEventListener('pointerdown', (e) => { ultimoPonteiro = e.pointerType || 'mouse'; ativaNoToque = ativa; });
    area.addEventListener('pointerleave', (e) => { if (e.pointerType === 'mouse' && !area.contains(document.activeElement)) esconder(); });
    area.addEventListener('focusout', (e) => { if (!area.contains(e.relatedTarget)) esconder(); });
    area.addEventListener('keydown', (e) => {
        const passos = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };
        if (e.key in passos) { e.preventDefault(); focar(foco + passos[e.key]); }
        else if (e.key === 'Home') { e.preventDefault(); focar(0); }
        else if (e.key === 'End') { e.preventDefault(); focar(barras.length - 1); }
        else if (e.key === 'Escape') esconder();
        else if ((e.key === 'Enter' || e.key === ' ') && zero(foco)) e.preventDefault();
    });
    // Toque ou clique fora do gráfico fecha a dica.
    document.addEventListener('pointerdown', (e) => { if (ativa !== -1 && !area.contains(e.target)) esconder(); });
    window.addEventListener('resize', () => { if (ativa !== -1) posicionar(ativa); });

    if (seletor && Object.keys(g.metricas).length > 1) {
        seletor.hidden = false;
        seletor.querySelectorAll('[data-metrica]').forEach((botao) => botao.addEventListener('click', () => {
            if (!g.metricas[botao.dataset.metrica]) return;
            metrica = botao.dataset.metrica;
            seletor.querySelectorAll('[data-metrica]').forEach((o) => o.setAttribute('aria-pressed', String(o === botao)));
            desenhar();
            if (aviso) aviso.textContent = `Mostrando ${m().titulo.toLowerCase()}: ${m().total}.`;
        }));
    }
    desenhar();
});
