<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Imagen_vehiculo extends Model
{
    protected $primaryKey = 'id_imagen_vehiculo';
    protected $fillable = [
        'id_vehiculo',
        'ruta_imagen',
        'es_principal'
    ];

    public function vehiculo()
    {
        return $this->belongsTo(Vehiculo::class, 'id_vehiculo', 'id_vehiculo');
    }
}
