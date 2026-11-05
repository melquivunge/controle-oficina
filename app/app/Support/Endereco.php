<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Endereço brasileiro opcional (empresa, unidade e cliente): normalização, validação e exibição. */
class Endereco
{
    public const CAMPOS = ['cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf'];

    public const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    /** @return array<string, string|null> campos aparados; CEP só dígitos; UF maiúscula; vazio vira null */
    public static function normalizar(array $entrada): array
    {
        $saida = [];
        foreach (self::CAMPOS as $c) {
            $v = trim((string) ($entrada[$c] ?? ''));
            $v = match ($c) {
                'cep' => preg_replace('/\D/', '', $v),
                'uf' => mb_strtoupper($v),
                default => preg_replace('/\s+/', ' ', $v),
            };
            $saida[$c] = $v === '' ? null : $v;
        }

        return $saida;
    }

    public static function regras(string $prefixo = ''): array
    {
        return [
            $prefixo.'cep' => ['nullable', 'regex:/^\d{8}$/'],
            $prefixo.'logradouro' => ['nullable', 'string', 'max:150'],
            $prefixo.'numero' => ['nullable', 'string', 'max:20'],
            $prefixo.'complemento' => ['nullable', 'string', 'max:80'],
            $prefixo.'bairro' => ['nullable', 'string', 'max:80'],
            $prefixo.'cidade' => ['nullable', 'string', 'max:80'],
            $prefixo.'uf' => ['nullable', Rule::in(self::UFS)],
        ];
    }

    public const MENSAGENS = ['cep.regex' => 'Informe o CEP com 8 dígitos.', 'uf.in' => 'Escolha uma UF válida.'];

    public static function atributos(): array
    {
        return ['cep' => 'CEP', 'logradouro' => 'logradouro', 'numero' => 'número', 'complemento' => 'complemento', 'bairro' => 'bairro', 'cidade' => 'cidade', 'uf' => 'UF'];
    }

    public static function preenchido(Model $m): bool
    {
        return collect(self::CAMPOS)->contains(fn ($c) => filled($m->getAttribute($c)));
    }

    /** "Rua X, 10, apto 2 · Bairro · Cidade/UF · CEP 00000-000" */
    public static function texto(Model $m): string
    {
        $linha = collect([$m->logradouro, $m->numero, $m->complemento])->filter()->join(', ');
        $cidade = collect([$m->cidade, $m->uf])->filter()->join('/');
        $cep = $m->cep && strlen($m->cep) === 8 ? 'CEP '.substr($m->cep, 0, 5).'-'.substr($m->cep, 5) : $m->cep;

        return collect([$linha, $m->bairro, $cidade, $cep])->filter()->join(' · ');
    }
}
