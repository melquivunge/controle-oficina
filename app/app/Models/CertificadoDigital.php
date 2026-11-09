<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use NFePHP\Common\Certificate;

/**
 * Certificado A1 da empresa (09/11/2026). O .pfx e a senha ficam criptografados com a APP_KEY;
 * nunca são exibidos, registrados em log ou gravados em disco. Vale sempre o último enviado.
 */
class CertificadoDigital extends Model
{
    protected $table = 'certificados_digitais';

    protected $fillable = ['pfx', 'senha', 'documento', 'titular', 'valido_de', 'valido_ate', 'enviado_por'];

    protected $hidden = ['pfx', 'senha'];

    protected function casts(): array
    {
        return ['pfx' => 'encrypted', 'senha' => 'encrypted', 'valido_de' => 'datetime', 'valido_ate' => 'datetime'];
    }

    public static function atual(): ?self
    {
        return static::latest('id')->first();
    }

    /** Abre o .pfx; senha errada ou arquivo inválido viram erro de validação sem detalhes técnicos. */
    public static function abrir(string $conteudo, string $senha): Certificate
    {
        try {
            return Certificate::readPfx($conteudo, $senha);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['certificado' => 'Não foi possível abrir o certificado. Confira o arquivo (.pfx ou .p12) e a senha.']);
        }
    }

    public function certificado(): Certificate
    {
        try {
            return Certificate::readPfx(base64_decode($this->pfx), $this->senha);
        } catch (\Throwable) { // sem "previous": o trace carregaria a senha como argumento
            throw new \RuntimeException('Não foi possível abrir o certificado guardado. Envie-o novamente.');
        }
    }

    public function vencido(): bool
    {
        return $this->valido_ate->isPast();
    }
}
