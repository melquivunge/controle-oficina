<?php

use App\Http\Controllers\AnexoController;
use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\CaixaController;
use App\Http\Controllers\PagamentoController;
use App\Http\Controllers\RecebedorController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ConferenciaController;
use App\Http\Controllers\ContaController;
use App\Http\Controllers\IndicadoresController;
use App\Http\Controllers\ExecucaoController;
use App\Http\Controllers\FilaController;
use App\Http\Controllers\FiscalController;
use App\Http\Controllers\OrcamentoController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\OrdemController;
use App\Http\Controllers\AutenticacaoController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\UnidadeController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

// Área do cliente: link com token, sem conta.
Route::middleware('throttle:portal')->group(function () {
    Route::get('/c/{token}', [PortalController::class, 'show'])->name('portal.show');
    Route::get('/q/{token}', [\App\Http\Controllers\OrcamentoAvulsoController::class, 'portal'])->name('orcamento-avulso.portal');
    Route::get('/c/{token}/documentos/{documento}', [PortalController::class, 'documento'])->name('portal.documento');
});

Route::middleware('guest')->group(function () {
    Route::get('/entrar', [AutenticacaoController::class, 'formulario'])->name('login');
    Route::post('/entrar', [AutenticacaoController::class, 'entrar'])->middleware('throttle:login');
});

