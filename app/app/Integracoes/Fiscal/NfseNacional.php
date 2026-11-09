<?php

namespace App\Integracoes\Fiscal;

use App\Models\CertificadoDigital;
use App\Models\Empresa;
use DOMDocument;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use NFePHP\Common\Certificate;
use NFePHP\Common\Signer;
use RuntimeException;

/**
 * NFS-e Nacional pela API da Sefin Nacional (09/11/2026, etapa 2). Leiaute DPS 1.01 espelhando o que o sistema
 * antigo emitia com sucesso no DF (Simples Nacional, item 14.01, ISS não retido). Assinatura RSA-SHA256 e mTLS
 * com o certificado A1 (arquivos temporários em /dev/shm, na memória, apagados ao terminar).
 * Homologação = "produção restrita" da Sefin: notas sem validade fiscal.
 */
class NfseNacional
{
    public const NS = 'http://www.sped.fazenda.gov.br/nfse';

    /** @var ?array{0:string,1:string} certificado e chave em arquivos temporários */
    private ?array $pem = null;

    public function __construct(private int $ambiente) {}

    public static function idDps(string $cLocEmi, string $cnpj, string $serie, int $numero): string
    {
        return 'DPS'.$cLocEmi.'2'.$cnpj.str_pad($serie, 5, '0', STR_PAD_LEFT).str_pad((string) $numero, 15, '0', STR_PAD_LEFT);
    }

    private function base(): string
    {
        return $this->ambiente === 1 ? 'https://sefin.nfse.gov.br/SefinNacional' : 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional';
    }

    private function adn(): string
    {
        return $this->ambiente === 1 ? 'https://adn.nfse.gov.br' : 'https://adn.producaorestrita.nfse.gov.br';
    }

    private function id(array $d): string
    {
        return self::idDps($d['fiscal']['municipio_ibge'], preg_replace('/\D/', '', (string) Empresa::atual()->cnpj), $d['serie'], $d['numero_dps']);
    }

    /** Já existe NFS-e para esta DPS? (evita emissão dupla quando a resposta anterior se perdeu) */
    public function consultar(array $dados): ?array
    {
        $cert = $this->cert();
        $r = $this->http($cert)->get($this->base().'/dps/'.$this->id($dados));
        if ($r->status() === 404) {
            return null;
        }
        $chave = $r->json('chaveAcesso');
        if (! $r->successful() || ! $chave) {
            throw new RuntimeException('Consulta da DPS falhou (HTTP '.$r->status().'): '.$this->erros($r));
        }
        $n = $this->http($cert)->get($this->base().'/nfse/'.$chave);
        if (! $n->successful() || ! $n->json('nfseXmlGZipB64')) {
            throw new RuntimeException('Consulta da NFS-e falhou (HTTP '.$n->status().'): '.$this->erros($n));
        }

        return $this->autorizado($cert, $chave, gzdecode(base64_decode($n->json('nfseXmlGZipB64'))));
    }

    public function emitir(array $dados): array
    {
        $cert = $this->cert();
        $dps = Signer::sign($cert, $this->dps($dados), 'infDPS', 'Id', OPENSSL_ALGO_SHA256, [true, false, null, null], 'DPS');
        $r = $this->http($cert)->post($this->base().'/nfse', ['dpsXmlGZipB64' => self::compactar($dps)]);
        if ($r->successful() && $r->json('chaveAcesso')) {
            return $this->autorizado($cert, $r->json('chaveAcesso'), gzdecode(base64_decode((string) $r->json('nfseXmlGZipB64'))));
        }
        if ($r->status() >= 500 || $r->status() === 429) {
            throw new RuntimeException('Sefin Nacional indisponível (HTTP '.$r->status().'). Nova tentativa automática.'); // a fila tenta de novo
        }
        if (str_contains($this->erros($r), 'E0014') && ($ja = $this->consultar($dados))) { // DPS repetida: a nota já existe
            return $ja;
        }

        return ['status' => 'rejeitado', 'numero' => null, 'protocolo' => null, 'mensagem' => mb_substr($this->erros($r), 0, 500), 'documento' => null];
    }

