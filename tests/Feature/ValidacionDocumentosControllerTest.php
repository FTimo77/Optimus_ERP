<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\ValidacionDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ValidacionDocumentosControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_browse_the_review_queue(): void
    {
        $uploader = User::factory()->create();
        $validacion = $this->createValidacion($uploader);
        $this->createValidacion($uploader, ['estado_revision' => 'borrador']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('validacion-documentos.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ValidacionDocumentos/Index')
                ->where('pendientes', 1)
                ->where('validaciones.total', 1)
                ->where('validaciones.data.0.id', $validacion->id)
                ->where('validaciones.data.0.placa', 'PBA1234')
                ->where('validaciones.data.0.marca', 'Toyota')
                ->where('validaciones.data.0.subido_por', $uploader->name)
            );
    }

    public function test_authenticated_user_can_open_a_review_case(): void
    {
        $validacion = $this->createValidacion(User::factory()->create(), [
            'comentario_solicitud' => 'Revisar el costo declarado.',
            'ruta_cuv' => 'validacion-documentos/cuv/cuv.pdf',
            'datos_ingreso' => [
                'id_vehiculo' => 'PBA1234',
                'modelo' => 'Corolla',
                'costo_adquisicion' => 18000,
                'ultima_fecha_revision' => '20-03-2025 13:52:09',
                'lugar_revision' => 'Centro de revisión Quito Norte',
                'imagenes' => [],
            ],
            'datos_oficiales' => [
                'placa' => 'PBA1234',
                'fecha_ultima_revision_desde' => '20-03-2025 13:52:09',
            ],
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('validacion-documentos.show', $validacion));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ValidacionDocumentos/Show')
                ->where('validacion.id', $validacion->id)
                ->where('validacion.placa', 'PBA1234')
                ->where('validacion.estado_revision', 'pendiente')
                ->where('validacion.comparacion.0.estado', 'coincide')
                ->where('validacion.comentario_solicitud', 'Revisar el costo declarado.')
                ->where('validacion.puede_reenviar', false)
                ->where('validacion.datos_ingreso.costo_adquisicion', 18000)
                ->where('validacion.datos_ingreso.ultima_fecha_revision', '20-03-2025 13:52:09')
                ->where('validacion.datos_ingreso.lugar_revision', 'Centro de revisión Quito Norte')
                ->where('validacion.comparacion.1.clave', 'ultima_fecha_revision')
                ->where('validacion.comparacion.1.estado', 'coincide')
                ->where('validacion.comparacion.1.documento', '20-03-2025 13:52:09')
                ->where('validacion.comparacion.1.sistema_web', '20-03-2025 13:52:09')
                ->where('validacion.comparacion.2.clave', 'lugar_revision')
                ->where('validacion.comparacion.2.estado', 'informativo')
                ->where('validacion.comparacion.2.documento', 'Centro de revisión Quito Norte')
                ->where('validacion.documento_url', route('validacion-documentos.documento', $validacion))
                ->where('validacion.cuv_url', route('validacion-documentos.cuv', $validacion))
            );
    }

    public function test_review_case_marks_user_review_date_different_from_axiscloud(): void
    {
        $validacion = $this->createValidacion(User::factory()->create(), [
            'datos_ingreso' => [
                'id_vehiculo' => 'PBA1234',
                'ultima_fecha_revision' => '21-03-2025 13:52:09',
                'lugar_revision' => 'Centro de revisión Sur',
            ],
            'datos_oficiales' => [
                'placa' => 'PBA1234',
                'ultima_fecha_revision' => '20-03-2025 13:52:09',
            ],
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('validacion-documentos.show', $validacion))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('validacion.comparacion.1.clave', 'ultima_fecha_revision')
                ->where('validacion.comparacion.1.estado', 'diferente')
                ->where('validacion.comparacion.1.documento', '21-03-2025 13:52:09')
                ->where('validacion.comparacion.1.sistema_web', '20-03-2025 13:52:09')
                ->where('validacion.comparacion.2.clave', 'lugar_revision')
                ->where('validacion.comparacion.2.estado', 'informativo')
                ->where('validacion.comparacion.2.documento', 'Centro de revisión Sur')
            );
    }

    public function test_a_draft_is_hidden_from_other_users(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('validacion-documentos/borrador.jpg', 'matricula privada');
        $borrador = $this->createValidacion(User::factory()->create(), [
            'estado_revision' => 'borrador',
            'ruta_documento' => 'validacion-documentos/borrador.jpg',
        ]);
        $otroUsuario = User::factory()->create();

        $this->actingAs($otroUsuario)
            ->get(route('validacion-documentos.show', $borrador))
            ->assertNotFound();
        $this->actingAs($otroUsuario)
            ->get(route('validacion-documentos.documento', $borrador))
            ->assertNotFound();
    }

    public function test_authenticated_user_can_download_the_private_registration_document(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('validacion-documentos/documento.jpg', 'matricula privada');
        $validacion = $this->createValidacion(User::factory()->create(), [
            'ruta_documento' => 'validacion-documentos/documento.jpg',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('validacion-documentos.documento', $validacion));

        $response->assertDownload('matricula_PBA1234.jpg')
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertStreamedContent('matricula privada');
    }

    public function test_authenticated_user_can_download_the_private_cuv(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('validacion-documentos/cuv/cuv.pdf', 'cuv privado');
        $validacion = $this->createValidacion(User::factory()->create(), [
            'ruta_cuv' => 'validacion-documentos/cuv/cuv.pdf',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('validacion-documentos.cuv', $validacion))
            ->assertDownload('cuv_PBA1234.pdf')
            ->assertStreamedContent('cuv privado');
    }

    public function test_authenticated_user_can_approve_a_document_with_a_comment(): void
    {
        Storage::fake('local');
        $catalogIds = $this->createCatalogs();
        $reviewer = User::factory()->create();
        $validacion = $this->createValidacion(User::factory()->create(), [
            'datos_ingreso' => [
                ...$catalogIds,
                'id_vehiculo' => 'PBA1234',
                'modelo' => 'Corolla',
                'anio' => 2024,
                'motor' => 'MOTOR-APPROVED-001',
                'chasis' => 'CHASIS-APPROVED-001',
                'cilindraje' => '1800 cc',
                'costo_adquisicion' => 18000,
                'imagenes' => [],
            ],
        ]);

        $response = $this->actingAs($reviewer)
            ->patch(route('validacion-documentos.decidir', $validacion), [
                'decision' => 'aprobada',
                'comentario_revision' => 'Datos verificados contra el documento.',
                'documento_cuv' => UploadedFile::fake()->create('cuv.pdf', 100, 'application/pdf'),
            ]);

        $response->assertRedirect(route('validacion-documentos.show', $validacion));
        $this->assertDatabaseHas('validacion_documentos', [
            'id' => $validacion->id,
            'estado_revision' => 'pendiente_comprobante',
            'comentario_revision' => 'Datos verificados contra el documento.',
            'revisado_por' => $reviewer->id,
        ]);
        $this->assertNotNull($validacion->fresh()->revisado_en);
        $this->assertDatabaseMissing('vehiculos', [
            'id_vehiculo' => 'PBA1234',
        ]);
        $rutaCuv = $validacion->fresh()->ruta_cuv;
        $this->assertIsString($rutaCuv);
        Storage::disk('local')->assertExists($rutaCuv);

        $uploadResponse = $this->actingAs($reviewer)
            ->from(route('validacion-documentos.show', $validacion))
            ->post(route('validacion-documentos.comprobante.store', $validacion), [
                'documento_comprobante' => UploadedFile::fake()->create('comprobante.pdf', 100, 'application/pdf'),
            ]);

        $uploadResponse->assertRedirect(route('validacion-documentos.show', $validacion));
        $validacion->refresh();
        $this->assertSame('aprobada', $validacion->estado_revision);
        $this->assertNotNull($validacion->ruta_comprobante_pago);
        Storage::disk('local')->assertExists($validacion->ruta_comprobante_pago);
        $this->assertDatabaseHas('vehiculos', [
            'id_vehiculo' => 'PBA1234',
            'id_marca' => $catalogIds['id_marca'],
            'modelo' => 'Corolla',
            'id_estado' => DB::table('estado_vehiculos')->where('nombre', 'Creado')->value('id_estado'),
        ]);
        $this->assertDatabaseHas('documentos_vehiculos', [
            'id_vehiculo' => 'PBA1234',
            'ruta_cuv' => $rutaCuv,
            'ruta_comprobante_pago' => $validacion->ruta_comprobante_pago,
        ]);
    }

    public function test_authenticated_user_can_reject_a_document_without_a_comment(): void
    {
        Storage::fake('local');
        $reviewer = User::factory()->create();
        $validacion = $this->createValidacion(User::factory()->create());

        $response = $this->actingAs($reviewer)
            ->post(route('validacion-documentos.decidir', $validacion), [
                '_method' => 'PATCH',
                'decision' => 'rechazada',
                'comentario_revision' => '   ',
                'documento_cuv' => UploadedFile::fake()->create('cuv.pdf', 100, 'application/pdf'),
            ]);

        $response->assertRedirect(route('validacion-documentos.show', $validacion));
        $this->assertDatabaseHas('validacion_documentos', [
            'id' => $validacion->id,
            'estado_revision' => 'rechazada',
            'comentario_revision' => null,
            'revisado_por' => $reviewer->id,
        ]);
        $this->assertDatabaseMissing('vehiculos', [
            'id_vehiculo' => $validacion->placa,
        ]);
        $rutaCuv = $validacion->fresh()->ruta_cuv;
        $this->assertIsString($rutaCuv);
        Storage::disk('local')->assertExists($rutaCuv);
        $this->assertDatabaseMissing('documentos_vehiculos', [
            'id_vehiculo' => $validacion->placa,
        ]);
    }

    public function test_review_decision_rejects_an_invalid_cuv_file(): void
    {
        Storage::fake('local');
        $validacion = $this->createValidacion(User::factory()->create());

        $response = $this->actingAs(User::factory()->create())
            ->from(route('validacion-documentos.show', $validacion))
            ->patch(route('validacion-documentos.decidir', $validacion), [
                'decision' => 'rechazada',
                'documento_cuv' => UploadedFile::fake()->create('cuv.txt', 100, 'text/plain'),
            ]);

        $response->assertRedirectBack()
            ->assertSessionHasErrors('documento_cuv');
        $this->assertDatabaseHas('validacion_documentos', [
            'id' => $validacion->id,
            'estado_revision' => 'pendiente',
            'ruta_cuv' => null,
        ]);
        Storage::disk('local')->assertEmpty();
    }

    public function test_submitter_can_resubmit_a_rejected_request_with_a_comment(): void
    {
        $submitter = User::factory()->create();
        $reviewer = User::factory()->create();
        $validacion = $this->createValidacion($submitter, [
            'estado_revision' => 'rechazada',
            'comentario_revision' => 'Verifica el número de chasis.',
            'revisado_por' => $reviewer->id,
            'revisado_en' => now()->subMinute(),
        ]);

        $this->actingAs($submitter)
            ->get(route('validacion-documentos.show', $validacion))
            ->assertInertia(fn (Assert $page) => $page
                ->where('validacion.estado_revision', 'rechazada')
                ->where('validacion.puede_reenviar', true)
                ->where('validacion.comentario_revision', 'Verifica el número de chasis.')
            );

        $response = $this->actingAs($submitter)
            ->post(route('validacion-documentos.reenviar', $validacion), [
                'comentario_reenvio' => 'Adjunto la información corregida del chasis.',
            ]);

        $response->assertRedirect(route('validacion-documentos.show', $validacion));
        $this->assertDatabaseHas('validacion_documentos', [
            'id' => $validacion->id,
            'estado_revision' => 'pendiente',
            'comentario_reenvio' => 'Adjunto la información corregida del chasis.',
            'comentario_revision' => 'Verifica el número de chasis.',
            'revisado_por' => null,
            'revisado_en' => null,
        ]);
        $this->assertDatabaseMissing('vehiculos', [
            'id_vehiculo' => $validacion->placa,
        ]);
    }

    public function test_only_the_original_submitter_can_resubmit_a_rejected_request(): void
    {
        $validacion = $this->createValidacion(User::factory()->create(), [
            'estado_revision' => 'rechazada',
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('validacion-documentos.reenviar', $validacion), [
                'comentario_reenvio' => 'Intento reenviar el expediente.',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('validacion_documentos', [
            'id' => $validacion->id,
            'estado_revision' => 'rechazada',
        ]);
    }

    public function test_resubmission_requires_a_non_empty_comment(): void
    {
        $submitter = User::factory()->create();
        $validacion = $this->createValidacion($submitter, [
            'estado_revision' => 'rechazada',
        ]);

        $response = $this->actingAs($submitter)
            ->from(route('validacion-documentos.show', $validacion))
            ->post(route('validacion-documentos.reenviar', $validacion), [
                'comentario_reenvio' => '   ',
            ]);

        $response->assertRedirectBack()
            ->assertSessionHasErrors('comentario_reenvio');
        $this->assertDatabaseHas('validacion_documentos', [
            'id' => $validacion->id,
            'estado_revision' => 'rechazada',
            'comentario_reenvio' => null,
        ]);
    }

    public function test_a_case_without_acquisition_data_cannot_be_approved(): void
    {
        $validacion = $this->createValidacion(User::factory()->create());

        $response = $this->actingAs(User::factory()->create())
            ->from(route('validacion-documentos.show', $validacion))
            ->patch(route('validacion-documentos.decidir', $validacion), [
                'decision' => 'aprobada',
            ]);

        $response->assertRedirectBack()
            ->assertSessionHasErrors('decision');
        $this->assertDatabaseHas('validacion_documentos', [
            'id' => $validacion->id,
            'estado_revision' => 'pendiente',
        ]);
        $this->assertDatabaseMissing('vehiculos', [
            'id_vehiculo' => $validacion->placa,
        ]);
    }

    public function test_a_reviewed_case_cannot_be_decided_again(): void
    {
        $validacion = $this->createValidacion(User::factory()->create(), [
            'estado_revision' => 'aprobada',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->patch(route('validacion-documentos.decidir', $validacion), [
                'decision' => 'rechazada',
            ]);

        $response->assertConflict();
        $this->assertDatabaseHas('validacion_documentos', [
            'id' => $validacion->id,
            'estado_revision' => 'aprobada',
        ]);
    }

    public function test_decision_must_be_approved_or_rejected(): void
    {
        $validacion = $this->createValidacion(User::factory()->create());

        $response = $this->actingAs(User::factory()->create())
            ->from(route('validacion-documentos.show', $validacion))
            ->patch(route('validacion-documentos.decidir', $validacion), [
                'decision' => 'pendiente',
            ]);

        $response->assertRedirectBack()
            ->assertSessionHasErrors('decision');
        $this->assertDatabaseHas('validacion_documentos', [
            'id' => $validacion->id,
            'estado_revision' => 'pendiente',
        ]);
    }

    public function test_review_routes_require_authentication(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('validacion-documentos/documento.jpg', 'matricula privada');
        $validacion = $this->createValidacion(User::factory()->create(), [
            'ruta_documento' => 'validacion-documentos/documento.jpg',
        ]);

        $this->get(route('validacion-documentos.index'))
            ->assertRedirect(route('login'));
        $this->get(route('validacion-documentos.show', $validacion))
            ->assertRedirect(route('login'));
        $this->get(route('validacion-documentos.documento', $validacion))
            ->assertRedirect(route('login'));
        $this->get(route('validacion-documentos.cuv', $validacion))
            ->assertRedirect(route('login'));
        $this->patch(route('validacion-documentos.decidir', $validacion), [
            'decision' => 'aprobada',
        ])->assertRedirect(route('login'));
        $this->post(route('validacion-documentos.escanear.cuv', $validacion), [
            'documento_cuv' => UploadedFile::fake()->create('cuv.pdf', 100, 'application/pdf'),
        ])->assertRedirect(route('login'));
    }

    public function test_reviewer_can_scan_cuv_and_compare_it_with_the_scanned_matricula_fields(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config([
            'services.gemini.api_key' => 'test-gemini-api-key',
            'services.gemini.model' => 'gemini-3.5-flash-lite',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'numero_cuv' => 'CUV-2026-00597334',
                                'placa' => 'PBA-1234',
                                'chasis' => '9BWZZZ377VT004251',
                                'motor' => '1NZFE123456',
                                'marca' => 'TOYOTA',
                                'modelo' => 'COROLLA SEDAN',
                                'anio' => '2022',
                                'anio_expedicion' => '2025',
                                'fecha_emision' => '15-03-2025',
                                'fecha_caduca' => '15-03-2026',
                                'ultima_fecha_revision' => '16-06-2026',
                                'lugar_revision' => 'GAD JIPIJAPA - MANABI',
                                'color' => 'BLANCO',
                                'clase' => 'AUTOMOVIL',
                                'servicio' => 'PARTICULAR',
                                'propietario' => 'JUAN CARLOS PEREZ LOPEZ',
                                'cedula_propietario' => 'CED - 1712345678',
                                'gravamenes_vigentes' => 'NO TIENE REGISTRADOS.',
                                'tiene_gravamenes' => false,
                                'bloqueos_vigentes' => 'NO TIENE REGISTRADOS.',
                                'tiene_bloqueos' => false,
                                'cantidad_propietarios' => 1,
                                'tiene_mas_de_un_dueno' => false,
                                'historia_dominio' => [[
                                    'desde' => '28-JUL-2021',
                                    'hasta' => 'NO REGISTRADO',
                                    'documento_identidad' => 'CED - 1712345678',
                                    'nombre' => 'PEREZ LOPEZ JUAN CARLOS',
                                    'institucion' => 'CAY-GAD CAYAMBE - PICHINCHA',
                                ]],
                            ], JSON_THROW_ON_ERROR),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $validacion = $this->createValidacion(User::factory()->create(), [
            'datos_documento' => [
                'placa' => 'PBA1234',
                'chasis' => '9BWZZZ377VT004251',
                'motor' => '1NZFE123456',
                'marca' => 'TOYOTA',
                'modelo' => 'COROLLA',
                'anio' => '2022',
                'anio_expedicion' => '2025',
                'fecha_emision' => '15/03/2025',
                'fecha_caduca' => '15/03/2026',
                'color' => 'BLANCO',
                'clase' => 'AUTOMOVIL',
                'servicio' => 'USO PARTICULAR',
                'propietario' => 'PEREZ LOPEZ JUAN CARLOS',
                'cedula_propietario' => '1712345678',
            ],
            'datos_ingreso' => [
                'id_vehiculo' => 'PBA1234',
                'ultima_fecha_revision' => '16-06-2026 13:52:09',
                'lugar_revision' => 'GAD JIPIJAPA - MANABI',
            ],
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('validacion-documentos.escanear.cuv', $validacion), [
                'documento_cuv' => UploadedFile::fake()->create('cuv.pdf', 120, 'application/pdf'),
            ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('alertas_criticas', 0)
            ->assertJsonPath('alertas_cuv.tiene_alertas', false)
            ->assertJsonPath('alertas_cuv.bloqueos.tiene', false)
            ->assertJsonPath('alertas_cuv.gravamenes.tiene', false)
            ->assertJsonPath('alertas_cuv.propietarios.tiene_mas_de_un_dueno', false)
            ->assertJsonPath('datos_cuv.placa', 'PBA1234')
            ->assertJsonPath('comparacion.0.clave', 'placa')
            ->assertJsonPath('comparacion.0.estado', 'coincide')
            ->assertJsonPath('comparacion.1.clave', 'propietario')
            ->assertJsonPath('comparacion.1.estado', 'coincide')
            ->assertJsonPath('comparacion.2.clave', 'cedula_propietario')
            ->assertJsonPath('comparacion.2.estado', 'coincide')
            ->assertJsonPath('comparacion.3.clave', 'chasis')
            ->assertJsonPath('comparacion.3.estado', 'coincide')
            ->assertJsonPath('comparacion.13.clave', 'ultima_fecha_revision')
            ->assertJsonPath('comparacion.13.origen', 'formulario_ingreso')
            ->assertJsonPath('comparacion.13.estado', 'coincide')
            ->assertJsonPath('comparacion.14.clave', 'lugar_revision')
            ->assertJsonPath('comparacion.14.origen', 'formulario_ingreso')
            ->assertJsonPath('comparacion.14.estado', 'coincide');

        $validacionActualizada = $validacion->fresh();
        $this->assertNotNull($validacionActualizada->ruta_cuv);
        Storage::disk('local')->assertExists($validacionActualizada->ruta_cuv);
        $this->assertSame(0, $validacionActualizada->resumen_auditoria['cuv']['alertas_criticas']);
    }

    public function test_cuv_scan_flags_critical_discrepancies_and_legal_domain_alerts(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config([
            'services.gemini.api_key' => 'test-gemini-api-key',
            'services.gemini.model' => 'gemini-3.5-flash-lite',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'placa' => 'PBA1234',
                                'chasis' => 'CHASIS-DIFERENTE-999',
                                'motor' => 'MOTOR-DIFERENTE-999',
                                'marca' => 'TOYOTA',
                                'modelo' => 'COROLLA',
                                'anio' => '2022',
                                'ultima_fecha_revision' => '16-06-2026',
                                'lugar_revision' => 'GAD JIPIJAPA - MANABI',
                                'propietario' => 'OTRO PROPIETARIO DISTINTO',
                                'cedula_propietario' => '0999999999',
                                'gravamenes_vigentes' => 'PRENDA COMERCIAL BANCO PICHINCHA',
                                'tiene_gravamenes' => true,
                                'bloqueos_vigentes' => 'PROHIBICION DE ENAJENAR JUDICIAL',
                                'tiene_bloqueos' => true,
                                'cantidad_propietarios' => 2,
                                'tiene_mas_de_un_dueno' => true,
                                'historia_dominio' => [
                                    [
                                        'desde' => '10-ENE-2019',
                                        'hasta' => '27-JUL-2021',
                                        'documento_identidad' => 'CED - 1700000001',
                                        'nombre' => 'PRIMER DUENO HISTORICO',
                                        'institucion' => 'AMT QUITO',
                                    ],
                                    [
                                        'desde' => '28-JUL-2021',
                                        'hasta' => 'NO REGISTRADO',
                                        'documento_identidad' => 'CED - 0999999999',
                                        'nombre' => 'OTRO PROPIETARIO DISTINTO',
                                        'institucion' => 'GAD JIPIJAPA',
                                    ],
                                ],
                            ], JSON_THROW_ON_ERROR),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $validacion = $this->createValidacion(User::factory()->create(), [
            'datos_documento' => [
                'placa' => 'PBA1234',
                'chasis' => '9BWZZZ377VT004251',
                'motor' => '1NZFE123456',
                'marca' => 'TOYOTA',
                'modelo' => 'COROLLA',
                'anio' => '2022',
                'propietario' => 'JUAN CARLOS PEREZ LOPEZ',
                'cedula_propietario' => '1712345678',
            ],
            'datos_ingreso' => [
                'id_vehiculo' => 'PBA1234',
                'ultima_fecha_revision' => '01-01-2025 10:00:00',
                'lugar_revision' => 'CENTRO DE REVISION QUITO NORTE',
            ],
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('validacion-documentos.escanear.cuv', $validacion), [
                'documento_cuv' => UploadedFile::fake()->image('cuv.jpg'),
            ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('alertas_criticas', 9)
            ->assertJsonPath('alertas_cuv.tiene_alertas', true)
            ->assertJsonPath('alertas_cuv.bloqueos.tiene', true)
            ->assertJsonPath('alertas_cuv.gravamenes.tiene', true)
            ->assertJsonPath('alertas_cuv.propietarios.tiene_mas_de_un_dueno', true)
            ->assertJsonPath('alertas_cuv.propietarios.cantidad', 2)
            ->assertJsonPath('comparacion.1.clave', 'propietario')
            ->assertJsonPath('comparacion.1.estado', 'diferente')
            ->assertJsonPath('comparacion.2.clave', 'cedula_propietario')
            ->assertJsonPath('comparacion.2.estado', 'diferente')
            ->assertJsonPath('comparacion.3.clave', 'chasis')
            ->assertJsonPath('comparacion.3.estado', 'diferente')
            ->assertJsonPath('comparacion.4.clave', 'motor')
            ->assertJsonPath('comparacion.4.estado', 'diferente')
            ->assertJsonPath('comparacion.13.clave', 'ultima_fecha_revision')
            ->assertJsonPath('comparacion.13.estado', 'diferente')
            ->assertJsonPath('comparacion.14.clave', 'lugar_revision')
            ->assertJsonPath('comparacion.14.estado', 'diferente');
    }

    public function test_authenticated_user_can_upload_payment_receipt_from_show_to_create_vehicle(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $catalogs = $this->createCatalogs();
        $uploader = User::factory()->create();
        $reviewer = User::factory()->create();

        $validacion = $this->createValidacion($uploader, [
            'estado_revision' => 'pendiente_comprobante',
            'revisado_por' => $reviewer->id,
            'revisado_en' => now(),
            'datos_ingreso' => array_merge($catalogs, [
                'id_vehiculo' => 'PBA1234',
                'modelo' => 'Corolla',
                'anio' => 2024,
                'motor' => 'MTR123456',
                'chasis' => 'CHS123456',
                'cilindraje' => '1800',
                'costo_adquisicion' => 18000,
                'precio_venta' => 21500,
                'imagenes' => [],
            ]),
        ]);

        $tempImage = "validacion-documentos/{$validacion->id}/imagenes/foto1.jpg";
        Storage::disk('local')->put($tempImage, 'fake-image-content');
        $datosIngreso = $validacion->datos_ingreso;
        $datosIngreso['imagenes'] = [$tempImage];
        $validacion->update(['datos_ingreso' => $datosIngreso]);

        $uploadResponse = $this->actingAs($reviewer)
            ->from(route('validacion-documentos.show', $validacion))
            ->post(route('validacion-documentos.comprobante.store', $validacion), [
                'documento_comprobante' => UploadedFile::fake()->create('comprobante.pdf', 120, 'application/pdf'),
            ]);

        $uploadResponse->assertRedirect(route('validacion-documentos.show', $validacion));

        $validacion->refresh();
        $this->assertSame('aprobada', $validacion->estado_revision);
        $this->assertNotNull($validacion->ruta_comprobante_pago);
        Storage::disk('local')->assertExists($validacion->ruta_comprobante_pago);
        Storage::disk('local')->assertMissing($tempImage);
        Storage::disk('public')->assertExists('vehiculos/PBA1234/foto1.jpg');

        $this->assertDatabaseHas('vehiculos', [
            'id_vehiculo' => 'PBA1234',
            'modelo' => 'Corolla',
        ]);

        $this->assertDatabaseHas('documentos_vehiculos', [
            'id_vehiculo' => 'PBA1234',
            'ruta_matricula' => 'validacion-documentos/documento.jpg',
            'ruta_comprobante_pago' => $validacion->ruta_comprobante_pago,
        ]);

        $downloadResponse = $this->actingAs($reviewer)
            ->get(route('validacion-documentos.comprobante', $validacion));

        $downloadResponse->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createValidacion(User $uploader, array $attributes = []): ValidacionDocumento
    {
        return ValidacionDocumento::create(array_merge([
            'placa' => 'PBA1234',
            'ruta_documento' => 'validacion-documentos/documento.jpg',
            'mime_type' => 'image/jpeg',
            'datos_documento' => [
                'placa' => 'PBA1234',
                'marca' => 'Toyota',
                'modelo' => 'Corolla',
            ],
            'datos_oficiales' => [
                'placa' => 'PBA1234',
                'marca' => 'Toyota',
            ],
            'comparacion' => [
                [
                    'campo' => 'Placa',
                    'clave' => 'placa',
                    'documento' => 'PBA1234',
                    'sistema_web' => 'PBA1234',
                    'estado' => 'coincide',
                    'nota' => '',
                ],
            ],
            'alertas_criticas' => 0,
            'estado_revision' => 'pendiente',
            'subido_por' => $uploader->id,
        ], $attributes));
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
