<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageDelivery extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['segredo_link'];

    protected function casts(): array
    {
        return ['segredo_link' => 'encrypted', 'enviado_em' => 'datetime'];
    }

    public function rotuloTipo(): string
    {
        return ['entrada' => 'Confirmação de entrada', 'orcamento' => 'Orçamento para aprovação', 'cobranca' => 'Cobrança', 'conclusao' => 'Veículo pronto', 'aviso_colaborador' => 'Aviso de serviço ao colaborador', 'documento_os' => 'OS enviada por WhatsApp'][$this->tipo];
    }

    public function rotuloStatus(): string
    {
        return ['pendente' => 'Na fila de envio', 'enviado' => 'Aceita pelo provedor (entrega não confirmada)', 'falhou' => 'Falhou — reenviar'][$this->status];
    }
}
