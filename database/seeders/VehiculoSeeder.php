<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VehiculoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $catalogs = [
                'marca_vehiculos' => ['id_marca', ['Toyota', 'Kia', 'Hyundai', 'Nissan', 'Chevrolet']],
                'clase_vehiculos' => ['id_clase', ['SUV', 'Sedan', 'Pickup', 'Hatchback']],
                'paises' => ['id_pais', ['Japon', 'Corea del Sur', 'Ecuador', 'Estados Unidos']],
                'color_vehiculos' => ['id_color', ['Blanco', 'Negro', 'Gris', 'Rojo', 'Azul']],
                'tipo_combustibles' => ['id_tipo_combustible', ['Gasolina', 'Diesel', 'Hibrido']],
                'transmisiones' => ['id_transmision', ['Automatica', 'Manual']],
                'patios' => ['id_patio', ['Patio Quito', 'Patio Guayaquil']],
                'estado_vehiculos' => ['id_estado', ['Disponible', 'En preparacion']],
            ];

            $catalogIds = [];
            $now = now();

            foreach ($catalogs as $table => [$primaryKey, $names]) {
                foreach ($names as $name) {
                    $id = DB::table($table)
                        ->where('nombre', $name)
                        ->value($primaryKey);

                    if ($id === null) {
                        $id = DB::table($table)->insertGetId([
                            'nombre' => $name,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ], $primaryKey);
                    }

                    $catalogIds[$table][$name] = $id;
                }
            }

            $providerEmail = 'proveedor-demo@optimus.test';
            $providerId = DB::table('proveedores')
                ->where('correo', $providerEmail)
                ->value('id_proveedor');

            if ($providerId === null) {
                $providerId = DB::table('proveedores')->insertGetId([
                    'nombre' => 'Proveedor de Prueba Optimus',
                    'cedula' => '9999999999',
                    'correo' => $providerEmail,
                    'direccion' => 'Direccion de prueba',
                    'ruta_cedula' => 'documentos/demo-identificacion.pdf',
                    'created_at' => $now,
                    'updated_at' => $now,
                ], 'id_proveedor');
            }

            $vehicles = [
                [
                    'marca' => 'Toyota',
                    'clase' => 'SUV',
                    'pais' => 'Japon',
                    'color' => 'Blanco',
                    'tipo_combustible' => 'Gasolina',
                    'transmision' => 'Automatica',
                    'patio' => 'Patio Quito',
                    'estado' => 'Disponible',
                    'modelo' => 'RAV4',
                    'anio' => 2023,
                    'motor' => '2.5L',
                    'chasis' => 'OPT2023TOYOTARAV4',
                    'cilindraje' => '2500 cc',
                    'kilometraje' => 18500,
                    'costo_adquisicion' => 28500.00,
                    'precio_venta' => 32900.00,
                ],
                [
                    'marca' => 'Kia',
                    'clase' => 'Sedan',
                    'pais' => 'Corea del Sur',
                    'color' => 'Gris',
                    'tipo_combustible' => 'Gasolina',
                    'transmision' => 'Automatica',
                    'patio' => 'Patio Guayaquil',
                    'estado' => 'Disponible',
                    'modelo' => 'Rio',
                    'anio' => 2022,
                    'motor' => '1.4L',
                    'chasis' => 'OPT2022KIARIO001',
                    'cilindraje' => '1400 cc',
                    'kilometraje' => 26300,
                    'costo_adquisicion' => 14500.00,
                    'precio_venta' => 17400.00,
                ],
                [
                    'marca' => 'Hyundai',
                    'clase' => 'SUV',
                    'pais' => 'Corea del Sur',
                    'color' => 'Negro',
                    'tipo_combustible' => 'Hibrido',
                    'transmision' => 'Automatica',
                    'patio' => 'Patio Quito',
                    'estado' => 'Disponible',
                    'modelo' => 'Tucson',
                    'anio' => 2024,
                    'motor' => '1.6L Hibrido',
                    'chasis' => 'OPT2024HYUNDTUCSN',
                    'cilindraje' => '1600 cc',
                    'kilometraje' => 9200,
                    'costo_adquisicion' => 34200.00,
                    'precio_venta' => 38900.00,
                ],
                [
                    'marca' => 'Nissan',
                    'clase' => 'Pickup',
                    'pais' => 'Japon',
                    'color' => 'Rojo',
                    'tipo_combustible' => 'Diesel',
                    'transmision' => 'Manual',
                    'patio' => 'Patio Guayaquil',
                    'estado' => 'En preparacion',
                    'modelo' => 'Frontier',
                    'anio' => 2021,
                    'motor' => '2.5L Diesel',
                    'chasis' => 'OPT2021NISSFRONT01',
                    'cilindraje' => '2500 cc',
                    'kilometraje' => 41700,
                    'costo_adquisicion' => 26900.00,
                    'precio_venta' => 31200.00,
                ],
                [
                    'marca' => 'Chevrolet',
                    'clase' => 'Hatchback',
                    'pais' => 'Estados Unidos',
                    'color' => 'Azul',
                    'tipo_combustible' => 'Gasolina',
                    'transmision' => 'Manual',
                    'patio' => 'Patio Quito',
                    'estado' => 'Disponible',
                    'modelo' => 'Spark',
                    'anio' => 2020,
                    'motor' => '1.2L',
                    'chasis' => 'OPT2020CHEVSPARK01',
                    'cilindraje' => '1200 cc',
                    'kilometraje' => 53800,
                    'costo_adquisicion' => 9200.00,
                    'precio_venta' => 11500.00,
                ],
            ];

            foreach ($vehicles as $vehicle) {
                DB::table('vehiculos')->updateOrInsert(
                    ['chasis' => $vehicle['chasis']],
                    [
                        'id_marca' => $catalogIds['marca_vehiculos'][$vehicle['marca']],
                        'id_clase' => $catalogIds['clase_vehiculos'][$vehicle['clase']],
                        'id_pais' => $catalogIds['paises'][$vehicle['pais']],
                        'id_color' => $catalogIds['color_vehiculos'][$vehicle['color']],
                        'id_tipo_combustible' => $catalogIds['tipo_combustibles'][$vehicle['tipo_combustible']],
                        'id_transmision' => $catalogIds['transmisiones'][$vehicle['transmision']],
                        'id_patio' => $catalogIds['patios'][$vehicle['patio']],
                        'id_estado' => $catalogIds['estado_vehiculos'][$vehicle['estado']],
                        'id_proveedor' => $providerId,
                        'modelo' => $vehicle['modelo'],
                        'anio' => $vehicle['anio'],
                        'motor' => $vehicle['motor'],
                        'chasis' => $vehicle['chasis'],
                        'cilindraje' => $vehicle['cilindraje'],
                        'kilometraje' => $vehicle['kilometraje'],
                        'costo_adquisicion' => $vehicle['costo_adquisicion'],
                        'precio_venta' => $vehicle['precio_venta'],
                        'ruta_matricula' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        });
    }
}
