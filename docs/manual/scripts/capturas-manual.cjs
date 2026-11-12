// Capturas do manual de uso — somente leitura (GET, abrir menus/abas, preencher sem enviar).
// Exceções: login/2FA/confirmação de senha (sessão) e "Versão simples" do painel, ligada e DESLIGADA em seguida.
// Observação: abrir a OS impressa registra uma linha de auditoria "os_impressa" (comportamento do sistema).
// Uso (na raiz do projeto, com OFICINA_BANCO=demo):
//   docker run --rm --network container:controle-oficina-app-1 -v oficina-e2e-nm:/w \
//     -v "$PWD/docs/manual/scripts":/w/s -v "$PWD/docs/manual/imagens":/out -w /w \
//     -e URL=http://localhost:8080 mcr.microsoft.com/playwright:v1.63.0-noble node s/capturas-manual.cjs
// SO=nome1,nome2 limita às capturas indicadas.
const { chromium } = require('playwright'); const crypto = require('crypto'); const fs = require('fs');
const BASE = process.env.URL || 'http://localhost:8080'; const OUT = '/out';
const SENHA = 'demo2026oficina'; const SEG = 'MRSW23ZNN5TGSY3JNZQS2MRQGI3C2MTGME';
const SO = process.env.SO ? process.env.SO.split(',') : null;
const MAXH = 3000;
function totp(b32, t = Date.now() / 1000) { const A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = ''; for (const c of b32) bits += A.indexOf(c).toString(2).padStart(5, '0'); const key = Buffer.from(bits.match(/.{8}/g).map(b => parseInt(b, 2))); const buf = Buffer.alloc(8); buf.writeBigUInt64BE(BigInt(Math.floor(t / 30))); const h = crypto.createHmac('sha1', key).update(buf).digest(); const o = h[19] & 15; return String((h.readUInt32BE(o) & 0x7fffffff) % 1e6).padStart(6, '0'); }
fs.mkdirSync(OUT, { recursive: true });
const log = [];

async function foto(p, nome, largura) {
  await p.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {}); await p.waitForTimeout(300);
  const h = await p.evaluate(() => document.documentElement.scrollHeight);
  const vh = Math.min(Math.max(h, largura < 768 ? 844 : 900), MAXH);
  // Janela do tamanho da página: o menu lateral fixo acompanha a altura inteira.
  await p.setViewportSize({ width: largura, height: vh }); await p.waitForTimeout(250);
  await p.screenshot({ path: `${OUT}/${nome}.jpg`, type: 'jpeg', quality: 80 });
  const linha = `${nome} url=${p.url().replace(BASE, '')} altura=${h}${h > MAXH ? ' (cortada em ' + MAXH + ')' : ''}`; console.log(linha); log.push(linha);
  fs.writeFileSync('/w/s/capturas-manual.log', log.join('\n') + '\n');
}

