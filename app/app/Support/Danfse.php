<?php

namespace App\Support;

use App\Rules\Cnpj;
use Com\Tecnick\Barcode\Barcode;
use DOMDocument;
use DOMXPath;
use NFePHP\DA\Legacy\Pdf;

/**
 * DANFSe gerado a partir do XML autorizado (09/11/2026): a Sefin nem sempre entrega o PDF oficial
 * (ADN 404 em homologação e 503 em produção quando testado). Campos conforme o XML; o contador confere o leiaute.
 * ponytail: uma página; descrição além do espaço é cortada (o XML continua completo).
 */
final class Danfse
{
    private Pdf $pdf;

    private DOMXPath $x;

    private const ESQ = 8.0;

    private const LARG = 194.0;

    public static function gerar(string $xml): ?string
    {
        $dom = new DOMDocument;
        if (! @$dom->loadXML($xml, LIBXML_NONET)) {
            return null;
        }
        $x = new DOMXPath($dom);
        $x->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');
        if (! $x->query('//n:infNFSe/n:DPS/n:infDPS')->length) {
            return null;
        }

        try {
            return (new self($x))->montar();
        } catch (\Throwable $e) { // PDF nunca derruba uma nota já autorizada
            report($e);

            return null;
        }
    }

    private function __construct(DOMXPath $x)
    {
        $this->x = $x;
    }

    private function v(string $caminho, string $padrao = '-'): string
    {
        $n = $this->x->query('//n:infNFSe/'.$caminho)->item(0);

        return $n && trim($n->nodeValue) !== '' ? trim($n->nodeValue) : $padrao;
    }

    private static function iso(string $s): string
    {
        return (string) iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
    }

    private static function doc(string $d): string
    {
        return match (strlen($d)) {
            14 => Cnpj::exibicao($d),
            11 => substr($d, 0, 3).'.'.substr($d, 3, 3).'.'.substr($d, 6, 3).'-'.substr($d, 9),
            default => $d,
        };
    }

    private static function data(string $iso, bool $hora = true): string
    {
        $t = strtotime($iso);

        return $t === false ? $iso : (new \DateTimeImmutable($iso))->format($hora ? 'd/m/Y H:i:s' : 'd/m/Y');
    }

    private static function moeda(string $v): string
    {
        return is_numeric($v) ? 'R$ '.number_format((float) $v, 2, ',', '.') : $v;
    }

    private function campo(float $x, float $y, float $l, string $rotulo, string $valor): void
    {
        $this->pdf->SetXY($x, $y);
        $this->pdf->SetFont('Arial', 'B', 6.5);
        $this->pdf->Cell($l, 3.2, self::iso($rotulo));
        $this->pdf->SetXY($x, $y + 3.2);
        $this->pdf->SetFont('Arial', '', 8);
        $this->pdf->Cell($l, 3.8, self::iso($valor));
    }

    /** @param list<array{0:float,1:string,2:string}> $linha [largura relativa, rótulo, valor] */
    private function linha(float $y, array $linha): float
    {
        $x = self::ESQ + 1;
        foreach ($linha as [$frac, $r, $v]) {
            $l = (self::LARG - 2) * $frac;
            $this->campo($x, $y, $l - 1, $r, $v);
            $x += $l;
        }

        return $y + 7.6;
    }

    private function secao(float $y, string $titulo): float
    {
        $this->pdf->SetFillColor(230, 230, 230);
        $this->pdf->SetXY(self::ESQ, $y);
        $this->pdf->SetFont('Arial', 'B', 8);
        $this->pdf->Cell(self::LARG, 4.6, self::iso($titulo), 1, 0, 'L', true);

        return $y + 5.4;
    }

