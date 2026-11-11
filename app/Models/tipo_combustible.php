<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tipo_combustible extends Model
{
    protected $primaryKey = 'id_tipo_combustible';
    protected $fillable = [
        'nombre'
    ];
}
