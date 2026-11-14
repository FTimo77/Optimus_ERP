<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValidacionDocumento extends Model
{
    protected $fillable = [
        'placa',
        'ruta_documento',
        'mime_type',
        'datos_documento',
        'datos_oficiales',
        'comparacion',
        'alertas_criticas',
        'estado_revision',
        'subido_por',
        'revisado_por',
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
}
