<?php

namespace App\Demo;

use App\Enums\Papel;
use App\Enums\StatusOrdem;
use App\Integracoes\Pix\SimuladorPix;
use App\Models\Ordem;
use App\Models\Recebedor;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Conferencias;
use App\Services\Entradas;
use App\Services\Execucao;
use App\Services\Financeiro;
use App\Services\Fiscal;
use App\Services\Orcamentos;
use App\Services\Ordens;
use App\Support\Fuso;
use App\Support\Totp;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Um mês de movimento fictício nas três unidades, criado pelos serviços de domínio (totais, auditoria e
 * relações coerentes). Determinístico: RNG com semente fixa e relógio ancorado na data de referência.
 */
class GeradorDemo
{
    private const NOMES = ['Mariana Costa', 'Rafael Alves', 'Beatriz Rocha', 'Lucas Mendes', 'Fernanda Lima', 'Carlos Souza', 'Juliana Ferreira', 'Pedro Santos',
        'Aline Ribeiro', 'Bruno Carvalho', 'Camila Gomes', 'Diego Martins', 'Eduarda Araújo', 'Felipe Barbosa', 'Gabriela Teixeira', 'Henrique Lopes',
        'Isabela Moreira', 'João Pereira', 'Larissa Cardoso', 'Marcelo Dias', 'Natália Freitas', 'Otávio Nunes', 'Patrícia Rezende', 'Renato Castro'];
    private const MODELOS = ['Honda Fit', 'Fiat Argo', 'VW Polo', 'Chevrolet Onix', 'Hyundai HB20', 'Toyota Corolla', 'Renault Kwid', 'Jeep Renegade', 'Ford Ka', 'Nissan Kicks'];
    private const MOTIVOS = ['Barulho na suspensão dianteira', 'Revisão dos 30 mil km', 'Freio rangendo', 'Luz da injeção acesa', 'Troca de óleo', 'Carro puxando para a direita',
        'Ar-condicionado sem gelar', 'Bateria descarregando', 'Vibração no volante', 'Superaquecimento'];
    /** [descrição, quantidade, preço em reais] — quantidades fracionárias exercitam o arredondamento. */
    private const SERVICOS = [['Troca de óleo e filtro', '1', '189,90'], ['Alinhamento e balanceamento', '1', '120,00'], ['Pastilhas de freio dianteiras', '1', '280,00'],
        ['Bieleta dianteira', '2', '180,00'], ['Diagnóstico eletrônico', '1', '90,00'], ['Correia dentada', '1', '650,00'], ['Velas de ignição', '4', '45,00'],
        ['Fluido de arrefecimento (litro)', '2,5', '32,90'], ['Fluido de freio (litro)', '0,5', '58,30'], ['Higienização do ar-condicionado', '1', '150,00'], ['Bateria 60Ah', '1', '520,00']];

    private int $placaSeq = 0;
    private int $atendimentoSeq = 0; // entrada sem veículo determinística (sem consumir o RNG)
    private array $resumo = [];
    private \Random\Randomizer $rng;

    private function r(int $min, int $max): int
    {
        return $this->rng->getInt($min, $max);
    }

