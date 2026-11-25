<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PagoCredito extends Model
{
    protected $table = 'pagos_credito';

    protected $primaryKey = 'id_pago_credito';

    protected $fillable = [
        'id_credito',
        'id_cuota_credito',
        'id_transaccion_caja',
        'user_id',
        'monto_pagado',
        'metodo_pago',
        'numero_referencia',
        'fecha_pago',
        'ruta_comprobante',
        'observaciones',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto_pagado' => 'decimal:2',
            'fecha_pago' => 'date:Y-m-d',
        ];
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'id_credito', 'id_credito');
    }

    public function cuota(): BelongsTo
    {
        return $this->belongsTo(CuotaCredito::class, 'id_cuota_credito', 'id_cuota_credito');
    }

    public function transaccionCaja(): BelongsTo
    {
        return $this->belongsTo(Transacciones_caja::class, 'id_transaccion_caja', 'id_transaccion');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
