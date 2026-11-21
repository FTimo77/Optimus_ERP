<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ValidacionDocumento extends Model
{
    protected $fillable = [
        'placa',
        'categoria_ingreso',
        'ruta_documento',
        'ruta_cuv',
        'ruta_comprobante_pago',
        'ruta_cedula_cliente',
        'ruta_contrato_generado',
        'mime_type',
        'datos_documento',
        'datos_oficiales',
        'comparacion',
        'resumen_auditoria',
        'datos_ingreso',
        'datos_cedula_cliente',
        'alertas_criticas',
        'estado_revision',
        'forma_pago',
        'id_tipo_contrato',
        'subido_por',
        'revisado_por',
        'comentario_solicitud',
        'comentario_reenvio',
        'comentario_revision',
        'revisado_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'datos_documento' => 'encrypted:array',
            'datos_oficiales' => 'encrypted:array',
            'comparacion' => 'encrypted:array',
            'resumen_auditoria' => 'encrypted:array',
            'datos_ingreso' => 'encrypted:array',
            'datos_cedula_cliente' => 'encrypted:array',
            'alertas_criticas' => 'integer',
            'revisado_en' => 'datetime',
        ];
    }

    public function subidor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    public function revisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }

    public function credito(): HasOne
    {
        return $this->hasOne(Credito::class, 'validacion_documento_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoVehiculo::class, 'validacion_documento_id');
    }

    public function tipoContrato(): BelongsTo
    {
        return $this->belongsTo(TipoContrato::class, 'id_tipo_contrato', 'id_tipo_contrato');
    }
}