    public function gerar(CarbonImmutable $referencia): array
    {
        // RNG próprio (não o global): o destrutor do Faker chama mt_srand() sem semente quando o GC o coleta,
        // o que embaralhava a demo conforme o histórico do processo (causa demonstrada em 06/11/2026).
        $this->rng = new \Random\Randomizer(new \Random\Engine\Mt19937(20261029));
        $dias = (int) config('oficina.demo.dias', 30);
        $inicio = $referencia->subDays($dias - 1)->setTimezone(Fuso::LOCAL)->startOfDay();
        $this->em($inicio->setTime(7, 0));
        $segredo = Totp::base32('demo-oficina-2026-2fa'); // fixo: só para a conta fictícia
        $dono = new User(['name' => 'Proprietária Demo', 'email' => 'dono@demo.oficina.test', 'password' => config('oficina.demo.senha')]);
        $dono->forceFill(['papel' => Papel::Proprietario, 'two_factor_secret' => $segredo, 'two_factor_confirmed_at' => now()])->save();

        // Identidade fictícia para a OS impressa (CNPJ com dígitos válidos, sem pertencer a empresa real conhecida).
        \App\Models\Empresa::create(['razao_social' => 'Oficina Demonstração Ltda (fictícia)', 'nome_fantasia' => 'Oficina Demo', 'cnpj' => '11222333000181',
            'telefone' => '6130000100', 'email' => 'contato@demo.oficina.test', 'logradouro' => 'Quadra Fictícia 1', 'numero' => '10', 'bairro' => 'Centro', 'cidade' => 'Brasília', 'uf' => 'DF', 'cep' => '70000000']);
        $equipes = [];
        foreach ([['ASN', 'Oficina Asa Norte'], ['TAG', 'Oficina Taguatinga'], ['AGC', 'Oficina Águas Claras']] as [$codigo, $nome]) {
            $u = Unidade::create(['codigo' => $codigo, 'nome' => $nome, 'ativa' => true]);
            Recebedor::create(['unidade_id' => $u->id, 'provedor' => 'simulado', 'identificador_conta' => 'demo-conta-'.strtolower($codigo), 'descricao' => "Conta PJ fictícia {$codigo}", 'ativo' => true, 'criado_por' => $dono->id]);
            $eq = ['unidade' => $u];
            foreach (['gerente' => Papel::Gerente, 'recepcao' => Papel::Recepcao, 'mecanico' => Papel::Mecanico, 'mecanico2' => Papel::Mecanico] as $chave => $papel) {
                $usr = new User(['name' => ucfirst(str_replace('2', ' 2', $chave)).' '.$codigo, 'email' => "{$chave}.".strtolower($codigo).'@demo.oficina.test', 'password' => config('oficina.demo.senha')]);
                $usr->forceFill(['papel' => $papel, 'unidade_id' => $u->id])->save();
                $eq[$chave] = $usr;
            }
            $equipes[] = $eq;
        }

        for ($dia = 0; $dia < $dias; $dia++) {
            $data = $inicio->addDays($dia);
            $recente = $dia >= $dias - 2; // últimos dois dias: atendimentos em andamento
            foreach ($equipes as $eq) {
                $qtd = $data->isSunday() ? 0 : $this->r(2, 4);
                for ($n = 0; $n < $qtd; $n++) {
                    $this->atendimento($eq, $data->setTime(8, 0)->addMinutes($this->r(0, 300)), $recente, $referencia);
                }
                if (! $data->isSunday()) {
                    $this->em($data->setTime(17, 30));
                    $placas = $this->r(1, 6) === 1 ? ['DMO'.$this->r(1, 9).'Z'.str_pad((string) $this->r(0, 99), 2, '0', STR_PAD_LEFT)] : [];
                    $esperadas = app(Conferencias::class)->esperadas($eq['unidade']->id)->pluck('id')->all();
                    app(Conferencias::class)->registrar($eq['unidade']->id, $eq['recepcao'], $esperadas, $placas);
                    $this->conta('conferencias');
                    if ($dia < $dias - 1) {
                        $this->em($data->setTime(18, 30));
                        $dinheiro = app(Financeiro::class)->dinheiroEsperado($eq['unidade']->id, $data->format('Y-m-d'));
                        app(Financeiro::class)->fecharCaixa($eq['unidade']->id, $data->format('Y-m-d'), $eq['gerente'], $dia % 9 === 4 ? max(0, $dinheiro - 2000) : $dinheiro, $dia % 9 === 4 ? 'Diferença de troco (cenário de demonstração)' : null);
                        $this->conta('fechamentos');
                    }
                }
            }
        }
        // Divergências antigas resolvidas pelo gerente; as dos últimos dias ficam abertas.
        $this->em($referencia->setTime(9, 0));
        foreach (\App\Models\Divergencia::whereNull('resolvido_em')->where('created_at', '<', $referencia->subDays(2))->get() as $d) {
            $ger = collect($equipes)->firstWhere('unidade.id', $d->unidade_id)['gerente'];
            app(Conferencias::class)->resolver($d, $ger, 'Verificado no pátio (cenário de demonstração)');
        }
        $this->whatsappDemo($equipes, $dono, $referencia); // Central de WhatsApp (RF23): bloco isolado
        $this->posVendaDemo($equipes, $referencia); // Pós-venda: contatos FICTÍCIOS (nada é enviado)
        $this->supervisorDemo($equipes, $referencia); // Supervisor multiunidade + feriado municipal: bloco isolado
        $this->catalogoDemo($dono, $referencia); // Catálogo de estética de rodas e baterias: bloco isolado
        Carbon::setTestNow();

        return ['resumo' => $this->resumo, 'segredo_2fa' => $segredo, 'unidades' => collect($equipes)->map(fn ($e) => $e['unidade']->codigo)->all()];
    }