(async () => {
  const b = await chromium.launch();
  const ctx = {}; const ctx390 = {};
  const novo = async (estado, l) => b.newContext({ viewport: { width: l, height: l < 768 ? 844 : 900 }, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo', ...(estado ? { storageState: estado } : {}) });

  // --- Entrada (anônimo) e 2FA da proprietária ---
  const anon = await novo(null, 1440); const pa = await anon.newPage();
  const quer = n => !SO || SO.includes(n);
  await pa.goto(BASE + '/entrar'); if (quer('entrar')) await foto(pa, 'entrar', 1440);
  await anon.close();
  { const c = await novo(null, 390); const p = await c.newPage(); await p.goto(BASE + '/entrar'); if (quer('entrar-390')) await foto(p, 'entrar-390', 390); await c.close(); }

  async function entrar(email) {
    const c = await novo(null, 1440); const p = await c.newPage();
    await p.goto(BASE + '/entrar'); await p.fill('#email', email); await p.fill('#password', SENHA); await p.click('form button[type=submit]'); await p.waitForLoadState('networkidle');
    if (await p.locator('#codigo').count()) {
      if (quer('dois-fatores')) await foto(p, 'dois-fatores', 1440);
      await p.fill('#codigo', totp(SEG)); await p.click('form button[type=submit]'); await p.waitForLoadState('networkidle');
    }
    return [c, p];
  }
  for (const [papel, email] of [['dono', 'dono@demo.oficina.test'], ['gerente', 'gerente.asn@demo.oficina.test'], ['recepcao', 'recepcao.asn@demo.oficina.test'], ['mecanico', 'mecanico.asn@demo.oficina.test'], ['supervisor', 'supervisor@demo.oficina.test']]) {
    const [c] = await entrar(email); ctx[papel] = c;
  }
  // Confirmação de senha da proprietária (configurações sensíveis)
  { const p = await ctx.dono.newPage(); await p.goto(BASE + '/configuracoes/unidades'); await p.waitForLoadState('networkidle');
    if (p.url().includes('confirmar-senha')) { if (quer('confirmar-senha')) await foto(p, 'confirmar-senha', 1440); await p.fill('#password', SENHA); await p.click('form button[type=submit]'); await p.waitForLoadState('networkidle'); }
    await p.close(); }
  for (const k of Object.keys(ctx)) ctx390[k] = await novo(await ctx[k].storageState(), 390);

  async function tela(nome, papel, caminho, l = 1440, antes) {
    if (!quer(nome)) return;
    const c = l === 390 ? ctx390[papel] : ctx[papel]; const p = await c.newPage(); p.setDefaultTimeout(15000);
    const erros = []; p.on('pageerror', e => erros.push(e.message));
    try {
      const r = await p.goto(BASE + caminho); await p.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
      if (antes) await antes(p);
      await foto(p, nome, l); if (r && r.status() >= 400) log.push(`  status ${r.status()}`); if (erros.length) log.push('  ERROS ' + erros.join('|'));
    } catch (e) { const m = `FALHOU ${nome}: ${e.message.split('\n')[0]}`; console.log(m); log.push(m); }
    await p.close();
  }
  async function primeiroLink(papel, caminho, re) {
    const p = await ctx[papel].newPage(); p.setDefaultTimeout(15000); await p.goto(BASE + caminho);
    const h = await p.$$eval('a[href]', (as, src) => { const r = new RegExp(src); return as.map(a => new URL(a.href).pathname).find(x => r.test(x)) || null; }, re); await p.close(); return h;
  }

  // --- Proprietária ---
  await tela('painel-dono', 'dono', '/');
  await tela('painel-dono-notificacoes', 'dono', '/', 1440, async p => { await p.click('summary.icone-botao'); });
  await tela('painel-dono-menu-usuario', 'dono', '/', 1440, async p => { await p.click('summary.usuario-botao'); });
  if (quer('painel-simples')) {
    const p = await ctx.dono.newPage(); await p.goto(BASE + '/');
    try {
      await p.click('form[action$="/painel/modo"] button'); await p.waitForLoadState('networkidle');
      await foto(p, 'painel-simples', 1440);
    } finally {
      await p.goto(BASE + '/');
      const bt = p.locator('form[action$="/painel/modo"] input[name=simples][value="0"]');
      if (await bt.count()) { await p.click('form[action$="/painel/modo"] button'); await p.waitForLoadState('networkidle'); }
      const ainda = await p.locator('form[action$="/painel/modo"] input[name=simples][value="0"]').count();
      const msg = `painel-simples desligado: ${ainda === 0 ? 'SIM' : 'NÃO (VERIFICAR)'}`; console.log(msg); log.push(msg);
    }
    await p.close();
  }
  await tela('ordens-dono', 'dono', '/ordens');
  await tela('os-entrada', 'dono', '/ordens/TAG-00073');
  await tela('os-aguardando-aprovacao', 'dono', '/ordens/AGC-00074?aba=orcamento');
  await tela('os-autorizado', 'dono', '/ordens/AGC-00076?aba=orcamento');
  await tela('os-cancelada', 'dono', '/ordens/AGC-00030');
  await tela('os-sem-veiculo', 'dono', '/ordens/TAG-00075');
  await tela('auditoria', 'dono', '/auditoria');
  await tela('indicadores-dono', 'dono', '/indicadores');
  await tela('pendencias', 'dono', '/pendencias');
  await tela('aprovacoes', 'dono', '/aprovacoes');
  await tela('integracoes', 'dono', '/integracoes');
  await tela('empresa', 'dono', '/configuracoes/empresa');
  await tela('unidades', 'dono', '/configuracoes/unidades');
  await tela('recebedores', 'dono', '/configuracoes/recebedores');
  await tela('usuarios', 'dono', '/configuracoes/usuarios');
  await tela('usuario-novo', 'dono', '/configuracoes/usuarios/novo');
  { const h = quer('usuario-editar') ? await primeiroLink('dono', '/configuracoes/usuarios', '^/configuracoes/usuarios/\\d+$') : null; if (h) await tela('usuario-editar', 'dono', h); }
  await tela('whatsapp-numeros', 'dono', '/configuracoes/whatsapp/numeros');
  // Manual do proprietário (12/11/2026): formulários preenchidos SEM enviar.
  await tela('unidade-nova', 'dono', '/configuracoes/unidades', 1440, async p => { await p.fill('#nova-codigo', 'GUA'); await p.fill('#nova-nome', 'Oficina Guará'); });
  await tela('usuario-novo-gerente', 'dono', '/configuracoes/usuarios/novo', 1440, async p => {
    await p.fill('#name', 'Maria Souza'); await p.fill('#email', 'maria.gerente@exemplo.com.br'); await p.selectOption('#papel', 'gerente');
    await p.selectOption('#unidade_id', { index: 1 }); await p.waitForTimeout(300); });
  await tela('catalogo', 'dono', '/configuracoes/catalogo');
  { const h = quer('catalogo-editar') ? await primeiroLink('dono', '/configuracoes/catalogo', '^/configuracoes/catalogo/\\d+$') : null; if (h) await tela('catalogo-editar', 'dono', h); }
  await tela('feriados-dono', 'dono', '/feriados');
  await tela('conta', 'dono', '/conta');

  // --- Gerente (Asa Norte) ---
  const OS = 'ASN-00070';
  await tela('painel-gerente', 'gerente', '/');
  await tela('ordens', 'gerente', '/ordens');
  await tela('os-resumo', 'gerente', `/ordens/${OS}`);
  await tela('os-diagnostico', 'gerente', `/ordens/${OS}?aba=diagnostico`);
  await tela('os-orcamento', 'gerente', `/ordens/${OS}?aba=orcamento`);
  await tela('os-pagamentos', 'gerente', `/ordens/${OS}?aba=pagamentos`);
  await tela('os-pagamento-parte', 'gerente', `/ordens/${OS}?aba=pagamentos&modo=parte`);
  await tela('os-pagamento-depois', 'gerente', `/ordens/${OS}?aba=pagamentos&modo=depois`);
  await tela('os-documentos', 'gerente', `/ordens/${OS}?aba=documentos`);
  await tela('os-historico', 'gerente', `/ordens/${OS}?aba=historico`);
  await tela('os-montar-orcamento', 'gerente', '/ordens/ASN-00021?aba=orcamento');
  await tela('os-execucao', 'gerente', '/ordens/ASN-00012?aba=orcamento');
  await tela('os-pix-revisao', 'gerente', '/ordens/ASN-00029?aba=pagamentos');
  await tela('os-combinado', 'gerente', '/ordens/ASN-00008?aba=pagamentos');
  await tela('os-entregue-excecao', 'gerente', '/ordens/ASN-00006?aba=pagamentos');
  await tela('impressao', 'gerente', `/ordens/${OS}/impressao`);
  { const h = quer('fiscal') ? await primeiroLink('gerente', '/ordens/ASN-00003?aba=documentos', '/fiscal/\\d+$') : null; if (h) await tela('fiscal', 'gerente', h); }
  await tela('orcamentos', 'gerente', '/orcamentos?status=todos');
  await tela('orcamentos-avulsos', 'gerente', '/orcamentos-sem-entrada');
  await tela('orcamento-avulso-novo', 'gerente', '/orcamentos-sem-entrada/novo');
  await tela('caixa', 'gerente', '/caixa?data=2026-10-22');
  await tela('caixa-receber', 'gerente', '/caixa?data=2026-10-22&aba=receber');
  await tela('caixa-fechamento', 'gerente', '/caixa?data=2026-10-22&aba=fechamento');
  await tela('caixa-fechamento-aberto', 'gerente', '/caixa?aba=fechamento');
  await tela('indicadores', 'gerente', '/indicadores');
  await tela('posvenda', 'gerente', '/pos-venda?de=2026-10-01&ate=2026-10-31');
  await tela('posvenda-ficha', 'gerente', '/pos-venda/ASN-00058');
  await tela('acesso-negado', 'gerente', '/ordens/TAG-00073');
  await tela('acesso-negado-config', 'gerente', '/configuracoes/usuarios');
  await tela('nao-encontrada', 'gerente', '/pagina-que-nao-existe');

  // --- Recepção (Asa Norte) ---
  await tela('painel-recepcao', 'recepcao', '/');
  await tela('nova-entrada', 'recepcao', '/entradas/nova');
  await tela('nova-entrada-erros', 'recepcao', '/entradas/nova', 1440, async p => { await p.click('form button[type=submit].primary'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(500); });
  if (quer('nova-entrada-cadastro')) {
    const placa = await (async () => { const p = await ctx.recepcao.newPage(); await p.goto(BASE + '/veiculos'); const t = await p.locator('.plate').first().textContent().catch(() => null); await p.close(); return t && t.trim(); })();
    if (placa) await tela('nova-entrada-cadastro', 'recepcao', '/entradas/nova', 1440, async p => { await p.fill('#placa', placa.replace(/[^A-Za-z0-9]/g, '')); await p.locator('#placa').blur(); await p.waitForLoadState('networkidle'); await p.waitForTimeout(800); });
  }
  await tela('nova-entrada-sem-veiculo', 'recepcao', '/entradas/nova', 1440, async p => { await p.check('.sem-veiculo-opcao input'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(500); });
  await tela('recebimentos', 'recepcao', '/caixa?data=2026-10-22');
  await tela('conferencia', 'recepcao', '/conferencia');
  await tela('clientes', 'recepcao', '/clientes');
  { const h = quer('cliente-ficha') ? await primeiroLink('recepcao', '/clientes', '^/clientes/\\d+$') : null; if (h) await tela('cliente-ficha', 'recepcao', h); }
  await tela('veiculos', 'recepcao', '/veiculos');
  await tela('whatsapp', 'recepcao', '/whatsapp');
  { const h = quer('whatsapp-conversa') ? await primeiroLink('recepcao', '/whatsapp', '^/whatsapp/conversas/\\d+$') : null; if (h) await tela('whatsapp-conversa', 'recepcao', h); }
  await tela('os-orcamento-recepcao', 'recepcao', '/ordens/ASN-00021?aba=orcamento');
  await tela('os-pagamentos-recepcao', 'recepcao', `/ordens/${OS}?aba=pagamentos`);
  // celular (balcão)
  await tela('painel-recepcao-390', 'recepcao', '/', 390);
  await tela('menu-celular-390', 'recepcao', '/', 390, async p => { await p.click('button.hamburger'); await p.waitForTimeout(400); });
  await tela('nova-entrada-390', 'recepcao', '/entradas/nova', 390);
  await tela('os-resumo-390', 'recepcao', `/ordens/${OS}`, 390);
  await tela('os-pagamentos-390', 'recepcao', `/ordens/${OS}?aba=pagamentos`, 390);
  await tela('recebimentos-390', 'recepcao', '/caixa?data=2026-10-22', 390);

  // --- Mecânico (Asa Norte) ---
  await tela('meu-painel', 'mecanico', '/meu-painel');
  await tela('fila', 'mecanico', '/fila');
  await tela('os-mecanico', 'mecanico', '/ordens/ASN-00021');
  await tela('os-mecanico-diagnostico', 'mecanico', '/ordens/ASN-00021?aba=diagnostico');
  await tela('os-mecanico-execucao', 'mecanico', '/ordens/ASN-00012?aba=orcamento');
  await tela('acesso-negado-mecanico', 'mecanico', '/caixa');
  await tela('meu-painel-390', 'mecanico', '/meu-painel', 390);
  await tela('fila-390', 'mecanico', '/fila', 390);
  await tela('os-mecanico-execucao-390', 'mecanico', '/ordens/ASN-00012?aba=orcamento', 390);

  // --- Supervisor ---
  await tela('painel-supervisor', 'supervisor', '/');
  await tela('feriados', 'supervisor', '/feriados');

  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
