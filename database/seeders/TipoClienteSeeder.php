<?php

namespace Database\Seeders;

use App\Models\TipoCliente;
use Illuminate\Database\Seeder;

class TipoClienteSeeder extends Seeder
{
    public function run(): void
    {
        $tipos = [
            [
                'nombre' => TipoCliente::COMPRADOR,
                'descripcion' => 'Cliente que compra o reserva vehículos en el concesionario.',
            ],
            [
                'nombre' => TipoCliente::VENDEDOR,
                'descripcion' => 'Persona natural o jurídica que vende un vehículo al concesionario.',
            ],
            [
                'nombre' => TipoCliente::AMBOS,
                'descripcion' => 'Cliente que ha vendido y comprado vehículos en el concesionario.',
            ],
        ];

        foreach ($tipos as $tipo) {
            TipoCliente::updateOrCreate(
                ['nombre' => $tipo['nombre']],
                ['descripcion' => $tipo['descripcion']],
            );
        }
    }
}
