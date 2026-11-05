<?php

namespace App\Models;

use App\Support\Normaliza;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    use HasFactory;

    protected $fillable = ['nome', 'telefone', 'email', 'observacoes', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf'];

    public function veiculos(): HasMany
    {
        return $this->hasMany(Veiculo::class);
    }

    public function ordens(): HasMany
    {
        return $this->hasMany(Ordem::class);
    }

    public function mescladoEm(): BelongsTo
    {
        return $this->belongsTo(self::class, 'mesclado_em_id');
    }

    public function scopeAtivos(Builder $q): Builder
    {
        return $q->whereNull('mesclado_em_id');
    }

    /** Busca por nome, telefone ou placa. Sem permissão de contato, o telefone não entra na busca (evita sondar números). */
    public function scopeBuscar(Builder $q, string $termo, bool $porTelefone = true): Builder
    {
        $termo = trim($termo);
        $digitos = preg_replace('/\D/', '', $termo);
        $placa = Normaliza::placa($termo);

        return $q->where(function (Builder $q) use ($termo, $digitos, $placa, $porTelefone) {
            $q->whereRaw('lower(nome) like ?', [mb_strtolower(addcslashes($termo, '%_\\')).'%'])
                ->orWhereRaw('lower(nome) like ?', ['% '.mb_strtolower(addcslashes($termo, '%_\\')).'%']);
            if ($porTelefone && strlen($digitos) >= 4) {
                $q->orWhere('telefone', 'like', '%'.$digitos.'%');
            }
            if (strlen($placa) >= 3) {
                $q->orWhereHas('veiculos', fn (Builder $v) => $v->where('placa', 'like', $placa.'%'));
            }
        });
    }

    public function iniciais(): string
    {
        $partes = preg_split('/\s+/', trim(preg_replace('/\(.*?\)/', '', $this->nome)));

        return mb_strtoupper(mb_substr($partes[0], 0, 1).(count($partes) > 1 ? mb_substr(end($partes), 0, 1) : ''));
    }

    /** Endereço em uma linha (exibir só a quem tem ver_contato_cliente). */
    public function enderecoTexto(): string
    {
        return \App\Support\Endereco::texto($this);
    }

    public function telefoneFormatado(): string
    {
        return Normaliza::telefoneExibicao($this->telefone);
    }
}
