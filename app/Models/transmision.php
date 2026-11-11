<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transmision extends Model
{
    protected $table = 'transmisiones';
    protected $primaryKey = 'id_transmision';
    protected $fillable = [
        'nombre'
    ];
}
