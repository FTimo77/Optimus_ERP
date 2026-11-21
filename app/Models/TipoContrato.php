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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requiere_documento' => 'boolean',
        ];
    }
}
