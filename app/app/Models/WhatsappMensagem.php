<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappMensagem extends Model
{
    /** Ordem de avanço do status de entrega; status nunca regride (webhooks podem chegar fora de ordem). */
    public const ORDEM_STATUS = ['enviando' => 1, 'enviada' => 2, 'entregue' => 3, 'lida' => 4];

    protected $table = 'whatsapp_mensagens';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['historico' => 'boolean', 'ocorrida_em' => 'datetime', 'enviada_em' => 'datetime', 'entregue_em' => 'datetime', 'lida_em' => 'datetime',
            'aguardava_desde' => 'datetime', 'latitude' => 'float', 'longitude' => 'float'];
    }

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(WhatsappConversa::class, 'conversa_id');
    }

    public function numero(): BelongsTo
    {
        return $this->belongsTo(WhatsappNumero::class, 'numero_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }

    public function entrada(): bool
    {
        return $this->direcao === 'entrada';
    }

    public function rotuloStatus(): string
    {
        return ['recebida' => 'Recebida', 'enviando' => 'Enviando', 'enviada' => 'Enviada', 'entregue' => 'Entregue', 'lida' => 'Lida', 'falhou' => 'Falhou'][$this->status] ?? $this->status;
    }

    public function rotuloTipo(): string
    {
        return ['texto' => 'Texto', 'imagem' => 'Imagem', 'audio' => 'Áudio', 'video' => 'Vídeo', 'documento' => 'Documento', 'localizacao' => 'Localização', 'modelo' => 'Modelo aprovado'][$this->tipo] ?? $this->tipo;
    }

    public function resumo(): string
    {
        return match ($this->tipo) {
            'texto' => (string) $this->texto,
            'modelo' => 'Modelo: '.$this->modelo,
            'localizacao' => 'Localização compartilhada',
            default => $this->rotuloTipo().($this->texto ? ': '.$this->texto : ''),
        };
    }
}
