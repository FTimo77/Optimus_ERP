<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PagoVehiculo extends Model
{
    protected $table = 'pagos_vehiculos';

    protected $primaryKey = 'id_pago';

    protected $fillable = [
        'validacion_documento_id',
        'id_vehiculo',
        'ruta_comprobante',
        'monto',
        'tipo_pago',
        'fecha_pago',
        'registrado_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha_pago' => 'date:Y-m-d',
        ];
    }

    public function validacion(): BelongsTo
    {
        return $this->belongsTo(ValidacionDocumento::class, 'validacion_documento_id');
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class, 'id_vehiculo', 'id_vehiculo');
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