    private function atendimento(array $eq, CarbonImmutable $t, bool $recente, CarbonImmutable $ref): void
    {
        [$u, $rec, $ger] = [$eq['unidade'], $eq['recepcao'], $eq['gerente']];
        $mec = $this->r(0, 1) ? $eq['mecanico'] : $eq['mecanico2'];
        $nome = self::NOMES[$this->r(0, count(self::NOMES) - 1)];
        $this->em($t);
        $semVeiculo = ++$this->atendimentoSeq % 17 === 0; // cliente trouxe só o jogo de rodas
        $placa = $this->placa();
        $o = app(Entradas::class)->registrar($rec, [
            'unidade_id' => $u->id, 'nome' => $nome, 'telefone' => '619'.str_pad((string) (10000000 + crc32($nome) % 8999999), 8, '0', STR_PAD_LEFT),
            'sem_veiculo' => $semVeiculo, 'item_descricao' => $semVeiculo ? 'Jogo de rodas aro 17 — 4 unidades (sem veículo)' : null,
            'placa' => $placa, 'motivo' => self::MOTIVOS[$this->r(0, count(self::MOTIVOS) - 1)], 'modelo' => self::MODELOS[$this->r(0, count(self::MODELOS) - 1)],
            'quilometragem' => $this->r(8000, 160000),
        ]);
        $this->conta('ordens');

        // Cenário escolhido pelo RNG; atendimentos recentes param em etapas intermediárias.
        $cenario = $recente ? ['entrada', 'diagnostico', 'aguardando', 'autorizado', 'execucao', 'pronto'][$this->r(0, 5)] : $this->sortear();
        $this->conta('cenario_'.$cenario);
        if ($cenario === 'entrada') {
            return;
        }
        $t = $t->addMinutes($this->r(20, 90));
        $this->em($t);
        app(Ordens::class)->iniciarDiagnostico($o, $mec);
        app(Ordens::class)->registrarDiagnostico($o, 'Avaliação: '.mb_strtolower(self::MOTIVOS[$this->r(0, 9)]).'.', $mec);
        if ($cenario === 'diagnostico') {
            return;
        }
        if ($cenario === 'cancelamento') {
            $this->em($t->addMinutes(40));
            app(Ordens::class)->cancelar($o, 'Cliente desistiu após diagnóstico', $ger);

            return;
        }
        $itens = $this->itens($cenario === 'fiscal_rejeitado');
        $orc = app(Orcamentos::class);
        $desconto = $cenario === 'desconto' ? 2000 * $this->r(1, 3) : 0;
        // Nunca maior que metade do subtotal (ex.: só 0,5 L de fluido de freio = R$ 29,15).
        $subtotal = array_sum(array_map(fn ($i) => (int) round((float) str_replace(',', '.', $i['quantidade']) * (float) str_replace(',', '.', $i['preco']) * 100), $itens));
        $desconto = min($desconto, intdiv($subtotal, 2));
        // Só gerente/proprietário orçam (decisão de 30/10/2026); a recepção acompanha o cliente.
        $v = $orc->salvarRascunho($o, $ger, $itens, $desconto, $desconto ? 'Cliente recorrente (demonstração)' : null);
        // Desconto acima do limite: a proprietária aprova antes do envio (aprovação interna), sem novo sorteio.
        $aprovacao = app(\App\Services\AprovacoesInternas::class)->situacao($v);
        if ($aprovacao['exige'] && ! $aprovacao['liberado'] && $aprovacao['pedido']) {
            app(\App\Services\AprovacoesInternas::class)->decidir($aprovacao['pedido'], \App\Models\User::where('papel', 'proprietario')->orderBy('id')->firstOrFail(), 'aprovada', 'Cliente recorrente; desconto autorizado (demonstração).');
        }
        $this->em($t = $t->addMinutes($this->r(15, 60)));
        $orc->enviar($v, $ger);
        app(\App\Services\PrazosEntrega::class)->definir($o, $ger, diasUteis: 3); // data de entrega em dias úteis (Bloco A)
        if ($cenario === 'aguardando') {
            return;
        }
        $this->em($t = $t->addMinutes($this->r(20, 180)));
        if ($cenario === 'recusa') {
            $orc->decidir($v->id, $o->id, 'recusado', 'link_cliente', null, 'Vou pensar (demonstração)');

            return;
        }
        $this->r(0, 3) ? $orc->decidir($v->id, $o->id, 'aprovado', 'link_cliente') : $orc->decidirPresencial($v->fresh(), $rec, 'aprovado', 'Cliente aprovou por telefone');
        if ($cenario === 'autorizado') {
            return;
        }
        $exec = app(Execucao::class);
        $this->em($t = $t->addMinutes($this->r(20, 60)));
        $exec->iniciar($o, $mec);
        if ($cenario === 'extra') {
            $this->em($t = $t->addMinutes(40));
            $v2 = $orc->salvarRascunho($o, $ger, [...$itens, ['descricao' => 'Coxim do motor (extra)', 'quantidade' => '1', 'preco' => '240,00']]);
            $orc->enviar($v2, $ger);
            $this->em($t = $t->addMinutes($this->r(20, 90)));
            $orc->decidir($v2->id, $o->id, $this->r(0, 2) ? 'aprovado' : 'recusado', 'link_cliente');
        }
        foreach ($o->fresh()->orcamentoAprovado->itens as $i) {
            $this->em($t = $t->addMinutes($this->r(20, 70)));
            $exec->concluirItem($o, $i->id, $mec);
            if ($cenario === 'execucao' || $cenario === 'atraso') {
                return; // fica em execução (atraso: atendimento antigo ainda aberto)
            }
        }
        $this->em($t = $t->addMinutes(15));
        $exec->marcarPronto($o, $mec, 'Teste de rodagem realizado.');
        if ($cenario === 'pronto') {
            return;
        }
        $this->pagarEntregar($o, $cenario, $t, $rec, $ger);
    }

