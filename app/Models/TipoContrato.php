<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoContrato extends Model
{
    protected $table = 'tipo_contrato';

    protected $primaryKey = 'id_tipo_contrato';

    protected $fillable = [
        'nombre',
        'requiere_documento',
        'cantidad_documentos',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requiere_documento' => 'boolean',
            'cantidad_documentos' => 'integer',
        ];
    }

    public function cantidadDocumentosRequeridos(): int
    {
        if (! $this->requiere_documento) {
            return 0;
        }

        if ($this->cantidad_documentos !== null && (int) $this->cantidad_documentos > 0) {
            return (int) $this->cantidad_documentos;
        }

        return mb_strtolower(trim((string) $this->nombre), 'UTF-8') === 'doble contrato' ? 2 : 1;
    }
}
