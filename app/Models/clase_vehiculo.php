<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Clase_Vehiculo extends Model
{
    protected $table = 'clase_vehiculos';
    protected $primaryKey = 'id_clase';
    protected $fillable = [
        'nombre'
    ];
}
