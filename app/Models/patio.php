<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Patio extends Model
{
    protected $primaryKey = 'id_patio';
    protected $fillable = [
        'nombre'
    ];
}
