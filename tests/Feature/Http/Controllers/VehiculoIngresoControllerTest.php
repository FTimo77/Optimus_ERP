<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VehiculoIngresoControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_page_provides_fuel_types_to_the_form(): void
    {
        $catalogIds = $this->createCatalogs();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('vehiculos.create'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Vehiculos/Ingreso')
            ->has('combustibles', 1)
            ->where('combustibles.0.id_tipo_combustible', $catalogIds['id_tipo_combustible'])
            ->where('combustibles.0.nombre', 'Gasolina')
            ->has('estados', 1)
            ->where('estados.0.id_estado', $catalogIds['id_estado'])
            ->where('estados.0.nombre', 'Disponible')
        );
    }

    public function test_authenticated_user_can_create_a_vehicle_with_a_fuel_type(): void
    {
        $catalogIds = $this->createCatalogs();

        $response = $this->actingAs(User::factory()->create())
            ->post(route('vehiculos.store'), [
                ...$catalogIds,
                'id_vehiculo' => 'pba-1234',
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
            'id_vehiculo' => 'PBA-1234',
            'chasis' => 'CHASIS-TEST-001',
            'id_tipo_combustible' => $catalogIds['id_tipo_combustible'],
        ]);

    }

    public function test_authenticated_user_can_create_a_vehicle_with_multiple_images(): void
    {
        Storage::fake('public');
        $catalogIds = $this->createCatalogs();

        $response = $this->actingAs(User::factory()->create())
            ->post(route('vehiculos.store'), [
                ...$catalogIds,
                'id_vehiculo' => 'PBC-3456',
                'modelo' => 'Corolla',
                'anio' => 2024,
                'motor' => 'MOTOR-TEST-004',
                'chasis' => 'CHASIS-TEST-004',
                'cilindraje' => '1800 cc',
                'kilometraje' => 12000,
                'costo_adquisicion' => 18000,
                'precio_venta' => 21500,
                'imagenes' => [
                    UploadedFile::fake()->image('vehiculo-frontal.jpg'),
                    UploadedFile::fake()->image('vehiculo-lateral.png'),
                ],
            ]);

        $response->assertRedirect(route('vehiculos.create'));
        $imagePaths = DB::table('imagen_vehiculos')
            ->where('id_vehiculo', 'PBC-3456')
            ->orderBy('id_imagen_vehiculo')
            ->pluck('ruta_imagen')
            ->all();

        $this->assertCount(2, $imagePaths);
        $this->assertDatabaseHas('imagen_vehiculos', [
            'id_vehiculo' => 'PBC-3456',
            'ruta_imagen' => $imagePaths[0],
            'es_principal' => true,
        ]);
        $this->assertDatabaseHas('imagen_vehiculos', [
            'id_vehiculo' => 'PBC-3456',
            'ruta_imagen' => $imagePaths[1],
            'es_principal' => false,
        ]);
        Storage::disk('public')->assertExists($imagePaths[0]);
        Storage::disk('public')->assertExists($imagePaths[1]);

        $this->get(route('vehiculos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehiculos/Inventario')
                ->where('vehiculos.0.imagen_url', Storage::disk('public')->url($imagePaths[0]))
            );
    }

    public function test_vehicle_is_not_created_with_a_non_image_upload(): void
    {
        $catalogIds = $this->createCatalogs();

        $response = $this->actingAs(User::factory()->create())
            ->from(route('vehiculos.create'))
            ->post(route('vehiculos.store'), [
                ...$catalogIds,
                'id_vehiculo' => 'PBD-4567',
                'modelo' => 'Corolla',
                'anio' => 2024,
                'motor' => 'MOTOR-TEST-005',
                'chasis' => 'CHASIS-TEST-005',
                'cilindraje' => '1800 cc',
                'kilometraje' => 12000,
                'costo_adquisicion' => 18000,
                'precio_venta' => 21500,
                'imagenes' => [
                    UploadedFile::fake()->image('vehiculo-valido.jpg'),
                    UploadedFile::fake()->create('archivo.txt', 1, 'text/plain'),
                ],
            ]);

        $response->assertSessionHasErrors('imagenes.1');
        $this->assertDatabaseMissing('vehiculos', [
            'id_vehiculo' => 'PBD-4567',
        ]);
    }

    public function test_vehicle_is_not_created_with_more_than_ten_images(): void
    {
        $catalogIds = $this->createCatalogs();

        $response = $this->actingAs(User::factory()->create())
            ->from(route('vehiculos.create'))
            ->post(route('vehiculos.store'), [
                ...$catalogIds,
                'id_vehiculo' => 'PBE-5678',
                'modelo' => 'Corolla',
                'anio' => 2024,
                'motor' => 'MOTOR-TEST-006',
                'chasis' => 'CHASIS-TEST-006',
                'cilindraje' => '1800 cc',
                'kilometraje' => 12000,
                'costo_adquisicion' => 18000,
                'precio_venta' => 21500,
                'imagenes' => array_map(
                    fn (int $index): UploadedFile => UploadedFile::fake()->image("vehiculo-{$index}.jpg"),
                    range(1, 11),
                ),
            ]);

        $response->assertSessionHasErrors('imagenes');
        $this->assertDatabaseMissing('vehiculos', [
            'id_vehiculo' => 'PBE-5678',
        ]);
    }

    public function test_vehicle_is_not_created_without_a_plate(): void
    {
        $catalogIds = $this->createCatalogs();

        $response = $this->actingAs(User::factory()->create())
            ->from(route('vehiculos.create'))
            ->post(route('vehiculos.store'), [
                ...$catalogIds,
                'modelo' => 'Corolla',
                'anio' => 2024,
                'motor' => 'MOTOR-TEST-003',
                'chasis' => 'CHASIS-TEST-003',
                'cilindraje' => '1800 cc',
                'kilometraje' => 12000,
                'costo_adquisicion' => 18000,
                'precio_venta' => 21500,
            ]);

        $response->assertSessionHasErrors('id_vehiculo');
        $this->assertDatabaseMissing('vehiculos', [
            'chasis' => 'CHASIS-TEST-003',
        ]);
    }

    public function test_vehicle_is_not_created_with_a_duplicate_plate(): void
    {
        $catalogIds = $this->createCatalogs();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('vehiculos.store'), [
            ...$catalogIds,
            'id_vehiculo' => 'PBA-1234',
            'modelo' => 'Corolla',
            'anio' => 2024,
            'motor' => 'MOTOR-TEST-001',
            'chasis' => 'CHASIS-TEST-001',
            'cilindraje' => '1800 cc',
            'kilometraje' => 12000,
            'costo_adquisicion' => 18000,
            'precio_venta' => 21500,
        ])->assertRedirect(route('vehiculos.create'));

        $response = $this->from(route('vehiculos.create'))->post(route('vehiculos.store'), [
            ...$catalogIds,
            'id_vehiculo' => 'PBA-1234',
            'modelo' => 'Corolla',
            'anio' => 2024,
            'motor' => 'MOTOR-TEST-002',
            'chasis' => 'CHASIS-TEST-002',
            'cilindraje' => '1800 cc',
            'kilometraje' => 12000,
            'costo_adquisicion' => 18000,
            'precio_venta' => 21500,
        ]);

        $response->assertSessionHasErrors('id_vehiculo');
        $this->assertDatabaseMissing('vehiculos', [
            'chasis' => 'CHASIS-TEST-002',
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
                'id_vehiculo' => 'PBB-2345',
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
