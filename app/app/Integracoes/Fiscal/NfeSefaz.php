<?php

namespace App\Integracoes\Fiscal;

use App\Models\CertificadoDigital;
use App\Models\Empresa;
use App\Support\Municipios;
use NFePHP\Common\Certificate;
use NFePHP\DA\NFe\Danfe;
use NFePHP\NFe\Common\Standardize;
use NFePHP\NFe\Complements;
use NFePHP\NFe\Make;
use NFePHP\NFe\Tools;
use RuntimeException;

/**
 * NF-e 4.00 (modelo 55) pela biblioteca sped-nfe, autorizada na SVRS (UF do emitente, DF). 09/11/2026, etapa 2.
 * Espelha o que o sistema antigo emitia: Simples Nacional (CRT 1, CSOSN 102), consumidor final, venda presencial.
 * O envio usa SoapCurlVerificado (servidor verificado, chave em memória).
 */
class NfeSefaz
{
    public function __construct(private int $ambiente) {}

    /** Chave determinística (cNF derivado da chave idempotente): reenviar a mesma nota dá a mesma chave. */
    public static function chave(array $d, string $cnpj): string
    {
        $cnf = str_pad((string) (hexdec(substr(hash('sha256', 'cnf|'.$d['chave']), 0, 7)) % 100000000), 8, '0', STR_PAD_LEFT);
        $base = '53'.$d['criado_em']->format('ym').$cnpj.'55'.str_pad($d['serie'], 3, '0', STR_PAD_LEFT)
            .str_pad((string) $d['numero_dps'], 9, '0', STR_PAD_LEFT).'1'.$cnf;
        $soma = 0;
        $peso = 2;
        for ($i = strlen($base) - 1; $i >= 0; $i--) {
            $soma += (int) $base[$i] * $peso;
            $peso = $peso === 9 ? 2 : $peso + 1;
        }
        $dv = 11 - $soma % 11;

        return $base.($dv >= 10 ? 0 : $dv);
    }

    // ponytail: sem consulta prévia; reenviar a mesma chave dá duplicidade (204/539), tratada em emitir().

    /** O que falta no cliente; a SEFAZ recusa (726) e não adianta repetir. ponytail: destinatário sempre não contribuinte (indIEDest 9): o cadastro não guarda a inscrição estadual do cliente. */
    private static function pendencia(array $d): ?string
    {
        $l = $d['cliente_local'] ?? [];
        if (! in_array(strlen((string) preg_replace('/\D/', '', (string) ($d['cliente_documento'] ?? ''))), [11, 14], true)) {
            return 'Para a NF-e, cadastre o CPF ou CNPJ do cliente.';
        }
        if (empty($l['logradouro']) || empty($l['bairro']) || empty($l['uf'])) {
            return 'Para a NF-e, cadastre o endereço completo do cliente (rua, bairro e UF).';
        }

        return strtoupper($l['uf']) === 'DF' || Municipios::codigo($l['uf'], (string) ($l['cidade'] ?? '')) ? null
            : 'Confira a cidade e a UF do cliente: não encontrei esse município na tabela do IBGE.';
    }

    public function emitir(array $dados): array
    {
        if ($falta = self::pendencia($dados)) {
            return ['status' => 'rejeitado', 'numero' => null, 'protocolo' => null, 'mensagem' => $falta, 'documento' => null];
        }
        [$tools, $cert] = $this->tools();
        $xml = $tools->signNFe($this->xml($dados));
        $resp = $tools->sefazEnviaLote([$xml], (string) $dados['numero_dps'], 1);
        $st = (new Standardize)->toStd($resp);
        $cStat = (string) ($st->protNFe->infProt->cStat ?? $st->cStat ?? '');
        if (in_array($cStat, ['103', '105'], true) || $cStat === '') {
            throw new RuntimeException('SEFAZ não devolveu o resultado na hora ('.$cStat.'). Nova tentativa automática.');
        }
        if (in_array($cStat, ['108', '109'], true)) {
            throw new RuntimeException('SEFAZ indisponível ('.$cStat.'). Nova tentativa automática.');
        }
        if (in_array($cStat, ['204', '539'], true)) { // duplicidade: a mesma NF-e já foi autorizada antes
            $c = (new Standardize)->toStd($tools->sefazConsultaChave(self::chave($dados, $this->cnpj())));
            if ((string) ($c->cStat ?? '') === '100') {
                $resp = $tools->sefazConsultaChave(self::chave($dados, $this->cnpj()));
                $cStat = '100';
            }
        }
        if ($cStat !== '100') {
            $motivo = (string) ($st->protNFe->infProt->xMotivo ?? $st->xMotivo ?? 'Rejeitada');

            return ['status' => 'rejeitado', 'numero' => null, 'protocolo' => null, 'mensagem' => mb_substr($cStat.' '.$motivo, 0, 500), 'documento' => null];
        }
        $autorizado = Complements::toAuthorize($xml, $resp);
        preg_match('/<nProt>(\d+)<\/nProt>/', $autorizado, $p);
        $pdf = null;
        try {
            $pdf = (new Danfe($autorizado))->render();
        } catch (\Throwable) {
        }

        return ['status' => 'autorizado', 'numero' => (string) $dados['numero_dps'], 'protocolo' => $p[1] ?? null, 'chave_acesso' => self::chave($dados, $this->cnpj()),
            'mensagem' => null, 'xml' => $autorizado, 'documento' => $pdf, 'extensao' => 'pdf'];
    }

