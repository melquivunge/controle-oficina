<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Services\Auditoria;
use App\Support\DadosFiscais;
use Illuminate\Http\Request;

/** Configurações → Dados fiscais (proprietário). Os valores vêm pré-preenchidos com o que o sistema antigo usava. */
class DadosFiscaisController extends Controller
{
    public function edit()
    {
        return view('configuracoes.fiscal', ['dados' => DadosFiscais::atuais(), 'salvo' => (bool) Empresa::atual()->fiscal]);
    }

    public function update(Request $request)
    {
        $digitos = fn ($n, $max = null) => ['nullable', 'regex:/^\\d{'.$n.($max ? ','.$max : '').'}$/'];
        $request->merge(collect(DadosFiscais::PADRAO)->except(['regime', 'nfe_natureza', 'nfse_aliquota_iss'])
            ->mapWithKeys(fn ($_, $k) => [$k => preg_replace('/\\D/', '', (string) $request->input($k))])->all()
            + ['nfse_aliquota_iss' => str_replace(',', '.', (string) $request->input('nfse_aliquota_iss'))]);
        $dados = $request->validate([
            'regime' => ['required', 'in:simples,normal'],
            'inscricao_municipal' => ['nullable', 'regex:/^\\d{1,20}$/'], 'inscricao_estadual' => ['nullable', 'regex:/^\\d{1,20}$/'],
            'municipio_ibge' => ['required', 'regex:/^\\d{7}$/'],
            'nfse_c_trib_nac' => ['required', 'regex:/^\\d{6}$/'], 'nfse_c_trib_mun' => $digitos(1, 6), 'nfse_c_nbs' => $digitos(9),
            'nfse_aliquota_iss' => ['required', 'numeric', 'min:0', 'max:5'],
            'nfse_serie' => ['required', 'regex:/^\\d{1,5}$/'], 'nfse_proximo_numero' => $digitos(1, 15), 'nfe_proximo_numero' => $digitos(1, 9),
            'nfe_serie' => ['required', 'regex:/^\\d{1,3}$/'], 'nfe_cfop_dentro' => ['required', 'regex:/^5\\d{3}$/'], 'nfe_cfop_fora' => ['required', 'regex:/^6\\d{3}$/'],
            'nfe_csosn' => ['required', 'regex:/^\\d{3}$/'], 'nfe_ncm_padrao' => ['required', 'regex:/^\\d{8}$/'], 'nfe_natureza' => ['required', 'string', 'max:60'],
        ], ['regex' => 'Formato inválido.'], DadosFiscais::ROTULOS);
        $dados['nfse_aliquota_iss'] = number_format((float) $dados['nfse_aliquota_iss'], 2, '.', '');
        $empresa = Empresa::atual();
        abort_unless($empresa->exists, 422, 'Cadastre a identidade da empresa primeiro.');
        $antes = $empresa->fiscal;
        $empresa->fiscal = array_map(fn ($v) => (string) ($v ?? ''), $dados);
        $empresa->save();
        Auditoria::registrar('dados_fiscais_alterados', $empresa, $antes, $empresa->fiscal, ator: $request->user());

        return back()->with('sucesso', 'Dados fiscais salvos.');
    }
}
