<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venta extends Model
{
    protected $table = 'ventas';

    protected $primaryKey = 'id_venta';

    protected $fillable = [
        'id_cliente',
        'id_vehiculo',
        'id_user',
        'id_metodo_pago',
        'tipo_operacion',
        'precio_base',
        'precio_acordado',
        'requiere_aprobacion_descuento',
        'estado_aprobacion_descuento',
        'motivo_descuento',
        'comentario_aprobacion_descuento',
        'aprobado_descuento_por',
        'aprobado_descuento_en',
        'fecha_venta',
        'precio_venta',
        'estado',
        'forma_pago',
        'modalidad_credito',
        'cuota_mensual_credito',
        'meses_credito',
        'fecha_siguiente_pago_credito',
        'plan_cuotas_credito',
        'requiere_aprobacion_credito',
        'estado_aprobacion_credito',
        'comentario_aprobacion_credito',
        'aprobado_credito_por',
        'aprobado_credito_en',
        'fecha_vencimiento_credito',
        'observaciones_credito',
        'ruta_cedula_comprador',
        'datos_cedula_comprador',
        'ruta_constancia_generada',
        'utilidad_neta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_venta' => 'date:Y-m-d',
            'fecha_vencimiento_credito' => 'date:Y-m-d',
            'fecha_siguiente_pago_credito' => 'date:Y-m-d',
            'aprobado_descuento_en' => 'datetime',
            'aprobado_credito_en' => 'datetime',
            'requiere_aprobacion_descuento' => 'boolean',
            'requiere_aprobacion_credito' => 'boolean',
            'precio_base' => 'decimal:2',
            'precio_acordado' => 'decimal:2',
            'precio_venta' => 'decimal:2',
            'cuota_mensual_credito' => 'decimal:2',
            'meses_credito' => 'integer',
            'plan_cuotas_credito' => 'array',
            'datos_cedula_comprador' => 'encrypted:array',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id_cliente');
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class, 'id_vehiculo', 'id_vehiculo');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_user', 'id');
    }

    public function aprobadorDescuento(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_descuento_por', 'id');
    }

    public function aprobadorCredito(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_credito_por', 'id');
    }

    public function metodo_pago(): BelongsTo
    {
        return $this->belongsTo(Metodo_pago::class, 'id_metodo_pago', 'id_metodo_pago');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoVenta::class, 'id_venta', 'id_venta');
    }
}
