<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transacciones_caja extends Model
{
    protected $primaryKey = 'id_transaccion';
    protected $fillable = [
        'id_vehiculo',
        'id_tipo_transaccion',
        'id_user',
        'id_metodo_pago',
        'id_cliente',
        'monto',
        'descripcion',
        'fecha_transaccion',
        'ruta_comprobante'
    ];

    function vehiculo()
    {
        return $this->belongsTo(Vehiculo::class, 'id_vehiculo', 'id_vehiculo');

    }

    function tipo_transaccion()
    {
        return $this->belongsTo(Tipo_transaccion::class, 'id_tipo_transaccion', 'id_tipo_transaccion');
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
