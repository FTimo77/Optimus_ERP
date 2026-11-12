<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoVehiculo extends Model
{
    protected $table = 'tipo_vehiculos';

    protected $primaryKey = 'id_tipo_vehiculo';

    protected $fillable = ['nombre'];

    public function vehiculos(): HasMany
    {
        return $this->hasMany(Vehiculo::class, 'id_tipo_vehiculo', 'id_tipo_vehiculo');
    }
}
