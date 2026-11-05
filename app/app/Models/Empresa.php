<?php

namespace App\Models;

use App\Rules\Cnpj;
use App\Support\Endereco;
use App\Support\Normaliza;
use Illuminate\Database\Eloquent\Model;

/** Identidade da empresa (registro único). Alterada só pelo proprietário em /configuracoes/empresa. */
class Empresa extends Model
{
    protected $table = 'empresas';

    protected $fillable = ['razao_social', 'nome_fantasia', 'cnpj', 'telefone', 'email', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'cor_principal', 'nome_sistema', 'descricao', 'tema_padrao'];

    public static function atual(): self
    {
        return self::query()->orderBy('id')->first() ?? new self;
    }

    public const NOME_PADRAO = 'Controle de Oficina';

    /** Nome exibido no menu, na aba do navegador e na tela de entrada. */
    public function nomeSistema(): string
    {
        return $this->nome_sistema ?: self::NOME_PADRAO;
    }

    public function cnpjFormatado(): string
    {
        return Cnpj::exibicao($this->cnpj);
    }

    public function telefoneFormatado(): string
    {
        return Normaliza::telefoneExibicao($this->telefone);
    }

    public function enderecoTexto(): string
    {
        return Endereco::texto($this);
    }
}
