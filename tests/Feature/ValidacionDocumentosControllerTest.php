<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\ValidacionDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $response = $this->actingAs(User::factory()->create())
            ->get(route('validacion-documentos.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ValidacionDocumentos/Index')
                ->where('pendientes', 1)
                ->where('validaciones.data.0.id', $validacion->id)
                ->where('validaciones.data.0.placa', 'PBA1234')
                ->where('validaciones.data.0.marca', 'Toyota')
                ->where('validaciones.data.0.subido_por', $uploader->name)
            );
    }

    public function test_authenticated_user_can_open_a_review_case(): void
    {
        $validacion = $this->createValidacion(User::factory()->create());

        $response = $this->actingAs(User::factory()->create())
            ->get(route('validacion-documentos.show', $validacion));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ValidacionDocumentos/Show')
                ->where('validacion.id', $validacion->id)
                ->where('validacion.placa', 'PBA1234')
                ->where('validacion.estado_revision', 'pendiente')
                ->where('validacion.comparacion.0.estado', 'coincide')
                ->where('validacion.documento_url', route('validacion-documentos.documento', $validacion))
            );
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

    public function test_authenticated_user_can_approve_a_document_with_a_comment(): void
    {
        $reviewer = User::factory()->create();
        $validacion = $this->createValidacion(User::factory()->create());

        $response = $this->actingAs($reviewer)
            ->patch(route('validacion-documentos.decidir', $validacion), [
                'decision' => 'aprobada',
                'comentario_revision' => 'Datos verificados contra el documento.',
            ]);

        $response->assertRedirect(route('validacion-documentos.show', $validacion));
        $this->assertDatabaseHas('validacion_documentos', [
            'id' => $validacion->id,
            'estado_revision' => 'aprobada',
            'comentario_revision' => 'Datos verificados contra el documento.',
            'revisado_por' => $reviewer->id,
        ]);
        $this->assertNotNull($validacion->fresh()->revisado_en);
    }

    public function test_authenticated_user_can_reject_a_document_without_a_comment(): void
    {
        $reviewer = User::factory()->create();
        $validacion = $this->createValidacion(User::factory()->create());

        $response = $this->actingAs($reviewer)
            ->patch(route('validacion-documentos.decidir', $validacion), [
                'decision' => 'rechazada',
                'comentario_revision' => '   ',
            ]);

        $response->assertRedirect(route('validacion-documentos.show', $validacion));
        $this->assertDatabaseHas('validacion_documentos', [
            'id' => $validacion->id,
            'estado_revision' => 'rechazada',
            'comentario_revision' => null,
            'revisado_por' => $reviewer->id,
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
        $this->patch(route('validacion-documentos.decidir', $validacion), [
            'decision' => 'aprobada',
        ])->assertRedirect(route('login'));
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
}