Route::middleware('auth')->group(function () {
    Route::post('/sair', [AutenticacaoController::class, 'sair'])->name('sair');

    Route::get('/dois-fatores', [AutenticacaoController::class, 'desafio'])->name('dois-fatores.desafio');
    Route::post('/dois-fatores', [AutenticacaoController::class, 'validarDesafio'])->middleware('throttle:dois-fatores');

    Route::middleware('dois-fatores')->group(function () {
        Route::get('/confirmar-senha', [AutenticacaoController::class, 'formularioConfirmarSenha'])->name('password.confirm');
        Route::post('/confirmar-senha', [AutenticacaoController::class, 'confirmarSenha'])->middleware('throttle:login');

        Route::get('/', PainelController::class)->name('painel');
        Route::get('/painel/exportar', [PainelController::class, 'exportar'])->middleware('can:ver_auditoria')->name('painel.exportar');
        Route::put('/painel/modo', [PainelController::class, 'modo'])->middleware(['can:ver_auditoria', 'throttle:30,1'])->name('painel.modo');
        Route::get('/conta', [ContaController::class, 'show'])->name('conta');
        Route::get('/meu-painel', \App\Http\Controllers\PainelMecanicoController::class)->middleware('can:executar')->name('painel.mecanico');
        Route::put('/conta/senha', [ContaController::class, 'senha'])->middleware('throttle:login')->name('conta.senha');
        Route::put('/conta/perfil', [ContaController::class, 'perfil'])->middleware('throttle:20,1')->name('conta.perfil');
        Route::get('/usuarios/{usuario}/foto', [ContaController::class, 'foto'])->name('usuarios.foto');

        Route::view('/entradas/nova', 'ordens.nova')->middleware('can:registrar_entrada')->name('entradas.nova');
        Route::get('/ordens', [OrdemController::class, 'index'])->name('ordens.index');
        Route::get('/ordens/{ordem}', [OrdemController::class, 'show'])->name('ordens.show');
        Route::post('/ordens/{ordem}/atribuir', [OrdemController::class, 'atribuir'])->name('ordens.atribuir');
        Route::post('/ordens/{ordem}/diagnostico/iniciar', [OrdemController::class, 'iniciarDiagnostico'])->name('ordens.diagnostico.iniciar');
        Route::post('/ordens/{ordem}/diagnostico', [OrdemController::class, 'diagnostico'])->name('ordens.diagnostico');
        Route::post('/ordens/{ordem}/cancelar', [OrdemController::class, 'cancelar'])->name('ordens.cancelar');
        Route::post('/ordens/{ordem}/anexos', [OrdemController::class, 'anexar'])->middleware('throttle:30,1')->name('ordens.anexar');
        Route::post('/ordens/{ordem}/orcamentos/{orcamento}/enviar', [OrcamentoController::class, 'enviar'])->name('orcamentos.enviar');
        Route::post('/ordens/{ordem}/orcamentos/{orcamento}/aprovar', [OrcamentoController::class, 'aprovar'])->name('orcamentos.aprovar');
        Route::post('/ordens/{ordem}/orcamentos/{orcamento}/presencial', [OrcamentoController::class, 'presencial'])->name('orcamentos.presencial');
        Route::post('/ordens/{ordem}/link', [OrcamentoController::class, 'novoLink'])->name('ordens.link');
        Route::post('/ordens/{ordem}/link/revogar', [OrcamentoController::class, 'revogarLinks'])->name('ordens.link.revogar');
        Route::post('/ordens/{ordem}/execucao/iniciar', [ExecucaoController::class, 'iniciar'])->name('execucao.iniciar');
        Route::post('/ordens/{ordem}/execucao/concluir-item', [ExecucaoController::class, 'concluirItem'])->name('execucao.item');
        Route::post('/ordens/{ordem}/execucao/pronto', [ExecucaoController::class, 'pronto'])->name('execucao.pronto');
        Route::post('/ordens/{ordem}/cobrancas', [PagamentoController::class, 'cobrar'])->name('pagamentos.cobrar');
        Route::post('/ordens/{ordem}/cobrancas/{cobranca}/simular', [PagamentoController::class, 'simular'])->name('pagamentos.simular');
        Route::post('/ordens/{ordem}/recebimentos', [PagamentoController::class, 'manual'])->name('pagamentos.manual');
        Route::post('/ordens/{ordem}/pagamentos/{pagamento}/devolver', [PagamentoController::class, 'devolver'])->name('pagamentos.devolver');
        Route::post('/ordens/{ordem}/pagamentos/{pagamento}/aceitar', [PagamentoController::class, 'aceitar'])->name('pagamentos.aceitar');
        Route::post('/ordens/{ordem}/entrega', [PagamentoController::class, 'entregar'])->name('ordens.entregar');
        Route::get('/ordens/{ordem}/pagamentos/{pagamento}/comprovante', [\App\Http\Controllers\PagamentoGuiadoController::class, 'comprovante'])->name('pagamentos.comprovante');
        Route::get('/caixa', [CaixaController::class, 'index'])->middleware('can:receber_manual')->name('caixa.index');
        Route::post('/caixa/fechar', [CaixaController::class, 'fechar'])->middleware('can:fechar_caixa')->name('caixa.fechar');
        // Bloco B: combinado de pagamento do saldo (permissão e unidade conferidas em App\Services\Combinados).
        Route::post('/ordens/{ordem}/combinado', [\App\Http\Controllers\CombinadoController::class, 'registrar'])->middleware('can:combinar_pagamento')->name('combinados.registrar');
        Route::post('/ordens/{ordem}/combinado/cancelar', [\App\Http\Controllers\CombinadoController::class, 'cancelar'])->middleware('can:combinar_pagamento')->name('combinados.cancelar');
        Route::post('/ordens/{ordem}/fiscal', [FiscalController::class, 'solicitar'])->name('fiscal.solicitar');
        Route::post('/ordens/{ordem}/fiscal/{documento}/reprocessar', [FiscalController::class, 'reprocessar'])->name('fiscal.reprocessar');
        Route::post('/ordens/{ordem}/fiscal/{documento}/interromper', [FiscalController::class, 'interromper'])->name('fiscal.interromper');
        Route::post('/ordens/{ordem}/fiscal/{documento}/cancelar', [FiscalController::class, 'cancelar'])->name('fiscal.cancelar');
        Route::get('/ordens/{ordem}/fiscal/{documento}', [FiscalController::class, 'baixar'])->name('fiscal.baixar');
        Route::get('/ordens/{ordem}/fiscal-status', [FiscalController::class, 'status'])->middleware('throttle:60,1')->name('fiscal.status');
        Route::post('/ordens/{ordem}/mensagens/{mensagem}/reenviar', [FiscalController::class, 'reenviarMensagem'])->name('mensagens.reenviar');
        Route::get('/conferencia', [ConferenciaController::class, 'index'])->middleware('can:conferir')->name('conferencia.index');
        Route::post('/conferencia', [ConferenciaController::class, 'store'])->middleware('can:conferir')->name('conferencia.store');
        Route::post('/conferencia/divergencias/{divergencia}', [ConferenciaController::class, 'resolver'])->name('conferencia.resolver');
        Route::get('/indicadores', IndicadoresController::class)->middleware('can:ver_auditoria')->name('indicadores');
        Route::get('/anexos/{anexo}', AnexoController::class)->name('anexos.show');
        Route::get('/fila', FilaController::class)->middleware('can:executar')->name('fila');

        Route::middleware('can:registrar_entrada')->group(function () {
            Route::get('/clientes', [ClienteController::class, 'index'])->name('clientes.index');
            Route::get('/historico-antigo', [\App\Http\Controllers\HistoricoAntigoController::class, 'index'])->name('historico.index');
            Route::get('/historico-antigo/{id}', [\App\Http\Controllers\HistoricoAntigoController::class, 'show'])->whereNumber('id')->name('historico.show');
            Route::get('/historico-antigo/{id}/imprimir', [\App\Http\Controllers\HistoricoAntigoController::class, 'imprimir'])->whereNumber('id')->name('historico.imprimir');
            Route::get('/clientes/novo', [ClienteController::class, 'create'])->name('clientes.create');
            Route::post('/placas/consulta', \App\Http\Controllers\ConsultaPlacaController::class)->middleware('throttle:30,1')->name('placas.consulta');
            Route::post('/clientes', [ClienteController::class, 'store'])->middleware('throttle:30,1')->name('clientes.store');
            Route::get('/clientes/{cliente}', [ClienteController::class, 'show'])->whereNumber('cliente')->name('clientes.show');
            Route::put('/clientes/{cliente}', [ClienteController::class, 'update'])->name('clientes.update');
            Route::post('/clientes/{cliente}/mesclar', [ClienteController::class, 'mesclar'])->name('clientes.mesclar');
        });

        Route::get('/auditoria', [AuditoriaController::class, 'index'])->middleware('can:ver_auditoria')->name('auditoria.index');

        // Páginas de consulta e operação: veículos, orçamentos, pendências/mensagens, integrações e exportação da auditoria.
        Route::get('/veiculos', [\App\Http\Controllers\VeiculoController::class, 'index'])->middleware('can:registrar_entrada')->name('veiculos.index');
        Route::get('/orcamentos', [OrcamentoController::class, 'index'])->middleware('can:ver_valores')->name('orcamentos.index');
        // Orçamento sem entrada do veículo (10/11/2026): quem orça; unidade conferida no serviço.
        Route::middleware('can:orcar')->prefix('orcamentos-sem-entrada')->name('orcamentos-avulsos.')->controller(\App\Http\Controllers\OrcamentoAvulsoController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/novo', 'create')->name('create');
            Route::post('/', 'store')->middleware('throttle:30,1')->name('store');
            Route::get('/{orcamento}', 'show')->name('show');
            Route::post('/{orcamento}/enviar', 'enviar')->middleware('throttle:20,1')->name('enviar');
            Route::post('/{orcamento}/decisao', 'decidir')->name('decidir');
            Route::post('/{orcamento}/cancelar', 'cancelar')->name('cancelar');
        });
        Route::middleware('can:resolver_divergencias')->group(function () {
            Route::get('/pendencias', [\App\Http\Controllers\PendenciaController::class, 'index'])->name('pendencias.index');
            Route::post('/pendencias/{tipo}/{id}/resolver', [\App\Http\Controllers\PendenciaController::class, 'resolver'])->whereNumber('id')->name('pendencias.resolver');
            Route::post('/pendencias/mensagens/resolver-todas', [\App\Http\Controllers\PendenciaController::class, 'resolverMensagens'])->name('pendencias.mensagens.resolver');
            Route::post('/pendencias/mensagens/processar', [\App\Http\Controllers\PendenciaController::class, 'processarFila'])->middleware('throttle:10,1')->name('pendencias.mensagens.processar');
            Route::post('/pendencias/mensagens/{mensagem}/reenviar', [\App\Http\Controllers\PendenciaController::class, 'reenviar'])->name('pendencias.mensagens.reenviar');
        });
        Route::get('/integracoes', \App\Http\Controllers\IntegracaoController::class)->middleware('can:configurar')->name('integracoes.index');
        Route::get('/auditoria/exportar', [AuditoriaController::class, 'exportar'])->middleware(['can:ver_auditoria', 'throttle:10,1'])->name('auditoria.exportar');

        // Devolução Pix pelo provedor: nova tentativa de devolução que falhou (permissão e unidade conferidas no serviço).
        Route::post('/ordens/{ordem}/devolucoes/{devolucao}/tentar-novamente', [PagamentoController::class, 'tentarDevolucao'])
            ->middleware('throttle:20,1')->name('devolucoes.tentar');

        // Central de WhatsApp (RF23): autorização por unidade/triagem conferida no controlador/serviço em toda ação.
        Route::prefix('whatsapp')->name('whatsapp.')->controller(\App\Http\Controllers\WhatsAppController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/simular', 'simular')->middleware('throttle:30,1')->name('simular'); // somente simulação, fora de produção
            Route::get('/conversas/{conversa}', 'show')->whereNumber('conversa')->name('show');
            Route::post('/conversas/{conversa}/responder', 'responder')->whereNumber('conversa')->middleware('throttle:60,1')->name('responder');
            Route::post('/conversas/{conversa}/modelo', 'modelo')->whereNumber('conversa')->middleware('throttle:30,1')->name('modelo');
            Route::post('/conversas/{conversa}/atribuir', 'atribuir')->whereNumber('conversa')->middleware('throttle:60,1')->name('atribuir');
            Route::post('/conversas/{conversa}/situacao', 'situacao')->whereNumber('conversa')->middleware('throttle:60,1')->name('situacao');
            Route::post('/conversas/{conversa}/encaminhar', 'encaminhar')->whereNumber('conversa')->middleware('throttle:60,1')->name('encaminhar');
            Route::post('/conversas/{conversa}/cliente', 'vincularCliente')->whereNumber('conversa')->middleware('throttle:60,1')->name('cliente');
            Route::post('/conversas/{conversa}/cliente/novo', 'cadastrarCliente')->whereNumber('conversa')->middleware('throttle:30,1')->name('cliente.novo');
            Route::post('/conversas/{conversa}/ordem', 'vincularOrdem')->whereNumber('conversa')->middleware('throttle:60,1')->name('ordem');
            Route::post('/conversas/{conversa}/simular-celular', 'simularCelular')->whereNumber('conversa')->middleware('throttle:30,1')->name('simular-celular');
            Route::post('/mensagens/{mensagem}/tentar', 'tentar')->whereNumber('mensagem')->middleware('throttle:20,1')->name('tentar');
            Route::get('/mensagens/{mensagem}/midia', 'midia')->whereNumber('mensagem')->middleware('throttle:120,1')->name('midia');
        });
        Route::middleware(['can:configurar', 'password.confirm'])->prefix('configuracoes/whatsapp')->name('whatsapp.numeros.')
            ->controller(\App\Http\Controllers\WhatsAppNumeroController::class)->group(function () {
                Route::get('/numeros', 'index')->name('index');
                Route::post('/numeros', 'store')->name('store');
                Route::put('/numeros/{numero}', 'update')->whereNumber('numero')->name('update');
                Route::put('/numeros/{numero}/ativo', 'alternarAtivo')->whereNumber('numero')->name('ativo');
                Route::delete('/numeros/{numero}/conversas', 'apagarConversas')->whereNumber('numero')->middleware('throttle:5,1')->name('conversas.apagar');
                Route::post('/numeros/{numero}/drope-webhook', 'registrarWebhookDrope')->whereNumber('numero')->middleware('throttle:5,1')->name('drope-webhook');
            });

        // Configurações sensíveis: somente proprietário e com reautenticação recente.
        Route::middleware(['can:configurar', 'password.confirm'])->prefix('configuracoes')->group(function () {
            Route::get('/unidades', [UnidadeController::class, 'index'])->name('unidades.index');
            Route::post('/unidades', [UnidadeController::class, 'store'])->name('unidades.store');
            Route::put('/unidades/{unidade}', [UnidadeController::class, 'update'])->name('unidades.update');

            Route::get('/recebedores', [RecebedorController::class, 'index'])->name('recebedores.index');
            Route::post('/recebedores', [RecebedorController::class, 'store'])->name('recebedores.store');

            Route::get('/usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
            Route::get('/usuarios/novo', [UsuarioController::class, 'create'])->name('usuarios.create');
            Route::post('/usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
            Route::get('/usuarios/{usuario}', [UsuarioController::class, 'edit'])->name('usuarios.edit');
            Route::put('/usuarios/{usuario}', [UsuarioController::class, 'update'])->name('usuarios.update');
            Route::post('/usuarios/{usuario}/ativo', [UsuarioController::class, 'alternarAtivo'])->name('usuarios.ativo');
            Route::post('/usuarios/{usuario}/dois-fatores', [UsuarioController::class, 'gerarDoisFatores'])->name('usuarios.dois-fatores');
        });
    });
});

