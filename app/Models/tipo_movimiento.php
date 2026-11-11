<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tipo_movimiento extends Model
{
    protected $primaryKey = 'id_tipo_movimiento';
    protected $fillable = [
        'nombre'
    ];
}
