<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Movimientos_vehiculo extends Model
{
    protected $primaryKey = 'id_movimiento';
    protected $fillable = [
        'id_vehiculo',
        'id_tipo_movimiento',
        'id_user',
        'id_cliente',
        'descripcion',
        'fecha_movimiento'
    ];

    function vehiculo()
    {
        return $this->belongsTo(Vehiculo::class, 'id_vehiculo', 'id_vehiculo');
    }

    function tipo_movimiento()
    {
        return $this->belongsTo(Tipo_movimiento::class, 'id_tipo_movimiento', 'id_tipo_movimiento');
    }

    function user()
    {
        return $this->belongsTo(User::class, 'id_user', 'id');
    }

    function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id_cliente');
    }


}
