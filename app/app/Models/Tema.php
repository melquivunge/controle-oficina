<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tema personalizado: base clara ou escura + cor da marca e cor do menu. Criado pelo proprietário. */
class Tema extends Model
{
    protected $table = 'temas';

    protected $fillable = ['nome', 'base', 'cor_marca', 'cor_menu'];

    public function chave(): string
    {
        return 'tema:'.$this->id;
    }
}
