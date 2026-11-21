<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Documentos_vehiculo extends Model
{
    protected $primaryKey = 'id_documento_vehiculo';
    protected $fillable = [
        'id_vehiculo',
        'id_tipo_contrato',
        'ruta_contrato',
        'ruta_matricula',
        'ruta_cuv',
        'ruta_comprobante_pago',
        'ruta_impronta',
        'ruta_constancia',
        'ruta_acta_entrega',
        'ruta_factura'
    ];

    public function vehiculo()
    {
        return $this->belongsTo(Vehiculo::class, 'id_vehiculo', 'id_vehiculo');
    }

    public function tipoContrato()
    {
        return $this->belongsTo(TipoContrato::class, 'id_tipo_contrato', 'id_tipo_contrato');
    }
}