    private function pagarEntregar(Ordem $o, string $cenario, CarbonImmutable $t, User $rec, User $ger): void
    {
        $fin = app(Financeiro::class);
        $total = $fin->resumo($o)['saldo'];
        $this->em($t = $t->addMinutes($this->r(10, 120)));
        $p = null;
        if (in_array($cenario, ['dinheiro', 'cartao'], true)) {
            $p = $fin->registrarManual($o, $rec, $cenario === 'dinheiro' ? 'dinheiro' : 'cartao_externo', $total, $cenario === 'cartao' ? 'NSU '.$this->r(100000, 999999) : null);
        } elseif (in_array($cenario, ['parcial', 'excecao'], true)) {
            $fin->registrarManual($o, $rec, 'dinheiro', intdiv($total, 2));
        } else {
            $c = $fin->gerarCobranca($o, $rec);
            $this->em($t = $t->addMinutes($this->r(3, 40)));
            $pago = match ($cenario) { 'pix_divergente' => $total - 1000, 'devolucao' => $total + 5000, default => null };
            app(SimuladorPix::class)->simularPagamento($c->txid, $pago);
            $fin->conciliarCobranca($c->txid);
            $p = \App\Models\Pagamento::where('cobranca_id', $c->id)->first();
            if ($cenario === 'pix_divergente') {
                return; // fica em revisão: alerta no painel
            }
        }
        if ($cenario === 'parcial') {
            return; // aguardando restante do pagamento
        }
        if ($cenario === 'devolucao' && $p) {
            // Cliente pagou a mais: gerente confere, aceita e devolve o excedente.
            $this->em($t = $t->addMinutes(30));
            $fin->aceitarRevisao($p, $ger, 'Pagamento a maior conferido no extrato (demonstração)');
            // Cenário "devolução Pix falhou": metade das devoluções (1ª, 3ª, …) é recusada pelo provedor SIMULADO
            // (sem sorteio extra, para não alterar a sequência reproduzível). Fica em Pendências e a OS aguarda.
            $falhar = ($this->resumo['cenario_devolucao'] ?? 0) % 2 === 1;
            $anterior = config('oficina.pix.simular_falha_devolucao');
            config(['oficina.pix.simular_falha_devolucao' => $falhar]);
            try {
                $d = $fin->devolver($p, $ger, 5000, 'Devolução do valor pago a mais (demonstração)');
            } finally {
                config(['oficina.pix.simular_falha_devolucao' => $anterior]);
            }
            if ($d->status === 'falhou') {
                $this->conta('devolucoes_falhas');

                return; // aguardando nova tentativa ou devolução por outro meio
            }
        }
        $this->em($t = $t->addMinutes($this->r(10, 240)));
        $fin->entregar($o, $cenario === 'excecao' ? $ger : $rec, $cenario === 'excecao' ? 'Cliente frotista com faturamento mensal (demonstração)' : null);
        if ($this->r(0, 1) || $cenario === 'fiscal_rejeitado') {
            app(Fiscal::class)->solicitar($o, $rec);
            $this->conta('documentos_fiscais');
        }
    }

