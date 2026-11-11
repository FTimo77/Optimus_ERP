<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item_inspeccion extends Model
{
    protected $table = 'item_inspecciones';
    protected $primaryKey = 'id_item_inspeccion';
    protected $fillable = [
        'nombre',
        'categoria',
        'orden'
    ];
}
