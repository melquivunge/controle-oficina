<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationEvent extends Model
{
    protected $table = 'integration_events';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'array', 'recebido_em' => 'datetime', 'processado_em' => 'datetime'];
    }
}