    /**
     * Pós-venda — contatos de exemplo nas OS entregues de cada unidade (determinístico, sem consumir o RNG do restante da demo):
     * cliente satisfeito, neutro, detrator com problema no serviço, negociação fora do padrão, não atendeu com retorno atrasado
     * e retorno agendado para hoje. Nenhuma ligação ou mensagem real é feita.
     */
    private function posVendaDemo(array $equipes, CarbonImmutable $ref): void
    {
        $pv = app(\App\Services\PosVenda::class);
        $hoje = $ref->setTimezone(Fuso::LOCAL);
        foreach ($equipes as $eq) {
            $ordens = Ordem::where('unidade_id', $eq['unidade']->id)->where('status', StatusOrdem::Entregue)->orderByDesc('id')->limit(6)->get();
            $cenarios = [
                fn ($o) => ['tentativa' => 'ligacao', 'resultado' => 'atendeu', 'nota' => 10, 'comentario' => 'Gostou do atendimento e do prazo (demonstração).'],
                fn ($o) => ['tentativa' => 'mensagem', 'resultado' => 'atendeu', 'nota' => 8],
                fn ($o) => ['tentativa' => 'ligacao', 'resultado' => 'atendeu', 'nota' => 4, 'problema_servico' => true, 'problema_descricao' => 'Barulho voltou dois dias depois da entrega (demonstração).'],
                fn ($o) => ['tentativa' => 'ligacao', 'resultado' => 'atendeu', 'nota' => 9, 'negociacao_fora_padrao' => true, 'negociacao_descricao' => 'Desconto prometido na entrega sem registro no orçamento (demonstração).'],
            ];
            foreach ($ordens->take(4)->values() as $n => $o) {
                $this->em($hoje->setTime(10, 0)->addMinutes($n * 7));
                $pv->registrarContato($o, $eq['recepcao'], $cenarios[$n]($o));
                $this->conta('pos_venda_contatos');
            }
            if ($o = $ordens->get(4)) { // não atendeu; retorno combinado para ontem (atrasado)
                $this->em($hoje->subDays(3)->setTime(15, 0));
                $pv->registrarContato($o, $eq['recepcao'], ['tentativa' => 'ligacao', 'resultado' => 'nao_atendeu', 'retorno_em' => $hoje->subDay()->toDateString()]);
                $this->conta('pos_venda_contatos');
            }
            if ($o = $ordens->get(5)) { // pediu para ligar hoje
                $this->em($hoje->subDay()->setTime(16, 0));
                $pv->registrarContato($o, $eq['recepcao'], ['tentativa' => 'mensagem', 'resultado' => 'atendeu', 'comentario' => 'Pediu retorno amanhã (demonstração).', 'retorno_em' => $hoje->toDateString()]);
                $this->conta('pos_venda_contatos');
            }
        }
    }