// Bloco A (30/10/2026): data de entrega prevista em dias úteis. Autorização (gerente/proprietário, unidade) no controlador e no serviço.
Route::middleware(['auth', 'dois-fatores'])->group(function () {
    Route::post('/ordens/{ordem}/prazo-entrega', \App\Http\Controllers\PrazoEntregaController::class)
        ->middleware('throttle:30,1')->name('ordens.prazo');
});

// Identidade da empresa, vendedor na OS e OS impressa (31/10/2026). Autorização por unidade conferida nos controladores/serviços.
Route::middleware(['auth', 'dois-fatores'])->group(function () {
    Route::middleware(['can:configurar', 'password.confirm'])->prefix('configuracoes/empresa')->controller(\App\Http\Controllers\EmpresaController::class)->group(function () {
        Route::get('/', 'edit')->name('empresa.edit');
        Route::put('/', 'update')->name('empresa.update');
        Route::put('/unidades/{unidade}', 'unidade')->name('empresa.unidade.update');
    });
    // Dados fiscais (09/11/2026): NFS-e (serviços) e NF-e (peças); padrões vindos do sistema antigo, conferidos pelo contador.
    Route::middleware(['can:configurar', 'password.confirm'])->prefix('configuracoes/fiscal')->controller(\App\Http\Controllers\DadosFiscaisController::class)->group(function () {
        Route::get('/', 'edit')->name('fiscal.config.edit');
        Route::put('/', 'update')->name('fiscal.config.update');
    });
    // Certificado digital A1 (09/11/2026, etapa 2): guardado criptografado; nunca exibido.
    Route::middleware(['can:configurar', 'password.confirm', 'throttle:10,1'])->prefix('configuracoes/certificado')->controller(\App\Http\Controllers\CertificadoController::class)->group(function () {
        Route::get('/', 'edit')->name('certificado.edit');
        Route::post('/', 'store')->name('certificado.store');
        Route::delete('/', 'destroy')->name('certificado.destroy');
    });
    // Aparência (31/10/2026): temas personalizados só pelo proprietário; cada usuário escolhe o próprio tema.
    Route::middleware(['can:configurar', 'password.confirm', 'throttle:30,1'])->prefix('configuracoes/temas')->controller(\App\Http\Controllers\TemaController::class)->group(function () {
        Route::post('/', 'store')->name('temas.store');
        Route::put('/{tema}', 'update')->name('temas.update');
        Route::delete('/{tema}', 'destroy')->name('temas.destroy');
    });
    Route::put('/conta/tema', [\App\Http\Controllers\ContaController::class, 'tema'])->middleware('throttle:30,1')->name('conta.tema');
    Route::put('/ordens/{ordem}/chassi', [\App\Http\Controllers\VeiculoController::class, 'chassi'])->middleware('throttle:30,1')->name('ordens.chassi');
    Route::post('/ordens/{ordem}/vendedor', [\App\Http\Controllers\OsImpressaController::class, 'vendedor'])->middleware('throttle:30,1')->name('ordens.vendedor');
    Route::get('/ordens/{ordem}/impressao', [\App\Http\Controllers\OsImpressaController::class, 'interna'])->middleware('throttle:60,1')->name('ordens.impressao');
});
// Logo da empresa é pública: aparece na tela de entrada e no portal do cliente (bitmap validado no upload, nosniff).
Route::get('/empresa/logo', [\App\Http\Controllers\EmpresaController::class, 'logo'])->middleware('throttle:120,1')->name('empresa.logo');
Route::get('/c/{token}/os', [\App\Http\Controllers\OsImpressaController::class, 'portal'])->middleware('throttle:portal')->name('portal.impressao');

