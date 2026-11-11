<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehiculo extends Model
{
    protected $primaryKey = 'id_vehiculo';
    protected $fillable = [
        'id_marca',
        'id_clase',
        'id_pais',
        'id_color',
        'id_tipo_combustible',
        'id_transmision',
        'id_patio',
        'id_estado',
        'id_proveedor',
        'modelo',
        'anio',
        'motor',
        'chasis',
        'cilindraje',
        'kilometraje',
        'costo_adquisicion',
        'precio_venta',
        'ruta_matricula'
    ];

    public function marca()
    {
        return $this->belongsTo(Marca_vehiculo::class, 'id_marca', 'id_marca');
    }

    public function clase()
    {
        return $this->belongsTo(ClaseVehiculo::class, 'id_clase', 'id_clase');
    }

    public function pais()
    {
        return $this->belongsTo(Pais::class, 'id_pais', 'id_pais');
    }   

    public function color()
    {
        return $this->belongsTo(Color_vehiculo::class, 'id_color', 'id_color');
    }

    public function tipo_combustible()
    {
        return $this->belongsTo(Tipo_combustible::class, 'id_tipo_combustible', 'id_tipo_combustible');
    }

    public function transmision()
    {
        return $this->belongsTo(Transmision::class, 'id_transmision', 'id_transmision');
    }

    public function patio()
    {
        return $this->belongsTo(Patio::class, 'id_patio', 'id_patio');
    }

    public function estado()
    {
        return $this->belongsTo(Estado_vehiculo::class, 'id_estado', 'id_estado');
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'id_proveedor', 'id_proveedor');
    }

}
