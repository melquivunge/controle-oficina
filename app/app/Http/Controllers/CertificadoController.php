<?php

namespace App\Http\Controllers;

use App\Models\CertificadoDigital;
use App\Models\Empresa;
use App\Services\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Configurações → Certificado digital (quem configura, com senha reconfirmada). */
class CertificadoController extends Controller
{
    public function edit()
    {
        return view('configuracoes.certificado', ['cert' => CertificadoDigital::atual(), 'modo' => config('oficina.fiscal.modo')]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'certificado' => ['required', 'file', 'max:64'], // KB; um A1 tem poucos KB
            'senha' => ['required', 'string', 'max:200'],
        ], [], ['certificado' => 'arquivo do certificado', 'senha' => 'senha do certificado']);
        $ext = strtolower($request->file('certificado')->getClientOriginalExtension());
        if (! in_array($ext, ['pfx', 'p12'], true)) {
            throw ValidationException::withMessages(['certificado' => 'Envie o arquivo .pfx ou .p12 do certificado A1.']);
        }
        $conteudo = $request->file('certificado')->get();
        $cert = CertificadoDigital::abrir($conteudo, $request->input('senha'));
        if ($cert->isExpired()) {
            throw ValidationException::withMessages(['certificado' => 'Este certificado está vencido desde '.$cert->getValidTo()->format('d/m/Y').'.']);
        }
        $doc = (string) ($cert->getCnpj() ?: $cert->getCpf());
        $cnpjEmpresa = preg_replace('/\D/', '', (string) Empresa::atual()->cnpj);
        if ($cnpjEmpresa === '' || $doc !== $cnpjEmpresa) {
            throw ValidationException::withMessages(['certificado' => $cnpjEmpresa === ''
                ? 'Cadastre o CNPJ da empresa (Configurações → Empresa) antes de enviar o certificado.'
                : 'O certificado é de outro CNPJ. Envie o certificado da empresa cadastrada.']);
        }
        $novo = CertificadoDigital::create(['pfx' => base64_encode($conteudo), 'senha' => $request->input('senha'), 'documento' => $doc,
            'titular' => mb_substr(\Illuminate\Support\Str::before((string) $cert->getCompanyName(), ':'), 0, 200), 'valido_de' => $cert->getValidFrom(), 'valido_ate' => $cert->getValidTo(),
            'enviado_por' => $request->user()->id]);
        CertificadoDigital::where('id', '<', $novo->id)->delete(); // só o atual fica guardado
        Auditoria::registrar('certificado_enviado', $novo, depois: ['documento' => $doc, 'valido_ate' => $novo->valido_ate->toDateString()], ator: $request->user());

        return back()->with('sucesso', 'Certificado guardado. Válido até '.$novo->valido_ate->format('d/m/Y').'.');
    }

    public function destroy(Request $request)
    {
        $cert = CertificadoDigital::atual();
        abort_unless($cert, 404);
        Auditoria::registrar('certificado_removido', $cert, antes: ['documento' => $cert->documento], ator: $request->user());
        CertificadoDigital::query()->delete();

        return back()->with('sucesso', 'Certificado removido. A emissão real fica parada até um novo envio.');
    }
}
