<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\Unidade;
use App\Rules\Cnpj;
use App\Services\Auditoria;
use App\Models\Tema;
use App\Support\Aparencia;
use App\Support\Endereco;
use App\Support\Normaliza;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Identidade da empresa (OS impressa): somente proprietário (can:configurar) com reautenticação recente (password.confirm).
 * Logo em disco privado; servida por rota própria (pública: aparece na tela de entrada) com nosniff.
 */
class EmpresaController extends Controller
{
    private const CAMPOS = ['razao_social', 'nome_fantasia', 'cnpj', 'telefone', 'email', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'cor_principal', 'nome_sistema', 'descricao', 'tema_padrao'];

    public function edit()
    {
        return view('configuracoes.empresa', [
            'empresa' => Empresa::atual(),
            'unidades' => Unidade::orderBy('nome')->get(),
            'temas' => Tema::orderBy('nome')->get(),
            'opcoesTema' => Aparencia::opcoes(),
        ]);
    }

    public function update(Request $request)
    {
        $request->merge(Endereco::normalizar($request->all()) + [
            'cnpj' => Cnpj::normalizar($request->input('cnpj')) ?: null,
            'telefone' => Normaliza::telefone($request->input('telefone')) ?: null,
            'cor_principal' => mb_strtolower(trim((string) $request->input('cor_principal'))) ?: null,
            'nome_sistema' => trim((string) $request->input('nome_sistema')) ?: null,
            'descricao' => trim((string) $request->input('descricao')) ?: null,
            'tema_padrao' => $request->input('tema_padrao') ?: null,
        ]);
        $dados = $request->validate([
            'razao_social' => ['required', 'string', 'min:2', 'max:150'],
            'nome_fantasia' => ['required', 'string', 'min:2', 'max:120'],
            'cnpj' => ['required', new Cnpj],
            'telefone' => ['required', 'regex:/^\d{10,11}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'cor_principal' => ['nullable', 'regex:/^#[0-9a-f]{6}$/'],
            'nome_sistema' => ['nullable', 'string', 'min:2', 'max:60'],
            'descricao' => ['nullable', 'string', 'max:300'],
            'tema_padrao' => ['nullable', Rule::in(array_keys(Aparencia::opcoes()))],
            // Logo: somente bitmap (jpg/png/webp); SVG recusado (pode conter script). Conteúdo real conferido (dimensions lê o arquivo).
            'logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:1024', 'dimensions:max_width=4000,max_height=4000'],
            'remover_logo' => ['nullable', 'boolean'],
        ] + Endereco::regras(), [
            'telefone.regex' => 'Informe o telefone com DDD (10 ou 11 dígitos).',
            'cor_principal.regex' => 'Use uma cor no formato #RRGGBB.',
            'logo.max' => 'A logo deve ter até 1 MB.',
            'logo.mimes' => 'A logo deve ser JPG, PNG ou WebP (SVG não é aceito).',
            'logo.mimetypes' => 'A logo deve ser JPG, PNG ou WebP (SVG não é aceito).',
        ] + Endereco::MENSAGENS, ['razao_social' => 'razão social', 'nome_fantasia' => 'nome fantasia', 'cnpj' => 'CNPJ', 'email' => 'e-mail', 'cor_principal' => 'cor principal', 'nome_sistema' => 'nome do sistema', 'descricao' => 'descrição', 'tema_padrao' => 'tema padrão'] + Endereco::atributos());

        $empresa = Empresa::atual();
        $anteriorLogo = $empresa->logo_caminho;
        $novoLogo = $request->hasFile('logo') ? $request->file('logo')->store('empresa', 'local') : null;
        try {
            DB::transaction(function () use ($empresa, $dados, $request, $novoLogo) {
                $antes = $empresa->exists ? $empresa->only(self::CAMPOS) + ['logo' => (bool) $empresa->logo_caminho] : null;
                $empresa->fill(collect($dados)->only(self::CAMPOS)->all());
                if ($novoLogo) {
                    $empresa->logo_caminho = $novoLogo;
                } elseif ($request->boolean('remover_logo')) {
                    $empresa->logo_caminho = null;
                }
                $empresa->save();
                Auditoria::registrar('empresa_alterada', $empresa, $antes, $empresa->only(self::CAMPOS) + ['logo' => (bool) $empresa->logo_caminho], unidadeId: null);
            });
        } catch (\Throwable $e) {
            if ($novoLogo) {
                Storage::disk('local')->delete($novoLogo);
            }
            throw $e;
        }
        if ($anteriorLogo && $anteriorLogo !== $empresa->logo_caminho) {
            Storage::disk('local')->delete($anteriorLogo);
        }

        return redirect()->route('empresa.edit')->with('sucesso', 'Identidade da empresa atualizada.');
    }

    /** CNPJ, telefone e endereço próprios da unidade (opcionais; vazios usam os da empresa no documento). */
    public function unidade(Request $request, Unidade $unidade)
    {
        $request->merge(Endereco::normalizar($request->all()) + [
            'cnpj' => Cnpj::normalizar($request->input('cnpj')) ?: null,
            'telefone' => Normaliza::telefone($request->input('telefone')) ?: null,
        ]);
        $dados = $request->validateWithBag('unidade'.$unidade->id, [
            'cnpj' => ['nullable', new Cnpj],
            'telefone' => ['nullable', 'regex:/^\d{10,11}$/'],
        ] + Endereco::regras(), ['telefone.regex' => 'Informe o telefone com DDD (10 ou 11 dígitos).'] + Endereco::MENSAGENS, ['cnpj' => 'CNPJ'] + Endereco::atributos());
        $campos = array_merge(['cnpj', 'telefone'], Endereco::CAMPOS);
        DB::transaction(function () use ($unidade, $dados, $campos) {
            $antes = $unidade->only($campos);
            $unidade->forceFill(collect($dados)->only($campos)->all())->save();
            Auditoria::registrar('unidade_identidade_alterada', $unidade, $antes, $unidade->only($campos), unidadeId: $unidade->id);
        });

        return redirect()->route('empresa.edit')->with('sucesso', "Dados da unidade {$unidade->nome} atualizados.");
    }

    /** Logo pública (tela de entrada, portal e OS impressa); arquivo privado no disco, servido só por esta rota. */
    public function logo()
    {
        $caminho = Empresa::atual()->logo_caminho;
        abort_unless($caminho && Storage::disk('local')->exists($caminho), 404);

        return Storage::disk('local')->response($caminho, 'logo', [
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600', 'Content-Security-Policy' => "default-src 'none'",
            'Content-Disposition' => 'inline',
        ]);
    }
}