    public function cancelar(string $chave, string $motivo): void
    {
        $cert = $this->cert();
        $dom = new DOMDocument('1.0', 'UTF-8');
        $ped = $dom->appendChild($dom->createElementNS(self::NS, 'pedRegEvento'));
        $ped->setAttribute('versao', '1.01');
        $inf = $ped->appendChild($dom->createElement('infPedReg'));
        $inf->setAttribute('Id', 'PRE'.$chave.'101101');
        foreach (['tpAmb' => (string) $this->ambiente, 'verAplic' => 'ControleOficina1', 'dhEvento' => now('America/Sao_Paulo')->subMinute()->format('Y-m-d\TH:i:sP'),
            'CNPJAutor' => (string) $cert->getCnpj(), 'chNFSe' => $chave] as $k => $v) {
            $inf->appendChild($dom->createElement($k))->appendChild($dom->createTextNode($v));
        }
        $e = $inf->appendChild($dom->createElement('e101101'));
        foreach (['xDesc' => 'Cancelamento de NFS-e', 'cMotivo' => '1', 'xMotivo' => self::texto($motivo, 255)] as $k => $v) {
            $e->appendChild($dom->createElement($k))->appendChild($dom->createTextNode($v));
        }
        $xml = Signer::sign($cert, $dom->saveXML(), 'infPedReg', 'Id', OPENSSL_ALGO_SHA256, [true, false, null, null], 'pedRegEvento');
        $r = $this->http($cert)->post($this->base().'/nfse/'.$chave.'/eventos', ['pedidoRegistroEventoXmlGZipB64' => self::compactar($xml)]);
        if (! $r->successful()) {
            throw new RuntimeException('A Fazenda recusou o cancelamento: '.$this->erros($r));
        }
    }

    /** O Signer devolve o XML sem a declaração; sem ela a Sefin rejeita com E1229. Fica fora do trecho assinado. */
    private static function compactar(string $xml): string
    {
        return base64_encode(gzencode('<?xml version="1.0" encoding="UTF-8"?>'.preg_replace('/^<\?xml[^>]*\?>\s*/', '', $xml)));
    }

    /** Monta a DPS (sem assinatura). */
    public function dps(array $d): string
    {
        $f = $d['fiscal'];
        $empresa = Empresa::atual();
        $cnpj = preg_replace('/\D/', '', (string) $empresa->cnpj);
        $loc = $f['municipio_ibge'];
        $simples = $f['regime'] === 'simples';
        $valor = $d['valor_centavos'] / 100;

        $x = new \XMLWriter;
        $x->openMemory();
        $x->startDocument('1.0', 'UTF-8');
        $x->startElementNs(null, 'DPS', self::NS);
        $x->writeAttribute('versao', '1.01');
        $x->startElement('infDPS');
        $x->writeAttribute('Id', self::idDps($loc, $cnpj, $d['serie'], $d['numero_dps']));
        $el = fn (string $n, string $v) => $x->writeElement($n, $v);
        $el('tpAmb', (string) $this->ambiente);
        $el('dhEmi', now('America/Sao_Paulo')->subMinute()->format('Y-m-d\TH:i:sP'));
        $el('verAplic', 'ControleOficina1');
        $el('serie', (string) (int) $d['serie']);
        $el('nDPS', (string) $d['numero_dps']);
        $el('dCompet', now('America/Sao_Paulo')->format('Y-m-d'));
        $el('tpEmit', '1');
        $el('cLocEmi', $loc);
        $x->startElement('prest');
        $el('CNPJ', $cnpj);
        if ($fone = preg_replace('/\D/', '', (string) $empresa->telefone)) {
            $el('fone', substr($fone, -11));
        }
        if ($empresa->email) {
            $el('email', $empresa->email);
        }
        $x->startElement('regTrib');
        $el('opSimpNac', $simples ? '3' : '1');
        if ($simples) {
            $el('regApTribSN', '1');
        }
        $el('regEspTrib', '0');
        $x->endElement();
        $x->endElement(); // prest
        $doc = preg_replace('/\D/', '', (string) ($d['cliente_documento'] ?? ''));
        if (in_array(strlen($doc), [11, 14], true)) { // tomador só com CPF/CNPJ; sem documento a DPS segue sem tomador
            $x->startElement('toma');
            $el(strlen($doc) === 14 ? 'CNPJ' : 'CPF', $doc);
            $el('xNome', self::texto($d['cliente'], 150));
            if (! empty($d['cliente_email'])) {
                $el('email', $d['cliente_email']);
            }
            $x->endElement();
        }
        $x->startElement('serv');
        $x->startElement('locPrest');
        $el('cLocPrestacao', $loc);
        $x->endElement();
        $x->startElement('cServ');
        $el('cTribNac', $f['nfse_c_trib_nac']);
        $el('xDescServ', self::texto(self::descricao($d), 2000));
        if ($f['nfse_c_nbs'] !== '') {
            $el('cNBS', $f['nfse_c_nbs']);
        }
        $x->endElement();
        $x->endElement(); // serv
        $x->startElement('valores');
        $x->startElement('vServPrest');
        $el('vServ', number_format($valor, 2, '.', ''));
        $x->endElement();
        $x->startElement('trib');
        $x->startElement('tribMun');
        $el('tribISSQN', '1');
        $el('tpRetISSQN', '1');
        $x->endElement();
        $x->startElement('totTrib');
        $x->startElement('vTotTrib');
        $el('vTotTribFed', '0');
        $el('vTotTribEst', '0');
        $el('vTotTribMun', number_format(round($valor * (float) $f['nfse_aliquota_iss'] / 100, 2), 2, '.', ''));
        $x->endElement();
        $x->endElement();
        $x->endElement(); // trib
        $x->endElement(); // valores
        $x->endElement(); // infDPS
        $x->endElement(); // DPS

        return $x->outputMemory();
    }

