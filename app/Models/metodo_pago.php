<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Metodo_pago extends Model
{
    protected $primaryKey = 'id_metodo_pago';
    protected $fillable = [
        'nombre'
    ];
}
