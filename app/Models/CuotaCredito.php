<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CuotaCredito extends Model
{
    protected $table = 'cuotas_credito';

    protected $primaryKey = 'id_cuota_credito';

    protected $fillable = [
        'id_credito',
        'id_venta',
        'numero_cuota',
        'fecha_vencimiento',
        'monto_capital',
        'monto_interes',
        'monto_mora',
        'valor_cuota',
        'monto_pagado',
        'fecha_pago_real',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'numero_cuota' => 'integer',
            'fecha_vencimiento' => 'date:Y-m-d',
            'monto_capital' => 'decimal:2',
            'monto_interes' => 'decimal:2',
            'monto_mora' => 'decimal:2',
            'valor_cuota' => 'decimal:2',
            'monto_pagado' => 'decimal:2',
            'fecha_pago_real' => 'date:Y-m-d',
        ];
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'id_credito', 'id_credito');
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'id_venta', 'id_venta');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoCredito::class, 'id_cuota_credito', 'id_cuota_credito');
    }
}