    private static function descricao(array $d): string
    {
        $linhas = array_map(fn ($i) => rtrim(rtrim(number_format((float) $i['quantidade'], 2, ',', ''), '0'), ',').' x '.$i['descricao'], $d['itens'] ?? []);

        return 'OS '.$d['ordem'].' - '.implode('; ', $linhas ?: ['Serviços de manutenção de veículo']);
    }

    /** Texto aceito pela Sefin: sem quebras nem caracteres de controle, tamanho limitado. */
    public static function texto(string $s, int $max): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s)), 0, $max);
    }

    private function autorizado(Certificate $cert, string $chave, string $xml): array
    {
        if (! preg_match('/^\d{50}$/', $chave)) { // vai para URL e nome de arquivo
            throw new RuntimeException('Sefin Nacional devolveu chave de acesso em formato inválido.');
        }
        preg_match('/<nNFSe>(\d+)<\/nNFSe>/', $xml, $m);
        $pdf = null;
        try { // DANFSe oficial do ADN; se falhar a nota continua autorizada e o XML fica guardado
            $p = $this->http($cert)->timeout(30)->get($this->adn().'/danfse/'.$chave);
            $pdf = $p->successful() && str_starts_with($p->body(), '%PDF') ? $p->body() : null;
        } catch (\Throwable) {
        }

        return ['status' => 'autorizado', 'numero' => $m[1] ?? null, 'protocolo' => $chave, 'chave_acesso' => $chave, 'mensagem' => null,
            'xml' => $xml, 'documento' => $pdf, 'extensao' => 'pdf'];
    }

    private function erros(Response $r): string
    {
        $lista = $r->json('erros') ?? $r->json('erro') ?? [];
        $lista = isset($lista['Codigo']) || isset($lista['codigo']) ? [$lista] : (array) $lista;
        $txt = collect($lista)->map(fn ($e) => trim(($e['Codigo'] ?? $e['codigo'] ?? '').' '.($e['Descricao'] ?? $e['descricao'] ?? '').' '.($e['Complemento'] ?? $e['complemento'] ?? '')))->filter()->implode(' | ');

        return $txt !== '' ? $txt : 'HTTP '.$r->status().' '.mb_substr(strip_tags($r->body()), 0, 200);
    }

    private function cert(): Certificate
    {
        $c = CertificadoDigital::atual();
        if (! $c) {
            throw new RuntimeException('Certificado digital não enviado (Configurações → Certificado digital).');
        }
        if ($c->vencido()) {
            throw new RuntimeException('Certificado digital vencido em '.$c->valido_ate->format('d/m/Y').'.');
        }

        return $c->certificado();
    }

    private function http(Certificate $cert): PendingRequest
    {
        // Guzzle 8 não aceita CURLOPT_*_BLOB: só caminho de arquivo. Em /dev/shm (memória), 0600, apagados no __destruct.
        if (! $this->pem) {
            $dir = is_writable('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
            $this->pem = [tempnam($dir, 'oficina-'), tempnam($dir, 'oficina-')];
            file_put_contents($this->pem[0], (string) $cert->publicKey);
            file_put_contents($this->pem[1], (string) $cert->privateKey);
        }

        return Http::timeout(60)->connectTimeout(15)->acceptJson()->withOptions(['cert' => $this->pem[0], 'ssl_key' => $this->pem[1]]);
    }

    public function __destruct()
    {
        array_map(fn ($f) => @unlink($f), $this->pem ?? []);
    }
}
