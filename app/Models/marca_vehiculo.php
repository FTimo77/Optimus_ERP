<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Marca_vehiculo extends Model
{
    protected $primaryKey = 'id_marca';
    protected $fillable = [
        'nombre'
    ];
}