    private function sortear(): string
    {
        $pesos = ['pix' => 32, 'dinheiro' => 12, 'cartao' => 12, 'recusa' => 7, 'extra' => 8, 'desconto' => 6, 'parcial' => 4, 'cancelamento' => 4,
            'devolucao' => 3, 'excecao' => 3, 'pix_divergente' => 2, 'atraso' => 4, 'fiscal_rejeitado' => 2, 'pronto' => 1];
        $r = $this->r(1, array_sum($pesos));
        foreach ($pesos as $c => $p) {
            if (($r -= $p) <= 0) {
                return $c;
            }
        }

        return 'pix';
    }

    private function itens(bool $rejeitavel): array
    {
        if ($rejeitavel) {
            // Total terminado em 13 centavos: o simulador fiscal rejeita (cenário de falha recuperável).
            return [['descricao' => 'Diagnóstico eletrônico', 'quantidade' => '1', 'preco' => '90,00'], ['descricao' => 'Taxa de descarte', 'quantidade' => '1', 'preco' => '10,13']];
        }
        $n = $this->r(1, 3);
        $escolhidos = [];
        while (count($escolhidos) < $n) {
            $escolhidos[$this->r(0, count(self::SERVICOS) - 1)] = true;
        }
        return array_map(fn ($i) => array_combine(['descricao', 'quantidade', 'preco'], self::SERVICOS[$i]), array_keys($escolhidos));
    }

    private function placa(): string
    {
        $n = ++$this->placaSeq;

        return 'DM'.chr(65 + intdiv($n, 1000) % 26).($n % 10).chr(65 + intdiv($n, 10) % 26).str_pad((string) (intdiv($n, 100) % 10 * 10 + $n % 7), 2, '0', STR_PAD_LEFT);
    }

    private function em(CarbonImmutable $t): void
    {
        Carbon::setTestNow($t->utc());
        CarbonImmutable::setTestNow($t->utc());
    }

    private function conta(string $k): void
    {
        $this->resumo[$k] = ($this->resumo[$k] ?? 0) + 1;
    }

    /**
     * Supervisor multiunidade (sem gerente próprio, como Goiânia): visão de gerente em duas unidades fictícias.
     * Feriado municipal FICTÍCIO na unidade principal dele, num dia útil à frente (afeta só prazos definidos depois).
     * Não usa mt_rand: não altera a sequência sorteada dos demais cenários.
     */
    private function supervisorDemo(array $equipes, CarbonImmutable $ref): void
    {
        [$a, $b] = [$equipes[1]['unidade'], $equipes[2]['unidade']];
        $this->em($ref->setTime(8, 0));
        $sup = new User(['name' => 'Supervisor Demo', 'email' => 'supervisor@demo.oficina.test', 'password' => config('oficina.demo.senha')]);
        $sup->forceFill(['papel' => Papel::Supervisor, 'unidade_id' => $a->id])->save();
        $sup->unidadesSupervisionadas()->sync([$a->id, $b->id]);
        $this->conta('supervisores');

        $dia = $ref->setTimezone(Fuso::LOCAL)->startOfDay()->addDays(7);
        while (! \App\Support\DiasUteis::ehUtil($dia)) { // dia útil nacional
            $dia = $dia->addDay();
        }
        \App\Models\FeriadoUnidade::create(['unidade_id' => $a->id, 'data' => $dia->format('Y-m-d'), 'descricao' => 'Feriado municipal (cenário de demonstração)', 'criado_por' => $sup->id]);
        $this->conta('feriados_locais');
    }

