<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Estado_vehiculo extends Model
{
    protected $primaryKey = 'id_estado';
    protected $fillable = [
        'nombre'
    ];
}
