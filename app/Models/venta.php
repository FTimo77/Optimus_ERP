<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Venta extends Model
{
    protected $primaryKey = 'id_venta';
    protected $fillable = [
        'id_cliente',
        'id_vehiculo',
        'id_user',
        'fecha_venta',
        'precio_venta',
        'id_metodo_pago',
        'estado',
        'utilidad_neta'
    ];

    function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id_cliente');
    }

    function vehiculo()
    {
        return $this->belongsTo(Vehiculo::class, 'id_vehiculo', 'id_vehiculo');
    }

    function user()
    {
        return $this->belongsTo(User::class, 'id_user', 'id');
    }

    function metodo_pago()
    {
        return $this->belongsTo(Metodo_pago::class, 'id_metodo_pago', 'id_metodo_pago');
    }
}