    /**
     * Central de WhatsApp (RF23) — conversas FICTÍCIAS pelo simulador (nada é enviado nem recebido de verdade).
     * Número central (triagem, coexistência) e um por unidade; cenários: sem resposta (alerta), respondida, resposta pelo
     * celular, envio falho, fora da janela de 24 h, triagem encaminhada, resolvida e mídias simuladas.
     */
    private function whatsappDemo(array $equipes, User $dono, CarbonImmutable $ref): void
    {
        $receb = app(\App\Services\WhatsApp\Recebimento::class);
        $atend = app(\App\Services\WhatsApp\Atendimento::class);
        $sim = new \App\Integracoes\WhatsApp\SimuladorWhatsApp;
        $seq = 0;
        $chegar = function (\App\Models\WhatsappNumero $n, CarbonImmutable $t, string $tel, ?string $nome, array $conteudo, string $tipo = 'mensagem') use ($receb, $sim, &$seq) {
            $this->em($t);
            $ev = ['tipo' => $tipo, 'id' => 'DEMO-WA-'.(++$seq), 'nome' => $nome, 'conteudo' => $conteudo, 'timestamp' => $t->getTimestamp()];
            $ev[$tipo === 'eco' ? 'para' : 'de'] = '55'.$tel;
            $receb->registrar('simulado', $sim->interpretar(['numero' => $n->telefone, 'eventos' => [$ev]]));

            return \App\Models\WhatsappConversa::where('numero_id', $n->id)->where('contato_telefone', $tel)->first();
        };
        $texto = fn (string $t) => ['tipo' => 'texto', 'texto' => $t];
        $modelos = [['nome' => 'retomar_contato', 'idioma' => 'pt_BR', 'descricao' => 'Retomar contato após 24 h']];
        $central = \App\Models\WhatsappNumero::create(['nome' => 'WhatsApp Central', 'telefone' => '6130000100', 'unidade_id' => null, 'provedor' => 'simulado',
            'modo_conexao' => 'coexistencia', 'modelos' => $modelos, 'minutos_alerta' => 15, 'criado_por' => $dono->id]);
        $porUnidade = [];
        foreach ($equipes as $i => $eq) {
            $porUnidade[$i] = \App\Models\WhatsappNumero::create(['nome' => 'WhatsApp '.$eq['unidade']->nome, 'telefone' => '613000010'.($i + 1), 'unidade_id' => $eq['unidade']->id,
                'provedor' => 'simulado', 'modo_conexao' => 'api', 'modelos' => $modelos, 'minutos_alerta' => $i === 2 ? 30 : 15, 'criado_por' => $dono->id]);
        }
        $clientes = \App\Models\Cliente::orderBy('id')->take(4)->get();
        $hoje = $ref->setTimezone(Fuso::LOCAL);

        // Unidade 1: cliente conhecido sem resposta há ~40 min (alerta) + foto do painel.
        $c1 = $clientes[0] ?? null;
        if ($c1) {
            $chegar($porUnidade[0], $hoje->setTime(17, 15), $c1->telefone, $c1->nome, $texto('Boa tarde! O carro já ficou pronto?'));
            $chegar($porUnidade[0], $hoje->setTime(17, 18), $c1->telefone, $c1->nome, ['tipo' => 'imagem', 'midia_id' => 'SIM-MIDIA-imagem-demo1', 'mime' => 'image/png', 'nome' => 'painel.png', 'legenda' => 'Apareceu essa luz no painel']);
        }
        // Unidade 1: respondida pela recepção e confirmada como lida.
        $c2 = $clientes[1] ?? null;
        if ($c2) {
            $conv = $chegar($porUnidade[0], $hoje->setTime(9, 40), $c2->telefone, $c2->nome, $texto('Bom dia, consigo levar o carro amanhã às 8h?'));
            $this->em($hoje->setTime(9, 47));
            $m = $atend->responder($conv, $equipes[0]['recepcao'], 'Bom dia! Pode sim, estaremos esperando às 8h.');
            if ($m->fresh()->id_provedor) {
                $receb->registrar('simulado', $sim->interpretar(['numero' => $porUnidade[0]->telefone, 'eventos' => [['tipo' => 'status', 'id' => $m->fresh()->id_provedor, 'status' => 'lida', 'timestamp' => $hoje->setTime(9, 50)->getTimestamp()]]]));
            }
            $chegar($porUnidade[0], $hoje->setTime(9, 52), $c2->telefone, $c2->nome, $texto('Perfeito, obrigado!'));
            $this->em($hoje->setTime(9, 55));
            $atend->mudarSituacao($conv->fresh(), $equipes[0]['recepcao'], 'resolvida', 'Agendamento confirmado (cenário de demonstração)');
        }
        // Unidade 2: contato desconhecido, áudio e documento; envio que falhou (simulador indisponível para final 0000).
        $conv = $chegar($porUnidade[1], $hoje->setTime(16, 5), '61990010000', 'Contato Demo Falha', ['tipo' => 'audio', 'midia_id' => 'SIM-MIDIA-audio-demo2', 'mime' => 'audio/ogg', 'nome' => 'audio.ogg']);
        $chegar($porUnidade[1], $hoje->setTime(16, 6), '61990010000', null, ['tipo' => 'documento', 'midia_id' => 'SIM-MIDIA-documento-demo2', 'mime' => 'application/pdf', 'nome' => 'nota-da-peca.pdf', 'legenda' => 'Segue a nota da peça']);
        $this->em($hoje->setTime(16, 20));
        $atend->responder($conv->fresh(), $equipes[1]['recepcao'], 'Recebemos o áudio e a nota, vamos verificar.');
        // Unidade 3: fora da janela de 24 h (só modelo aprovado).
        $chegar($porUnidade[2], $hoje->subDays(3)->setTime(11, 0), '61990000301', 'Cliente Antigo Demo', $texto('Vocês fazem revisão de ar-condicionado?'));
        // Central (triagem, coexistência): sem unidade, com resposta pelo celular da empresa.
        $c3 = $clientes[2] ?? null;
        if ($c3) {
            $chegar($central, $hoje->setTime(17, 30), $c3->telefone, $c3->nome, $texto('Oi, queria saber o valor da troca de óleo.'));
        }
        $chegar($central, $hoje->setTime(16, 50), '61990000401', 'Visitante Demo', ['tipo' => 'localizacao', 'lat' => -15.7942, 'lng' => -47.8822, 'nome' => 'Estou aqui, o carro não liga']);
        $chegar($central, $hoje->setTime(16, 58), '61990000401', null, $texto('Vocês têm guincho?'), 'mensagem');
        $chegar($central, $hoje->setTime(17, 2), '61990000401', null, $texto('Temos sim! Já estamos enviando, chega em 30 min.'), 'eco');
        // Central: encaminhada para a unidade 2 pela proprietária.
        $c4 = $clientes[3] ?? null;
        if ($c4) {
            $conv = $chegar($central, $hoje->setTime(15, 10), $c4->telefone, $c4->nome, $texto('Preciso remarcar a retirada do meu carro.'));
            $this->em($hoje->setTime(15, 25));
            $atend->encaminhar($conv, $dono, $equipes[1]['unidade']->id, 'Cliente da unidade (cenário de demonstração)');
        }
        $this->resumo['whatsapp_conversas'] = \App\Models\WhatsappConversa::count();
    }

