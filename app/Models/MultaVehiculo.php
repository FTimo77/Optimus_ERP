<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MultaVehiculo extends Model
{
    protected $table = 'multas_vehiculos';

    protected $primaryKey = 'id_multa';

    protected $fillable = [
        'id_vehiculo',
        'fuente',
        'concepto',
        'valor',
        'fecha_infraccion',
        'estado',
        'ruta_comprobante_pago',
        'pagado_por',
        'pagado_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'pagado_en' => 'datetime',
        ];
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class, 'id_vehiculo', 'id_vehiculo');
    }

    public function pagador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pagado_por');
    }
}
