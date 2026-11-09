<?php

namespace App\Integracoes\Fiscal;

use NFePHP\Common\Certificate;
use NFePHP\Common\Exception\SoapException;
use NFePHP\Common\Soap\SoapCurl;

/**
 * Envio SOAP da NF-e com o servidor verificado (09/11/2026). A SoapCurl da sped-common fixa VERIFYPEER=0: uma resposta
 * forjada poderia virar "autorizada". Aqui valem as CAs do sistema mais a raiz ICP-Brasil v10 (docker/certs), usada pela
 * SVRS, e a chave vai em memória, sem arquivo temporário. Sem proxy e sem arquivos de depuração.
 */
class SoapCurlVerificado extends SoapCurl
{
    public const CA = '/etc/ssl/oficina-ca.pem';

    private ?Certificate $cert = null;

    public function loadCertificate(?Certificate $certificate = null)
    {
        $this->cert = $certificate;

        return parent::loadCertificate($certificate);
    }

    public function send($url, $operation = '', $action = '', $soapver = SOAP_1_2, $parameters = [], $namespaces = [], $request = '', $soapheader = null)
    {
        $envelope = $this->makeEnvelopeSoap($request, $namespaces, $soapver, $soapheader);
        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CAINFO => self::CA,
            CURLOPT_SSLCERT_BLOB => (string) $this->cert->publicKey, CURLOPT_SSLCERTTYPE => 'PEM',
            CURLOPT_SSLKEY_BLOB => (string) $this->cert->privateKey, CURLOPT_SSLKEYTYPE => 'PEM',
            CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 60, CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $envelope,
            CURLOPT_HTTPHEADER => ['Content-Type: application/soap+xml;charset=utf-8;'.($action ? "action=$action" : ''), 'Content-length: '.strlen($envelope)],
        ]);
        $corpo = curl_exec($c);
        $erro = curl_error($c);
        $http = curl_getinfo($c, CURLINFO_HTTP_CODE);
        if ($erro !== '') {
            throw SoapException::soapFault($erro.' ['.$url.']', curl_errno($c) ?: 7);
        }
        if ($http !== 200 || trim((string) $corpo) === '') {
            throw SoapException::soapFault('SEFAZ respondeu HTTP '.$http, 99);
        }

        return $this->responseBody = trim($corpo);
    }
}