    /** Catálogo de exemplo (estética/reforma de rodas e baterias), fictício. Bloco isolado (31/10/2026). */
    private function catalogoDemo(User $dono, CarbonImmutable $ref): void
    {
        $this->em($ref->setTime(8, 0));
        $catalogo = app(\App\Services\Catalogo::class);
        $catalogo->importarCsv($dono, implode("\n", [
            'nome;categoria;unidade;preço;desconto_max',
            'Reforma geral aro 17;Rodas;roda;180,00;10',
            'Reforma geral aro 18;Rodas;roda;210,00;10',
            'Pintura de roda (cor original);Rodas;roda;150,00;8',
            'Diamantação de roda;Rodas;roda;190,00;5',
            'Desempeno de roda;Rodas;roda;120,00;10',
            'Jogo de rodas — reforma geral aro 17;Rodas;jogo;680,00;12',
            'Polimento de roda;Rodas;roda;90,00;15',
            'Verniz protetor;Rodas;roda;45,00;15',
            'Balanceamento;Rodas;roda;25,00;20',
            'Bateria 60Ah;Baterias;unidade;520,00;5',
            'Bateria 45Ah;Baterias;unidade;430,00;5',
            'Teste de bateria e alternador;Baterias;unidade;40,00;',
            'Mão de obra mecânica;Mecânica;hora;140,00;',
        ]));
        $porNome = \App\Models\CatalogoItem::pluck('id', 'nome');
        foreach (['Reforma geral aro 17' => ['Verniz protetor', 'Balanceamento', 'Desempeno de roda'], 'Jogo de rodas — reforma geral aro 17' => ['Verniz protetor', 'Balanceamento'],
            'Bateria 60Ah' => ['Teste de bateria e alternador'], 'Bateria 45Ah' => ['Teste de bateria e alternador']] as $item => $adicionais) {
            \App\Models\CatalogoItem::find($porNome[$item])?->adicionais()->sync(collect($adicionais)->map(fn ($n) => $porNome[$n])->all());
        }
    }
}
