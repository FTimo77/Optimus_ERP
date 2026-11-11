<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VehiculoIngresoControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_a_vehicle_with_a_fuel_type(): void
    {
        $catalogIds = $this->createCatalogs();

        $response = $this->actingAs(User::factory()->create())
            ->post(route('vehiculos.store'), [
                ...$catalogIds,
                'modelo' => 'Corolla',
                'anio' => 2024,
                'motor' => 'MOTOR-TEST-001',
                'chasis' => 'CHASIS-TEST-001',
                'cilindraje' => '1800 cc',
                'kilometraje' => 12000,
                'costo_adquisicion' => 18000,
                'precio_venta' => 21500,
            ]);

        $response->assertRedirect(route('vehiculos.create'));
        $this->assertDatabaseHas('vehiculos', [
            'chasis' => 'CHASIS-TEST-001',
            'id_tipo_combustible' => $catalogIds['id_tipo_combustible'],
        ]);
    }

    public function test_vehicle_is_not_created_with_a_nonexistent_fuel_type(): void
    {
        $catalogIds = $this->createCatalogs();
        $catalogIds['id_tipo_combustible'] = 999999;

        $response = $this->actingAs(User::factory()->create())
            ->from(route('vehiculos.create'))
            ->post(route('vehiculos.store'), [
                ...$catalogIds,
                'modelo' => 'Corolla',
                'anio' => 2024,
                'motor' => 'MOTOR-TEST-002',
                'chasis' => 'CHASIS-TEST-002',
                'cilindraje' => '1800 cc',
                'kilometraje' => 12000,
                'costo_adquisicion' => 18000,
                'precio_venta' => 21500,
            ]);

        $response->assertSessionHasErrors('id_tipo_combustible');
        $this->assertDatabaseMissing('vehiculos', [
            'chasis' => 'CHASIS-TEST-002',
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function createCatalogs(): array
    {
        $catalogs = [
            'marca_vehiculos' => ['id_marca', 'Toyota'],
            'clase_vehiculos' => ['id_clase', 'Sedan'],
            'paises' => ['id_pais', 'Japon'],
            'color_vehiculos' => ['id_color', 'Blanco'],
            'tipo_combustibles' => ['id_tipo_combustible', 'Gasolina'],
            'transmisiones' => ['id_transmision', 'Automatica'],
            'patios' => ['id_patio', 'Patio Quito'],
            'estado_vehiculos' => ['id_estado', 'Disponible'],
        ];

        $ids = [];

        foreach ($catalogs as $table => [$primaryKey, $name]) {
            $ids[$primaryKey] = DB::table($table)->insertGetId([
                'nombre' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ], $primaryKey);
        }

        $ids['id_proveedor'] = DB::table('proveedores')->insertGetId([
            'nombre' => 'Proveedor de Prueba',
            'cedula' => '1234567890',
            'correo' => 'proveedor-test@example.test',
            'direccion' => 'Direccion de prueba',
            'ruta_cedula' => 'documentos/proveedor-test.pdf',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_proveedor');

        return $ids;
    }
}
