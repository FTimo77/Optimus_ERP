<?php

namespace Database\Seeders;

use App\Models\Tipo_combustible;
use App\Models\Transmision;
use Illuminate\Database\Seeder;

class CombustibleYTransmisionSeeder extends Seeder
{
    public function run(): void
    {
        $combustibles = [
            'Gasolina',
            'Diésel',
            'Híbrido',
            'Híbrido Enchufable',
            'Eléctrico',
            'GLP (Gas)',
        ];

        foreach ($combustibles as $nombre) {
            Tipo_combustible::firstOrCreate(['nombre' => $nombre]);
        }

        $transmisiones = [
            'Manual',
            'Automática',
            'CVT (Variable Continua)',
            'Tiptronic / Secuencial',
            'Doble Embrague (DCT)',
        ];

        foreach ($transmisiones as $nombre) {
            Transmision::firstOrCreate(['nombre' => $nombre]);
        }
    }
}
