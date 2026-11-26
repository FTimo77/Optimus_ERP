<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoCliente extends Model
{
    public const COMPRADOR = 'Comprador';

    public const VENDEDOR = 'Vendedor';

    public const AMBOS = 'Ambos';

    protected $table = 'tipo_cliente';

    protected $primaryKey = 'id_tipo_cliente';

    protected $fillable = [
        'nombre',
        'descripcion',
    ];

    public function clientes(): HasMany
    {
        return $this->hasMany(Cliente::class, 'id_tipo_cliente', 'id_tipo_cliente');
    }
}
