<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proveedor extends Model
{
    protected $table = 'proveedores';
    protected $primaryKey = 'id_proveedor';
    protected $fillable = [
        'nombre',
        'cedula',
        'correo',
        'direccion',
        'ruta_cedula'
    ];
}
