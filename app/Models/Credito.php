<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Credito extends Model
{
    protected $table = 'creditos';

    protected $primaryKey = 'id_credito';

    protected $fillable = [
        'validacion_documento_id',
        'id_vehiculo',
        'monto_total',
        'fecha_vencimiento',
        'estado',
        'observaciones',
        'registrado_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto_total' => 'decimal:2',
            'fecha_vencimiento' => 'date:Y-m-d',
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
