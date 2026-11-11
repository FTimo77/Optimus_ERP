<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tipo_transaccion extends Model
{
    protected $table = 'tipo_transacciones';
    protected $primaryKey = 'id_tipo_transaccion';
    protected $fillable = [
        'nombre'
    ];
}
