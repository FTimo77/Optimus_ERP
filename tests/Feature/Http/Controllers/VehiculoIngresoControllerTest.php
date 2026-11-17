<?php

namespace Tests\Feature\Http\Controllers;

use App\Http\Controllers\VehiculoIngresoController;
use App\Models\User;
use App\Models\ValidacionDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FakeAxisVehiculoIngresoController extends VehiculoIngresoController
{
    /**
     * @var array<string, mixed>
     */
    public static array $respuestaAxis = [];

    protected function ejecutarAuditoriaCompleta(string $placa, string $archivoTemp): int
    {
        return file_put_contents($archivoTemp, json_encode(self::$respuestaAxis, JSON_THROW_ON_ERROR)) === false ? 1 : 0;
    }
}

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
        );
    }

    public function test_scan_returns_503_when_gemini_api_key_is_missing(): void
    {
        Http::preventStrayRequests();
        config(['services.gemini.api_key' => null]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('vehiculos.escanear.matricula'), [
                'documento' => UploadedFile::fake()->image('matricula.jpg'),
            ], ['Accept' => 'application/json']);

        $response->assertServiceUnavailable()
            ->assertJsonPath('message', 'Gemini no está configurado. Define GEMINI_API_KEY en .env y limpia la caché de configuración.');
    }

    public function test_scan_returns_502_when_gemini_rejects_the_api_key(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['status' => 'PERMISSION_DENIED'],
            ], 403),
        ]);
        config([
            'services.gemini.api_key' => 'test-gemini-api-key',
            'services.gemini.model' => 'gemini-3.5-flash-lite',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('vehiculos.escanear.matricula'), [
                'documento' => UploadedFile::fake()->image('matricula.jpg'),
            ], ['Accept' => 'application/json']);

        $response->assertStatus(502)
            ->assertJsonPath('message', 'Gemini rechazó la clave o sus permisos. Confirma que la clave sea válida y que el proyecto tenga acceso a Gemini API.')
            ->assertJsonPath('manual_review_available', true);

        $this->assertDatabaseCount('validacion_documentos', 0);
        Storage::disk('local')->assertEmpty();
        Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('x-goog-api-key', 'test-gemini-api-key')
            && ! str_contains($request->url(), 'key='));
    }

    public function test_scan_retries_gemini_once_after_a_503_response(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['status' => 'UNAVAILABLE']], 503)
                ->push(['error' => ['status' => 'UNAVAILABLE']], 503),
        ]);
        config([
            'services.gemini.api_key' => 'test-gemini-api-key',
            'services.gemini.model' => 'gemini-3.5-flash-lite',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('vehiculos.escanear.matricula'), [
                'documento' => UploadedFile::fake()->image('matricula.jpg'),
            ], ['Accept' => 'application/json']);

        $response->assertStatus(502)
            ->assertJsonPath('message', 'Gemini está temporalmente no disponible. El sistema reintentó una vez; espera un momento y vuelve a escanear.')
            ->assertJsonPath('manual_review_available', true);

        Http::assertSentCount(2);
    }

    public function test_scan_compares_registration_and_review_dates_with_axiscloud(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => json_encode([
                                    'placa' => 'PBA1234',
                                    'fecha_emision' => '16-JUN-2026',
                                    'fecha_caduca' => '31/03/2029',
                                ], JSON_THROW_ON_ERROR)],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);
        config([
            'services.gemini.api_key' => 'test-gemini-api-key',
            'services.gemini.model' => 'gemini-3.5-flash-lite',
        ]);
        FakeAxisVehiculoIngresoController::$respuestaAxis = [
            'codError' => '0',
            'campos' => json_encode([
                'lsDatosIdentificacion' => [
                    ['etiqueta' => 'Placa:', 'valor' => 'PBA1234'],
                    ['etiqueta' => 'VIN:', 'valor' => 'VIN12345678901234'],
                    ['etiqueta' => 'Motor:', 'valor' => 'MOTOR-AXIS-001'],
                ],
                'lsDatosModelo' => [
                    ['etiqueta' => 'Marca:', 'valor' => 'Toyota'],
                    ['etiqueta' => 'Modelo:', 'valor' => 'Corolla'],
                    ['etiqueta' => 'Año de Fabricación:', 'valor' => '2024'],
                    ['etiqueta' => 'País:', 'valor' => 'Japón'],
                ],
                'lsOtrasCaracteristicas' => [
                    ['etiqueta' => 'Color 1:', 'valor' => 'Blanco'],
                    ['etiqueta' => 'Clase:', 'valor' => 'Sedan'],
                    ['etiqueta' => 'Tipo:', 'valor' => 'Sedan'],
                    ['etiqueta' => 'Cilindraje:', 'valor' => '1800 cc'],
                ],
                'lsServicio' => 'Particular',
                'lsRevision' => [
                    ['etiqueta' => 'Matrícula Desde:', 'valor' => '16-06-2026 09:38:04'],
                    ['etiqueta' => 'Matrícula Hasta:', 'valor' => '31-03-2029 00:00:00'],
                    ['etiqueta' => 'Año Última Revisión:', 'valor' => '2025'],
                    ['etiqueta' => 'Última Revisión Desde:', 'valor' => '20-03-2025 13:52:09'],
                    ['etiqueta' => 'Última Revisión Hasta:', 'valor' => '31-03-2026 00:00:00'],
                ],
            ], JSON_THROW_ON_ERROR),
        ];
        $this->app->bind(VehiculoIngresoController::class, FakeAxisVehiculoIngresoController::class);
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('vehiculos.escanear.matricula'), [
                'documento' => UploadedFile::fake()->image('matricula.jpg'),
            ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('estado_revision', 'borrador')
            ->assertJsonPath('datos_oficiales.placa', 'PBA1234')
            ->assertJsonPath('datos_oficiales.marca', 'Toyota')
            ->assertJsonPath('datos_oficiales.cilindraje', '1800 cc')
            ->assertJsonPath('datos_oficiales.fecha_matricula_desde', '16-06-2026 09:38:04')
            ->assertJsonPath('datos_oficiales.fecha_matricula_hasta', '31-03-2029 00:00:00')
            ->assertJsonPath('datos_oficiales.fecha_ultima_revision_desde', '20-03-2025 13:52:09')
            ->assertJsonPath('datos_oficiales.fecha_ultima_revision_hasta', '31-03-2026 00:00:00')
            ->assertJsonPath('datos_oficiales.anio_ultima_revision', '2025')
            ->assertJsonPath('resumen_auditoria.multas.total', 0)
            ->assertJsonPath('resumen_auditoria.sri.total', 0)
            ->assertJsonPath('comparacion.7.estado', 'coincide')
            ->assertJsonPath('comparacion.8.estado', 'coincide');

        $this->assertNotContains('fecha_revision', array_column($response->json('comparacion'), 'clave'));

        $validacion = ValidacionDocumento::findOrFail($response->json('validacion_id'));

        $this->assertSame('PBA1234', $validacion->placa);
        $this->assertSame('borrador', $validacion->estado_revision);
        $this->assertSame($response->json('datos_documento'), $validacion->datos_documento);
        $this->assertSame(
            $response->json('resumen_auditoria'),
            $validacion->resumen_auditoria,
        );
        $this->assertDatabaseHas('validacion_documentos', [
            'id' => $validacion->id,
            'subido_por' => $user->id,
            'estado_revision' => 'borrador',
        ]);
        $this->assertDatabaseMissing('vehiculos', [
            'id_vehiculo' => $validacion->placa,
        ]);
        Storage::disk('local')->assertExists($validacion->ruta_documento);

        Http::assertSent(function (ClientRequest $request): bool {
            $prompt = $request->data()['contents'][0]['parts'][0]['text'] ?? '';

            return is_string($prompt)
                && str_contains($prompt, '"fecha_emision": ""')
                && str_contains($prompt, '"fecha_caduca": ""')
                && ! str_contains($prompt, '"fecha_revision": ""');
        });
    }

    public function test_partial_axiscloud_data_finishes_audit_and_returns_fields_to_complete(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode(['placa' => 'PBA1234'], JSON_THROW_ON_ERROR),
                        ]],
                    ],
                ]],
            ]),
        ]);
        config([
            'services.gemini.api_key' => 'test-gemini-api-key',
            'services.gemini.model' => 'gemini-3.5-flash-lite',
        ]);
        FakeAxisVehiculoIngresoController::$respuestaAxis = [
            'codError' => '0',
            'campos' => json_encode([
                'lsDatosIdentificacion' => [
                    ['etiqueta' => 'Placa:', 'valor' => 'PBA1234'],
                    ['etiqueta' => 'VIN:', 'valor' => 'VIN12345678901234'],
                    ['etiqueta' => 'Motor:', 'valor' => 'MOTOR-AXIS-001'],
                ],
            ], JSON_THROW_ON_ERROR),
        ];
        $this->app->bind(VehiculoIngresoController::class, FakeAxisVehiculoIngresoController::class);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('vehiculos.escanear.matricula'), [
                'documento' => UploadedFile::fake()->image('matricula.jpg'),
            ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('estado_revision', 'borrador')
            ->assertJsonPath('campos_axis_pendientes.0', 'id_marca')
            ->assertJsonPath('campos_axis_pendientes.6', 'cilindraje');
        $validacion = ValidacionDocumento::findOrFail($response->json('validacion_id'));
        $this->assertSame('', $validacion->datos_oficiales['cilindraje']);
        Storage::disk('local')->assertExists($validacion->ruta_documento);
    }

    public function test_successful_axiscloud_audit_registers_vehicle_using_only_official_vehicle_data(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $catalogIds = $this->createCatalogs();
        $user = User::factory()->create();
        $rutaMatricula = 'validacion-documentos/documento.jpg';
        Storage::disk('local')->put($rutaMatricula, 'matricula');
        $validacion = ValidacionDocumento::create([
            'placa' => 'PBA1234',
            'ruta_documento' => $rutaMatricula,
            'mime_type' => 'image/jpeg',
            'datos_documento' => ['placa' => 'PBA1234'],
            'datos_oficiales' => [
                'placa' => 'PBA1234',
                'marca' => 'Hyundai',
                'clase' => 'SUV',
                'pais' => 'Corea del Sur',
                'color' => 'Azul',
                'modelo' => 'Kona',
                'anio' => '2023',
                'motor' => 'MOTOR-AXIS-001',
                'chasis' => 'CHASIS-AXIS-001',
                'cilindraje' => '1600 cc',
                'tipo_vehiculo' => 'SUV',
                'servicio' => 'Particular',
            ],
            'comparacion' => [],
            'alertas_criticas' => 0,
            'estado_revision' => 'borrador',
            'subido_por' => $user->id,
        ]);

        $response = $this->actingAs($user)->post(route('vehiculos.store'), [
            ...$catalogIds,
            'id_vehiculo' => 'FAKE9999',
            'id_marca' => $catalogIds['id_marca'],
            'id_clase' => $catalogIds['id_clase'],
            'id_pais' => $catalogIds['id_pais'],
            'id_color' => $catalogIds['id_color'],
            'modelo' => 'Datos manipulados',
            'anio' => 2024,
            'motor' => 'MOTOR-FALSO-001',
            'chasis' => 'CHASIS-FALSO-001',
            'cilindraje' => '1800 cc',
            'costo_adquisicion' => 18000,
            'validacion_id' => $validacion->id,
            'imagenes' => [UploadedFile::fake()->image('vehiculo.jpg')],
        ]);

        $response->assertRedirect(route('vehiculos.create'));
        $this->assertDatabaseMissing('vehiculos', [
            'id_vehiculo' => 'FAKE9999',
        ]);
        $this->assertDatabaseHas('vehiculos', [
            'id_vehiculo' => 'PBA1234',
            'id_marca' => DB::table('marca_vehiculos')->where('nombre', 'Hyundai')->value('id_marca'),
            'id_clase' => DB::table('clase_vehiculos')->where('nombre', 'Suv')->value('id_clase'),
            'id_pais' => DB::table('paises')->where('nombre', 'Corea Del Sur')->value('id_pais'),
            'id_color' => DB::table('color_vehiculos')->where('nombre', 'Azul')->value('id_color'),
            'id_tipo_vehiculo' => DB::table('tipo_vehiculos')->where('nombre', 'SUV')->value('id_tipo_vehiculo'),
            'id_servicio' => DB::table('servicios')->where('nombre', 'PARTICULAR')->value('id_servicio'),
            'id_tipo_combustible' => $catalogIds['id_tipo_combustible'],
            'modelo' => 'Kona',
            'anio' => 2023,
            'motor' => 'MOTOR-AXIS-001',
            'chasis' => 'CHASIS-AXIS-001',
            'cilindraje' => '1600 cc',
            'costo_adquisicion' => 18000,
        ]);
        $this->assertDatabaseHas('validacion_documentos', [
            'id' => $validacion->id,
            'estado_revision' => 'registrada',
        ]);
        $this->assertDatabaseHas('documentos_vehiculos', [
            'id_vehiculo' => 'PBA1234',
            'ruta_matricula' => $rutaMatricula,
        ]);

        $imagenPublica = DB::table('imagen_vehiculos')
            ->where('id_vehiculo', 'PBA1234')
            ->value('ruta_imagen');
        $this->assertDatabaseHas('imagen_vehiculos', [
            'id_vehiculo' => 'PBA1234',
            'ruta_imagen' => $imagenPublica,
            'es_principal' => true,
        ]);
        Storage::disk('public')->assertExists($imagenPublica);
    }

    public function test_missing_axiscloud_cilindraje_can_be_filled_without_overriding_other_official_data(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $catalogIds = $this->createCatalogs();
        $user = User::factory()->create();
        $rutaMatricula = 'validacion-documentos/documento-incompleto.jpg';
        Storage::disk('local')->put($rutaMatricula, 'matricula');
        $validacion = ValidacionDocumento::create([
            'placa' => 'PBA1234',
            'ruta_documento' => $rutaMatricula,
            'mime_type' => 'image/jpeg',
            'datos_documento' => ['placa' => 'PBA1234'],
            'datos_oficiales' => [
                'placa' => 'PBA1234',
                'marca' => 'Hyundai',
                'clase' => 'SUV',
                'pais' => 'Corea del Sur',
                'color' => 'Azul',
                'modelo' => 'Kona',
                'anio' => '2023',
                'motor' => 'MOTOR-AXIS-CYL-001',
                'chasis' => 'CHASIS-AXIS-CYL-001',
                'cilindraje' => '',
                'tipo_vehiculo' => 'SUV',
                'servicio' => 'Particular',
            ],
            'comparacion' => [],
            'alertas_criticas' => 0,
            'estado_revision' => 'borrador',
            'subido_por' => $user->id,
        ]);

        $datosRegistro = [
            ...$catalogIds,
            'validacion_id' => $validacion->id,
            'id_vehiculo' => 'PBA1234',
            'modelo' => 'Valor manipulado',
            'anio' => 2024,
            'motor' => 'MOTOR-MANIPULADO',
            'chasis' => 'CHASIS-MANIPULADO',
            'costo_adquisicion' => 18000,
        ];

        $this->actingAs($user)
            ->from(route('vehiculos.create'))
            ->post(route('vehiculos.store'), $datosRegistro)
            ->assertSessionHasErrors('cilindraje');

        $this->assertDatabaseHas('validacion_documentos', [
            'id' => $validacion->id,
            'estado_revision' => 'borrador',
        ]);

        $this->assertDatabaseMissing('vehiculos', [
            'id_vehiculo' => 'PBA1234',
        ]);

        $response = $this->actingAs($user)->post(route('vehiculos.store'), [
            ...$datosRegistro,
            'cilindraje' => '1600 cc',
        ]);

        $response->assertRedirect(route('vehiculos.create'));
        $this->assertDatabaseHas('vehiculos', [
            'id_vehiculo' => 'PBA1234',
            'id_marca' => DB::table('marca_vehiculos')->where('nombre', 'Hyundai')->value('id_marca'),
            'id_clase' => DB::table('clase_vehiculos')->where('nombre', 'Suv')->value('id_clase'),
            'id_pais' => DB::table('paises')->where('nombre', 'Corea Del Sur')->value('id_pais'),
            'id_color' => DB::table('color_vehiculos')->where('nombre', 'Azul')->value('id_color'),
            'id_tipo_vehiculo' => DB::table('tipo_vehiculos')->where('nombre', 'SUV')->value('id_tipo_vehiculo'),
            'id_servicio' => DB::table('servicios')->where('nombre', 'PARTICULAR')->value('id_servicio'),
            'modelo' => 'Kona',
            'anio' => 2023,
            'motor' => 'MOTOR-AXIS-CYL-001',
            'chasis' => 'CHASIS-AXIS-CYL-001',
            'cilindraje' => '1600 cc',
        ]);
        $this->assertDatabaseHas('validacion_documentos', [
            'id' => $validacion->id,
            'estado_revision' => 'registrada',
        ]);
    }

    public function test_a_failed_ocr_document_can_be_sent_to_manual_review_and_approved(): void
    {
        Storage::fake('local');
        $catalogIds = $this->createCatalogs();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('vehiculos.store'), [
            ...$catalogIds,
            'id_vehiculo' => 'PBA-1234',
            'modelo' => 'Corolla',
            'anio' => 2024,
            'motor' => 'MOTOR-OCR-MANUAL-001',
            'chasis' => 'CHASIS-OCR-MANUAL-001',
            'cilindraje' => '1800 cc',
            'costo_adquisicion' => 18000,
            'comentario_solicitud' => 'La auditoría automática no estuvo disponible.',
            'documento_matricula' => UploadedFile::fake()->image('matricula.jpg'),
        ]);

        $validacion = ValidacionDocumento::query()->where('placa', 'PBA1234')->firstOrFail();

        $response->assertRedirect(route('validacion-documentos.show', $validacion));
        $this->assertSame('pendiente', $validacion->estado_revision);
        $this->assertSame('La auditoría automática no estuvo disponible.', $validacion->comentario_solicitud);
        $this->assertSame('PBA1234', $validacion->datos_ingreso['id_vehiculo']);
        $this->assertSame('ocr', $validacion->comparacion[0]['clave']);
        Storage::disk('local')->assertExists($validacion->ruta_documento);
        $this->assertDatabaseMissing('vehiculos', [
            'id_vehiculo' => 'PBA1234',
        ]);

        $this->actingAs(User::factory()->create())
            ->patch(route('validacion-documentos.decidir', $validacion), [
                'decision' => 'aprobada',
            ])
            ->assertRedirect(route('validacion-documentos.show', $validacion));

        $this->assertDatabaseHas('vehiculos', [
            'id_vehiculo' => 'PBA1234',
            'costo_adquisicion' => 18000,
        ]);
    }

    public function test_audited_vehicle_cannot_bypass_manual_review_and_enter_inventory(): void
    {
        $catalogIds = $this->createCatalogs();
        $user = User::factory()->create();
        ValidacionDocumento::create([
            'placa' => 'PBA1234',
            'ruta_documento' => 'validacion-documentos/documento.jpg',
            'mime_type' => 'image/jpeg',
            'datos_documento' => ['placa' => 'PBA1234'],
            'datos_oficiales' => ['placa' => 'PBA1234'],
            'comparacion' => [],
            'alertas_criticas' => 0,
            'estado_revision' => 'borrador',
            'subido_por' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->from(route('vehiculos.create'))
            ->post(route('vehiculos.store'), [
                ...$catalogIds,
                'id_vehiculo' => 'PBA-1234',
                'modelo' => 'Corolla',
                'anio' => 2024,
                'motor' => 'MOTOR-REVIEW-002',
                'chasis' => 'CHASIS-REVIEW-002',
                'cilindraje' => '1800 cc',
                'costo_adquisicion' => 18000,
            ]);

        $response->assertRedirectBack()
            ->assertSessionHasErrors('id_vehiculo');
        $this->assertDatabaseMissing('vehiculos', [
            'id_vehiculo' => 'PBA1234',
        ]);
    }

    public function test_only_the_uploader_can_send_an_audit_draft_for_review(): void
    {
        $catalogIds = $this->createCatalogs();
        $uploader = User::factory()->create();
        $validacion = ValidacionDocumento::create([
            'placa' => 'PBA1234',
            'ruta_documento' => 'validacion-documentos/documento.jpg',
            'mime_type' => 'image/jpeg',
            'datos_documento' => ['placa' => 'PBA1234'],
            'datos_oficiales' => ['placa' => 'PBA1234'],
            'comparacion' => [],
            'alertas_criticas' => 0,
            'estado_revision' => 'borrador',
            'subido_por' => $uploader->id,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('vehiculos.store'), [
                ...$catalogIds,
                'id_vehiculo' => 'PBA1234',
                'modelo' => 'Corolla',
                'anio' => 2024,
                'motor' => 'MOTOR-REVIEW-003',
                'chasis' => 'CHASIS-REVIEW-003',
                'cilindraje' => '1800 cc',
                'costo_adquisicion' => 18000,
                'validacion_id' => $validacion->id,
            ]);

        $response->assertNotFound();
        $this->assertDatabaseHas('validacion_documentos', [
            'id' => $validacion->id,
            'estado_revision' => 'borrador',
        ]);
        $this->assertDatabaseMissing('vehiculos', [
            'id_vehiculo' => 'PBA1234',
        ]);
    }

    public function test_authenticated_user_can_create_a_vehicle_without_optional_purchase_fields(): void
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
                'costo_adquisicion' => 18000,
            ]);

        $response->assertRedirect(route('vehiculos.create'));
        $this->assertDatabaseHas('vehiculos', [
            'id_vehiculo' => 'PBA-1234',
            'chasis' => 'CHASIS-TEST-001',
            'id_tipo_combustible' => $catalogIds['id_tipo_combustible'],
            'id_estado' => DB::table('estado_vehiculos')->where('nombre', 'Creado')->value('id_estado'),
            'id_patio' => null,
            'id_proveedor' => null,
            'kilometraje' => null,
            'precio_venta' => null,
            'id_tipo_vehiculo' => null,
            'id_servicio' => null,
        ]);

    }

    public function test_vehicle_type_and_service_values_are_reused_from_catalogs(): void
    {
        $catalogIds = $this->createCatalogs();
        $user = User::factory()->create();
        $vehicleData = [
            ...$catalogIds,
            'modelo' => 'Corolla',
            'anio' => 2024,
            'motor' => 'MOTOR-TEST-007',
            'chasis' => 'CHASIS-TEST-007',
            'cilindraje' => '1800 cc',
            'costo_adquisicion' => 18000,
            'tipo_vehiculo' => ' sedan ',
            'servicio' => ' uso particular ',
        ];

        $this->actingAs($user)->post(route('vehiculos.store'), [
            ...$vehicleData,
            'id_vehiculo' => 'PBF-6789',
        ])->assertRedirect(route('vehiculos.create'));

        $this->actingAs($user)->post(route('vehiculos.store'), [
            ...$vehicleData,
            'id_vehiculo' => 'PBG-7890',
            'motor' => 'MOTOR-TEST-008',
            'chasis' => 'CHASIS-TEST-008',
        ])->assertRedirect(route('vehiculos.create'));

        $tipoVehiculoId = DB::table('tipo_vehiculos')
            ->where('nombre', 'SEDAN')
            ->value('id_tipo_vehiculo');
        $servicioId = DB::table('servicios')
            ->where('nombre', 'USO PARTICULAR')
            ->value('id_servicio');

        $this->assertDatabaseCount('tipo_vehiculos', 1);
        $this->assertDatabaseCount('servicios', 1);
        $this->assertDatabaseHas('vehiculos', [
            'id_vehiculo' => 'PBF-6789',
            'id_tipo_vehiculo' => $tipoVehiculoId,
            'id_servicio' => $servicioId,
        ]);
        $this->assertDatabaseHas('vehiculos', [
            'id_vehiculo' => 'PBG-7890',
            'id_tipo_vehiculo' => $tipoVehiculoId,
            'id_servicio' => $servicioId,
        ]);

        $this->get(route('vehiculos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('vehiculos.0.tipo_vehiculo.nombre', 'SEDAN')
                ->where('vehiculos.0.servicio.nombre', 'USO PARTICULAR')
            );
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
        ];

        $ids = [];

        foreach ($catalogs as $table => [$primaryKey, $name]) {
            $ids[$primaryKey] = DB::table($table)->insertGetId([
                'nombre' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ], $primaryKey);
        }

        return $ids;
    }
}
