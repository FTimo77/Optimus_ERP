<?php

namespace Database\Seeders;

use App\Models\TipoContrato;
use Illuminate\Database\Seeder;

class TipoContratoSeeder extends Seeder
{
    public function run(): void
    {
        $tipos = [
            ['nombre' => 'Contrato de compra y venta', 'requiere_documento' => true, 'cantidad_documentos' => 1],
            ['nombre' => 'Contrato de mandato', 'requiere_documento' => true, 'cantidad_documentos' => 1],
            ['nombre' => 'Poder', 'requiere_documento' => true, 'cantidad_documentos' => 1],
            ['nombre' => 'Firma directa', 'requiere_documento' => false, 'cantidad_documentos' => 0],
            ['nombre' => 'Doble Contrato', 'requiere_documento' => true, 'cantidad_documentos' => 2],
            ['nombre' => 'Propietario Optimus', 'requiere_documento' => false, 'cantidad_documentos' => 0],
        ];

        foreach ($tipos as $tipo) {
            TipoContrato::updateOrCreate(
                ['nombre' => $tipo['nombre']],
                [
                    'requiere_documento' => $tipo['requiere_documento'],
                    'cantidad_documentos' => $tipo['cantidad_documentos'],
                ],
            );
        }
    }
}