// Supervisor multiunidade + feriados estaduais/municipais por unidade (31/10/2026). Proprietário ou supervisor; unidade conferida no controlador.
Route::middleware(['auth', 'dois-fatores'])->group(function () {
    Route::get('/feriados', [\App\Http\Controllers\FeriadoUnidadeController::class, 'index'])->name('feriados.index');
    Route::post('/feriados', [\App\Http\Controllers\FeriadoUnidadeController::class, 'store'])->middleware('throttle:30,1')->name('feriados.store');
    Route::delete('/feriados/{feriado}', [\App\Http\Controllers\FeriadoUnidadeController::class, 'destroy'])->whereNumber('feriado')->middleware('throttle:30,1')->name('feriados.destroy');
});

// Catálogo de serviços/produtos, limite de desconto e aprovação interna (31/10/2026).
// Permissões (gerir_catalogo/configurar, aprovar_desconto, ver_valores), unidade e autoaprovação conferidas nos serviços.
Route::middleware(['auth', 'dois-fatores'])->group(function () {
    Route::prefix('configuracoes/catalogo')->name('catalogo.')->controller(\App\Http\Controllers\CatalogoController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->middleware('throttle:60,1')->name('store');
        Route::post('/importar', 'importar')->middleware('throttle:10,1')->name('importar');
        Route::get('/{item}', 'edit')->whereNumber('item')->name('edit');
        Route::put('/{item}', 'update')->whereNumber('item')->middleware('throttle:60,1')->name('update');
    });
    Route::get('/aprovacoes', [\App\Http\Controllers\AprovacaoInternaController::class, 'index'])->name('aprovacoes.index');
    Route::post('/aprovacoes/{aprovacao}/decidir', [\App\Http\Controllers\AprovacaoInternaController::class, 'decidir'])
        ->whereNumber('aprovacao')->middleware('throttle:60,1')->name('aprovacoes.decidir');
});

