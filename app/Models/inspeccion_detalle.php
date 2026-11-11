<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inspeccion_detalle extends Model
{
    protected $primaryKey = 'id_inspeccion_detalle';
    protected $fillable = [
        'id_item_inspeccion',
        'cumple',
        'observaciones'
    ];

  
    function item_inspeccion()
    {
        return $this->belongsTo(Item_inspeccion::class, 'id_item_inspeccion', 'id_item_inspeccion');
    }
}
