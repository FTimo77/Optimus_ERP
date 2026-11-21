<?php

namespace Database\Seeders;

use App\Models\TipoContrato;
use Illuminate\Database\Seeder;

class TipoContratoSeeder extends Seeder
{
    public function run(): void
    {
        $tipos = [
            ['nombre' => 'Contrato de compra y venta', 'requiere_documento' => true],
            ['nombre' => 'Contrato de mandato', 'requiere_documento' => true],
            ['nombre' => 'Poder', 'requiere_documento' => true],
            ['nombre' => 'Firma directa', 'requiere_documento' => false],
        ];

        foreach ($tipos as $tipo) {
            TipoContrato::updateOrCreate(
                ['nombre' => $tipo['nombre']],
                ['requiere_documento' => $tipo['requiere_documento']],
            );
        }
    }
}
