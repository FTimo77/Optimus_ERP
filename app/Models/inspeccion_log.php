<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inspeccion_log extends Model
{
    protected $primaryKey = 'id_inspeccion_log';
    protected $fillable = [
        'id_vehiculo',
        'id_user',
        'id_inspeccion_detalle',
        'fecha',
        'observaciones'
    ];

    function vehiculo()
    {
        return $this->belongsTo(Vehiculo::class, 'id_vehiculo', 'id_vehiculo');
    }

    function user()
    {
        return $this->belongsTo(User::class, 'id_user', 'id');
    }

    function inspeccion_detalle()
    {
        return $this->belongsTo(Inspeccion_detalle::class, 'id_inspeccion_detalle', 'id_inspeccion_detalle');
    }
}
