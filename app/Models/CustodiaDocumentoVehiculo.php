<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustodiaDocumentoVehiculo extends Model
{
    protected $table = 'custodia_documentos_vehiculos';

    protected $primaryKey = 'id_custodia';

    public const ITEMS = [
        'matricula' => 'Documento de matrícula',
        'rtv' => 'Revisión Técnica Vehicular (RTV)',
        'contrato_legalizado' => 'Contrato legalizado',
        'llaves' => 'Llaves del vehículo',
    ];

    protected $fillable = [
        'id_vehiculo',
        'item',
        'estado',
        'usuario_entrega_id',
        'usuario_recepcion_id',
        'patio_destino_id',
        'patio_actual_id',
        'observacion',
        'solicitado_en',
        'recibido_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'solicitado_en' => 'datetime',
            'recibido_en' => 'datetime',
        ];
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class, 'id_vehiculo', 'id_vehiculo');
    }

    public function usuarioEntrega(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_entrega_id');
    }

    public function usuarioRecepcion(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_recepcion_id');
    }

    public function patioDestino(): BelongsTo
    {
        return $this->belongsTo(Patio::class, 'patio_destino_id', 'id_patio');
    }

    public function patioActual(): BelongsTo
    {
        return $this->belongsTo(Patio::class, 'patio_actual_id', 'id_patio');
    }
}
