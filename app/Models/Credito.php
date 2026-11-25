<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Credito extends Model
{
    protected $table = 'creditos';

    protected $primaryKey = 'id_credito';

    protected $fillable = [
        'validacion_documento_id',
        'id_cliente',
        'id_venta',
        'id_vehiculo',
        'id_tasa_interes',
        'user_id',
        'monto_total',
        'monto_inicial',
        'monto_financiado',
        'saldo_pendiente',
        'numero_cuotas',
        'fecha_inicio',
        'fecha_vencimiento',
        'fecha_vencimiento_final',
        'ruta_pagare',
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
            'monto_inicial' => 'decimal:2',
            'monto_financiado' => 'decimal:2',
            'saldo_pendiente' => 'decimal:2',
            'numero_cuotas' => 'integer',
            'fecha_inicio' => 'date:Y-m-d',
            'fecha_vencimiento' => 'date:Y-m-d',
            'fecha_vencimiento_final' => 'date:Y-m-d',
        ];
    }

    public function validacion(): BelongsTo
    {
        return $this->belongsTo(ValidacionDocumento::class, 'validacion_documento_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id_cliente');
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'id_venta', 'id_venta');
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class, 'id_vehiculo', 'id_vehiculo');
    }

    public function tasaInteres(): BelongsTo
    {
        return $this->belongsTo(TasaInteres::class, 'id_tasa_interes', 'id_tasa_interes');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por', 'id');
    }

    public function cuotas(): HasMany
    {
        return $this->hasMany(CuotaCredito::class, 'id_credito', 'id_credito')
            ->orderBy('numero_cuota');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoCredito::class, 'id_credito', 'id_credito')
            ->orderByDesc('id_pago_credito');
    }
}
