<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Color_vehiculo extends Model
{
    protected $primaryKey = 'id_color';
    protected $fillable = [
        'nombre'
    ];
}
