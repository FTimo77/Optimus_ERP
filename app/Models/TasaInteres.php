<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TasaInteres extends Model
{
    protected $table = 'tasas_interes';

    protected $primaryKey = 'id_tasa_interes';

    protected $fillable = [
        'nombre',
        'descripcion',
        'tasa_anual',
        'tasa_nominal_anual',
        'tasa_efectiva_anual',
        'tasa_mensual',
        'tasa_mensual_desde_mes_13',
        'tasa_mora',
        'plazo_minimo_meses',
        'plazo_maximo_meses',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tasa_anual' => 'decimal:2',
            'tasa_nominal_anual' => 'decimal:2',
            'tasa_efectiva_anual' => 'decimal:2',
            'tasa_mensual' => 'decimal:4',
            'tasa_mensual_desde_mes_13' => 'decimal:4',
            'tasa_mora' => 'decimal:2',
            'plazo_minimo_meses' => 'integer',
            'plazo_maximo_meses' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function creditos(): HasMany
    {
        return $this->hasMany(Credito::class, 'id_tasa_interes', 'id_tasa_interes');
    }
}