    public function cancelar(string $chave, string $motivo, string $protocolo): void
    {
        [$tools] = $this->tools();
        $st = (new Standardize)->toStd($tools->sefazCancela($chave, NfseNacional::texto($motivo, 255), $protocolo));
        $c = (string) ($st->retEvento->infEvento->cStat ?? '');
        if (! in_array($c, ['135', '155'], true)) {
            throw new RuntimeException('A SEFAZ recusou o cancelamento: '.$c.' '.($st->retEvento->infEvento->xMotivo ?? $st->xMotivo ?? ''));
        }
    }

    /** Monta a NF-e sem assinatura. */
    public function xml(array $d): string
    {
        $f = $d['fiscal'];
        $e = Empresa::atual();
        $cnpj = $this->cnpj();
        if ($f['inscricao_estadual'] === '') {
            throw new RuntimeException('Informe a inscrição estadual em Configurações → Dados fiscais para emitir NF-e.');
        }
        $homolog = $this->ambiente === 2;
        $interestadual = strtoupper((string) ($d['cliente_local']['uf'] ?? 'DF')) !== 'DF';
        $chave = self::chave($d, $cnpj);
        $o = fn (array $a) => (object) $a;
        $m = new Make;
        $m->taginfNFe($o(['versao' => '4.00', 'Id' => 'NFe'.$chave, 'pk_nItem' => null]));
        $m->tagide($o(['cUF' => 53, 'cNF' => substr($chave, 35, 8), 'natOp' => $f['nfe_natureza'], 'mod' => 55, 'serie' => (int) $d['serie'],
            'nNF' => $d['numero_dps'], 'dhEmi' => now('America/Sao_Paulo')->subMinute()->format('Y-m-d\TH:i:sP'), 'tpNF' => 1, 'idDest' => $interestadual ? 2 : 1,
            'cMunFG' => $f['municipio_ibge'], 'tpImp' => 1, 'tpEmis' => 1, 'cDV' => substr($chave, -1), 'tpAmb' => $this->ambiente, 'finNFe' => 1,
            'indFinal' => 1, 'indPres' => 1, 'procEmi' => 0, 'verProc' => 'ControleOficina1']));
        $m->tagemit($o(['xNome' => $e->razao_social ?: $e->nome_fantasia, 'xFant' => $e->nome_fantasia, 'IE' => $f['inscricao_estadual'],
            'IM' => $f['inscricao_municipal'] ?: null, 'CRT' => $f['regime'] === 'simples' ? 1 : 3, 'CNPJ' => $cnpj]));
        $m->tagenderEmit($o(['xLgr' => $e->logradouro, 'nro' => $e->numero ?: 'SN', 'xCpl' => $e->complemento ?: null, 'xBairro' => $e->bairro,
            'cMun' => $f['municipio_ibge'], 'xMun' => $e->cidade, 'UF' => $e->uf ?: 'DF', 'CEP' => preg_replace('/\D/', '', (string) $e->cep),
            'cPais' => 1058, 'xPais' => 'Brasil', 'fone' => preg_replace('/\D/', '', (string) $e->telefone) ?: null]));
        $doc = preg_replace('/\D/', '', (string) ($d['cliente_documento'] ?? ''));
        if (in_array(strlen($doc), [11, 14], true)) {
            $m->tagdest($o(['xNome' => $homolog ? 'NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL' : NfseNacional::texto($d['cliente'], 60),
                'indIEDest' => 9, strlen($doc) === 14 ? 'CNPJ' : 'CPF' => $doc, 'email' => $d['cliente_email'] ?: null]));
            $l = $d['cliente_local'];
            $fora = strtoupper($l['uf']) !== 'DF';
            [$cMun, $xMun] = $fora ? Municipios::buscar($l['uf'], (string) $l['cidade']) : [$f['municipio_ibge'], 'Brasilia'];
            $m->tagenderDest($o(['xLgr' => NfseNacional::texto($l['logradouro'], 60), 'nro' => NfseNacional::texto($l['numero'] ?: 'SN', 60), 'xCpl' => $l['complemento'] ? NfseNacional::texto($l['complemento'], 60) : null,
                'xBairro' => NfseNacional::texto($l['bairro'], 60), 'cMun' => $cMun, 'xMun' => $xMun, 'UF' => strtoupper($l['uf']), 'CEP' => preg_replace('/\D/', '', (string) $l['cep']) ?: null, 'cPais' => 1058, 'xPais' => 'Brasil']));
        }
        $itens = array_values($d['itens']);
        $bruto = array_sum(array_column($itens, 'total_centavos'));
        $desconto = max(0, $bruto - $d['valor_centavos']);
        $restante = $desconto;
        foreach ($itens as $k => $i) {
            $n = $k + 1;
            $desc = $n === count($itens) ? $restante : intdiv($desconto * (int) $i['total_centavos'], max($bruto, 1));
            $restante -= $desc;
            $q = (float) $i['quantidade'];
            $vUn = number_format($i['total_centavos'] / 100 / max($q, 0.0001), 10, '.', '');
            $m->tagprod($o(['item' => $n, 'cProd' => (string) ($i['codigo'] ?? $n), 'cEAN' => 'SEM GTIN', 'cEANTrib' => 'SEM GTIN',
                'xProd' => $homolog && $n === 1 ? 'NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL' : NfseNacional::texto($i['descricao'], 120),
                'NCM' => $i['ncm'] ?: $f['nfe_ncm_padrao'], 'CFOP' => $interestadual ? $f['nfe_cfop_fora'] : $f['nfe_cfop_dentro'], 'uCom' => 'UN', 'qCom' => $q, 'vUnCom' => $vUn,
                'vProd' => number_format($i['total_centavos'] / 100, 2, '.', ''), 'uTrib' => 'UN', 'qTrib' => $q, 'vUnTrib' => $vUn,
                'vDesc' => $desc > 0 ? number_format($desc / 100, 2, '.', '') : null, 'indTot' => 1]));
            $m->tagimposto($o(['item' => $n]));
            $m->tagICMSSN($o(['item' => $n, 'orig' => 0, 'CSOSN' => $f['nfe_csosn']]));
            if ($interestadual) { // como 3 das 4 notas do sistema antigo: DIFAL zerado (ponytail: contador confirma; uma nota para a BA destacou o valor)
                $m->tagICMSUFDest($o(['item' => $n, 'vBCUFDest' => 0, 'vBCFCPUFDest' => 0, 'pFCPUFDest' => 0, 'pICMSUFDest' => 0, 'pICMSInter' => 12, 'pICMSInterPart' => 100, 'vFCPUFDest' => 0, 'vICMSUFDest' => 0, 'vICMSUFRemet' => 0]));
            }
            $m->tagPIS($o(['item' => $n, 'CST' => '49', 'vBC' => 0, 'pPIS' => 0, 'vPIS' => 0]));
            $m->tagCOFINS($o(['item' => $n, 'CST' => '49', 'vBC' => 0, 'pCOFINS' => 0, 'vCOFINS' => 0]));
        }
        $m->tagICMSTot($o([]));
        $m->tagtransp($o(['modFrete' => 9]));
        $m->tagpag($o(['vTroco' => null]));
        $m->tagdetPag($o(['indPag' => 0, 'tPag' => '99', 'xPag' => 'Conforme ordem de servico', 'vPag' => number_format($d['valor_centavos'] / 100, 2, '.', '')]));
        $m->taginfAdic($o(['infCpl' => 'OS '.$d['ordem'].'. Documento emitido por ME ou EPP optante pelo Simples Nacional.']));
        $xml = $m->getXML();
        if (! $xml || $m->getErrors()) {
            throw new RuntimeException('NF-e com dados incompletos: '.implode('; ', $m->getErrors()));
        }

        return $xml;
    }

    private function cnpj(): string
    {
        return preg_replace('/\D/', '', (string) Empresa::atual()->cnpj);
    }

    /** @return array{0: Tools, 1: Certificate} */
    private function tools(): array
    {
        $c = CertificadoDigital::atual();
        if (! $c) {
            throw new RuntimeException('Certificado digital não enviado (Configurações → Certificado digital).');
        }
        if ($c->vencido()) {
            throw new RuntimeException('Certificado digital vencido em '.$c->valido_ate->format('d/m/Y').'.');
        }
        $e = Empresa::atual();
        $cert = $c->certificado();
        $tools = new Tools(json_encode(['atualizacao' => '2026-11-09 00:00:00', 'tpAmb' => $this->ambiente, 'razaosocial' => $e->razao_social ?: $e->nome_fantasia,
            'siglaUF' => 'DF', 'cnpj' => $this->cnpj(), 'schemes' => 'PL_010_V1.30', 'versao' => '4.00']), $cert);
        $tools->model('55');
        $tools->loadSoapClass(new SoapCurlVerificado);

        return [$tools, $cert];
    }
}