// Pós-venda (decisões de 30/10/2026, item 8): recepção, gerente/supervisor e proprietário (permissão pos_venda; mecânico nunca).
// Unidade da OS e situação "entregue" conferidas no serviço App\Services\PosVenda.
Route::middleware(['auth', 'dois-fatores', 'can:pos_venda'])->prefix('pos-venda')->name('posvenda.')
    ->controller(\App\Http\Controllers\PosVendaController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/exportar', 'exportar')->middleware('throttle:20,1')->name('exportar');
        Route::get('/{ordem}', 'show')->name('show');
        Route::post('/{ordem}/contatos', 'registrar')->middleware('throttle:60,1')->name('registrar');
    });

// Enviar OS ao cliente por WhatsApp (30/10/2026): outbox oficial (documento_os; simulado em demo) ou "Abrir no meu WhatsApp" (wa.me).
// ver_valores + unidade conferidos pela policy (imprimir) no controlador; mecânico 403; link do portal gerado só neste POST.
Route::middleware(['auth', 'dois-fatores'])->group(function () {
    Route::post('/ordens/{ordem}/os/whatsapp', [\App\Http\Controllers\OsImpressaController::class, 'whatsapp'])->middleware('throttle:10,1')->name('ordens.os.whatsapp');
});

// Pagamento guiado "Como o cliente vai pagar?" (30/10/2026, decisão 7). Permissões e unidade nos serviços:
// agora → cobrar (Pix) | receber_manual; dividir → combinar_pagamento + cobrar/receber_manual (403 sem). Mecânico 403.
Route::middleware(['auth', 'dois-fatores'])->group(function () {
    Route::post('/ordens/{ordem}/pagamento/agora', [\App\Http\Controllers\PagamentoGuiadoController::class, 'agora'])->middleware('throttle:30,1')->name('pagamentos.agora');
    Route::post('/ordens/{ordem}/pagamento/dividido', [\App\Http\Controllers\PagamentoGuiadoController::class, 'dividir'])->middleware(['can:combinar_pagamento', 'throttle:30,1'])->name('pagamentos.dividido');
});