    private function montar(): string
    {
        $this->pdf = new Pdf('P', 'mm', 'A4');
        $p = $this->pdf;
        $p->SetMargins(self::ESQ, 8);
        $p->SetAutoPageBreak(false);
        $p->AddPage();
        $p->SetTextColor(0, 0, 0);
        $homolog = $this->v('n:DPS/n:infDPS/n:tpAmb') === '2';
        $chave = substr($this->v('@Id', ''), 3);

        $y = 8.0;
        $p->SetXY(self::ESQ, $y);
        $p->SetFont('Arial', 'B', 15);
        $p->Cell(120, 7, 'DANFSe');
        $p->SetXY(self::ESQ, $y + 7);
        $p->SetFont('Arial', '', 9);
        $p->Cell(120, 4.5, self::iso('Documento Auxiliar da NFS-e'));
        $p->SetXY(self::ESQ, $y + 12);
        $p->SetFont('Arial', 'B', 9);
        $p->Cell(150, 4.5, self::iso($this->v('n:emit/n:xNome')));
        $url = 'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave='.$chave;
        $png = (new Barcode)->getBarcodeObj('QRCODE,M', $url, -3, -3, 'black', [-1, -1, -1, -1])->setBackgroundColor('white')->getPngData();
        $p->Image('data://text/plain;base64,'.base64_encode($png), 176, $y - 1, 26, 26, 'PNG');

        $y = 24.0;
        if ($homolog) {
            $p->SetFillColor(255, 0, 0);
            $p->SetTextColor(255, 255, 255);
            $p->SetXY(self::ESQ, $y);
            $p->SetFont('Arial', 'B', 10);
            $p->Cell(165, 6, self::iso('NFS-e SEM VALIDADE JURÍDICA'), 0, 0, 'C', true);
            $p->SetTextColor(0, 0, 0);
        }
        $y = 32.0;
        $d = 'n:DPS/n:infDPS/';
        $y = $this->secao($y, 'Identificação da NFS-e');
        $y = $this->linha($y, [[1.0, 'Chave de Acesso', $chave]]);
        $y = $this->linha($y, [[.2, 'Número da NFS-e', $this->v('n:nNFSe')], [.2, 'Competência', self::data($this->v($d.'n:dCompet'), false)], [.3, 'Data e Hora da Emissão da NFS-e', self::data($this->v('n:dhProc'))], [.3, 'Situação', $this->v('n:cStat') === '100' ? 'NFS-e Gerada' : $this->v('n:cStat')]]);
        $y = $this->linha($y, [[.2, 'Número da DPS', $this->v($d.'n:nDPS')], [.2, 'Série da DPS', $this->v($d.'n:serie')], [.3, 'Data e Hora da Emissão da DPS', self::data($this->v($d.'n:dhEmi'))], [.3, 'Município de Emissão', $this->v('n:xLocEmi')]]);

        $e = 'n:emit/';
        $end = 'n:emit/n:enderNac/';
        $y = $this->secao($y + 1, 'Emitente da NFS-e / Prestador do Serviço');
        $y = $this->linha($y, [[.3, 'CNPJ / CPF', self::doc($this->v($e.'n:CNPJ', $this->v($e.'n:CPF')))], [.7, 'Nome / Razão Social', $this->v($e.'n:xNome')]]);
        $y = $this->linha($y, [[.3, 'Telefone', $this->v($e.'n:fone')], [.7, 'E-mail', $this->v($e.'n:email')]]);
        $compl = $this->v($end.'n:xCpl', '');
        $y = $this->linha($y, [[.7, 'Endereço', $this->v($end.'n:xLgr').', '.$this->v($end.'n:nro').($compl !== '' ? ' - '.$compl : '').' - '.$this->v($end.'n:xBairro')], [.2, 'Município / UF', $this->v('n:xLocEmi').' / '.$this->v($end.'n:UF')], [.1, 'CEP', $this->v($end.'n:CEP')]]);
        $sn = ['1' => 'Não Optante', '2' => 'Optante - MEI', '3' => 'Optante - ME/EPP'][$this->v($d.'n:prest/n:regTrib/n:opSimpNac')] ?? '-';
        $y = $this->linha($y, [[.5, 'Simples Nacional na Data de Competência', $sn], [.5, 'Regime de Apuração (Simples Nacional)', $this->v($d.'n:prest/n:regTrib/n:regApTribSN') === '1' ? 'Tributos federais e municipal pelo Simples Nacional' : '-']]);

        $t = $d.'n:toma/';
        $y = $this->secao($y + 1, 'Tomador do Serviço');
        $y = $this->linha($y, [[.3, 'CNPJ / CPF', self::doc($this->v($t.'n:CNPJ', $this->v($t.'n:CPF')))], [.7, 'Nome / Razão Social', $this->v($t.'n:xNome', 'Tomador não identificado')]]);
        $y = $this->linha($y, [[1.0, 'E-mail', $this->v($t.'n:email')]]);

        $s = $d.'n:serv/';
        $y = $this->secao($y + 1, 'Serviço Prestado');
        $y = $this->linha($y, [[.2, 'Código de Tributação Nacional', $this->v($s.'n:cServ/n:cTribNac')], [.2, 'Código NBS', $this->v($s.'n:cServ/n:cNBS')], [.3, 'Local da Prestação', $this->v('n:xLocPrestacao')], [.3, 'Município de Incidência', $this->v('n:xLocIncid')]]);
        $p->SetXY(self::ESQ + 1, $y);
        $p->SetFont('Arial', 'B', 6.5);
        $p->Cell(100, 3.2, self::iso('Descrição do Serviço'));
        $p->SetXY(self::ESQ + 1, $y + 3.2);
        $p->SetFont('Arial', '', 8);
        $p->MultiCell(self::LARG - 2, 3.8, self::iso(mb_substr($this->v($s.'n:cServ/n:xDescServ'), 0, 900)));
        $y += 26;
        $p->SetXY(self::ESQ + 1, $y - 4);
        $p->SetFont('Arial', '', 6);
        $p->Cell(self::LARG - 2, 3, self::iso(mb_substr($this->v('n:xTribNac'), 0, 170)));

        $v = $d.'n:valores/';
        $trib = $v.'n:trib/n:tribMun/';
        $y = $this->secao($y, 'Tributação Municipal (ISSQN)');
        $y = $this->linha($y, [[.3, 'Tributação do ISSQN', $this->v($trib.'n:tribISSQN') === '1' ? 'Operação Tributável' : $this->v($trib.'n:tribISSQN')], [.3, 'Retenção do ISSQN', $this->v($trib.'n:tpRetISSQN') === '1' ? 'Não Retido' : 'Retido'], [.2, 'Base de Cálculo', self::moeda($this->v('n:valores/n:vBC'))], [.2, 'Valor do ISSQN', self::moeda($this->v('n:valores/n:vISSQN'))]]);

        $tot = $v.'n:trib/n:totTrib/n:vTotTrib/';
        $y = $this->secao($y + 1, 'Valor Total da NFS-e');
        $y = $this->linha($y, [[.5, 'Valor do Serviço', self::moeda($this->v($v.'n:vServPrest/n:vServ'))], [.5, 'Valor Líquido da NFS-e', self::moeda($this->v('n:valores/n:vLiq'))]]);
        $y = $this->secao($y + 1, 'Totais Aproximados dos Tributos');
        $y = $this->linha($y, [[.33, 'Federais', self::moeda($this->v($tot.'n:vTotTribFed'))], [.33, 'Estaduais', self::moeda($this->v($tot.'n:vTotTribEst'))], [.34, 'Municipais', self::moeda($this->v($tot.'n:vTotTribMun'))]]);

        $p->SetXY(self::ESQ, 276);
        $p->SetFont('Arial', '', 7);
        $p->MultiCell(self::LARG, 3.4, self::iso('A autenticidade desta NFS-e pode ser verificada pela leitura do código QR ou pela consulta da chave de acesso no portal nacional da NFS-e (www.nfse.gov.br).'));

        return $p->getPdf();
    }
}
