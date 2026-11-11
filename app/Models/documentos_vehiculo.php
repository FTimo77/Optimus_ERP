<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Documentos_vehiculo extends Model
{
    protected $primaryKey = 'id_documento_vehiculo';
    protected $fillable = [
        'id_vehiculo',
        'ruta_contrato',
        'ruta_matricula',
        'ruta_cuv',
        'ruta_impronta',
        'ruta_constancia',
        'ruta_acta_entrega',
        'ruta_factura'
    ];

    public function vehiculo()
    {
        return $this->belongsTo(Vehiculo::class, 'id_vehiculo', 'id_vehiculo');
    }
}
