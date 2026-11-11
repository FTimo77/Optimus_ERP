<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    protected $primaryKey = 'id_cliente';
    protected $fillable = [
        'nombre',
        'apellido',
        'telefono',
        'correo',
        'direccion',
        'cedula',
        'ruta_uafe',
        'ruta_cedula',
    ];
}
