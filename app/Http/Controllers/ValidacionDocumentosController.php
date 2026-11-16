<?php

namespace App\Http\Controllers;

use App\Models\Documentos_vehiculo;
use App\Models\estado_vehiculo as EstadoVehiculo;
use App\Models\imagen_vehiculo as ImagenVehiculo;
use App\Models\Servicio;
use App\Models\TipoVehiculo;
use App\Models\ValidacionDocumento;
use App\Models\vehiculo as Vehiculo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ValidacionDocumentosController extends Controller
{
    public function index(): Response
    {
        $validaciones = ValidacionDocumento::query()
            ->where('estado_revision', '!=', 'borrador')
            ->with('subidor:id,name')
            ->latest()
            ->paginate(15)
            ->through(fn (ValidacionDocumento $validacion): array => [
                'id' => $validacion->id,
                'placa' => $validacion->placa,
                'marca' => $validacion->datos_documento['marca'] ?? 'No detectada',
                'modelo' => $validacion->datos_documento['modelo'] ?? 'No detectado',
                'estado_revision' => $validacion->estado_revision,
                'alertas_criticas' => $validacion->alertas_criticas,
                'subido_por' => $validacion->subidor?->name ?? 'Usuario eliminado',
                'created_at' => $validacion->created_at?->toIso8601String(),
            ]);

        return Inertia::render('ValidacionDocumentos/Index', [
            'validaciones' => $validaciones,
            'pendientes' => ValidacionDocumento::query()->where('estado_revision', 'pendiente')->count(),
        ]);
    }

    public function show(Request $request, ValidacionDocumento $validacion): Response
    {
        abort_unless($validacion->estado_revision !== 'borrador', 404);

        $validacion->load(['subidor:id,name', 'revisor:id,name']);

        $datosIngresoOriginal = $validacion->datos_ingreso;
        $datosIngresoParaVista = null;

        if (is_array($datosIngresoOriginal)) {
            $datosIngresoParaVista = Arr::except($datosIngresoOriginal, ['imagenes']);

            $catalogos = [
                'id_marca' => ['tabla' => 'marca_vehiculos', 'columna_id' => 'id_marca'],
                'id_clase' => ['tabla' => 'clase_vehiculos', 'columna_id' => 'id_clase'],
                'id_pais' => ['tabla' => 'paises', 'columna_id' => 'id_pais'],
                'id_color' => ['tabla' => 'color_vehiculos', 'columna_id' => 'id_color'],
                'id_tipo_combustible' => ['tabla' => 'tipo_combustibles', 'columna_id' => 'id_tipo_combustible'],
                'id_transmision' => ['tabla' => 'transmisiones', 'columna_id' => 'id_transmision'],
            ];

            foreach ($catalogos as $campo => $catalogo) {
                $id = $datosIngresoOriginal[$campo] ?? null;

                if ($id === null || $id === '') {
                    continue;
                }

                $nombre = DB::table($catalogo['tabla'])
                    ->where($catalogo['columna_id'], $id)
                    ->value('nombre');

                $datosIngresoParaVista[$campo] = $nombre ?? 'No disponible';
            }
        }

        return Inertia::render('ValidacionDocumentos/Show', [
            'validacion' => [
                'id' => $validacion->id,
                'placa' => $validacion->placa,
                'estado_revision' => $validacion->estado_revision,
                'datos_documento' => $validacion->datos_documento,
                'datos_oficiales' => $validacion->datos_oficiales,
                'comparacion' => $validacion->comparacion,
                'resumen_auditoria' => $validacion->resumen_auditoria,
                'datos_ingreso' => $datosIngresoParaVista,
                'alertas_criticas' => $validacion->alertas_criticas,
                'comentario_solicitud' => $validacion->comentario_solicitud,
                'comentario_reenvio' => $validacion->comentario_reenvio,
                'comentario_revision' => $validacion->comentario_revision,
                'puede_reenviar' => $validacion->estado_revision === 'rechazada'
                    && $validacion->subido_por === $request->user()->id,
                'documento_url' => route('validacion-documentos.documento', $validacion),
                'cuv_url' => $validacion->ruta_cuv === null
                    ? null
                    : route('validacion-documentos.cuv', $validacion),
                'subido_por' => $validacion->subidor?->name ?? 'Usuario eliminado',
                'revisado_por' => $validacion->revisor?->name,
                'created_at' => $validacion->created_at?->toIso8601String(),
                'revisado_en' => $validacion->revisado_en?->toIso8601String(),
            ],
            'success' => session('success'),
        ]);
    }

    public function documento(ValidacionDocumento $validacion): StreamedResponse
    {
        abort_unless($validacion->estado_revision !== 'borrador', 404);

        $disk = Storage::disk('local');

        abort_unless($disk->exists($validacion->ruta_documento), 404);

        $extension = pathinfo($validacion->ruta_documento, PATHINFO_EXTENSION) ?: 'bin';

        return $disk->download(
            $validacion->ruta_documento,
            "matricula_{$validacion->placa}.{$extension}",
            [
                'Content-Type' => $validacion->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function cuv(ValidacionDocumento $validacion): StreamedResponse
    {
        abort_unless($validacion->estado_revision !== 'borrador', 404);
        abort_unless($validacion->ruta_cuv !== null, 404);

        $disk = Storage::disk('local');

        abort_unless($disk->exists($validacion->ruta_cuv), 404);

        $extension = pathinfo($validacion->ruta_cuv, PATHINFO_EXTENSION) ?: 'bin';

        return $disk->download(
            $validacion->ruta_cuv,
            "cuv_{$validacion->placa}.{$extension}",
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function decidir(Request $request, ValidacionDocumento $validacion): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['aprobada', 'rechazada'])],
            'comentario_revision' => ['nullable', 'string', 'max:2000'],
            'documento_cuv' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);
        $documentoCuv = $validated['documento_cuv'] ?? null;
        unset($validated['documento_cuv']);

        $rutaCuvTemporal = $documentoCuv?->store(
            "validacion-documentos/{$validacion->id}/cuv",
            'local',
        );

        if ($documentoCuv !== null && ! is_string($rutaCuvTemporal)) {
            throw ValidationException::withMessages([
                'documento_cuv' => 'No se pudo guardar el CUV. Inténtalo nuevamente.',
            ]);
        }

        $comentario = trim($validated['comentario_revision'] ?? '');
        $rutasImagenesPublicas = [];
        $rutasImagenesTemporales = [];
        $rutaCuvAnterior = $validacion->ruta_cuv;

        try {
            DB::transaction(function () use (
                $request,
                $validacion,
                $validated,
                $comentario,
                $rutaCuvTemporal,
                &$rutasImagenesPublicas,
                &$rutasImagenesTemporales,
            ): void {
                $validacionBloqueada = ValidacionDocumento::query()
                    ->whereKey($validacion->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless(
                    $validacionBloqueada->estado_revision === 'pendiente',
                    409,
                    'Este expediente ya fue revisado.',
                );

                if ($rutaCuvTemporal !== null) {
                    $validacionBloqueada->update(['ruta_cuv' => $rutaCuvTemporal]);
                }

                if ($validated['decision'] === 'aprobada') {
                    $rutasImagenesTemporales = $this->crearVehiculoDesdeExpediente(
                        $validacionBloqueada,
                        $rutasImagenesPublicas,
                    );
                }

                $validacionBloqueada->update([
                    'estado_revision' => $validated['decision'],
                    'comentario_revision' => $comentario === '' ? null : $comentario,
                    'revisado_por' => $request->user()->id,
                    'revisado_en' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            if ($rutasImagenesPublicas !== []
                && ! Storage::disk('public')->delete($rutasImagenesPublicas)) {
                Log::error('Could not remove vehicle images after a failed review approval.', [
                    'validacion_id' => $validacion->id,
                    'image_paths' => $rutasImagenesPublicas,
                ]);
            }

            if ($rutaCuvTemporal !== null && ! Storage::disk('local')->delete($rutaCuvTemporal)) {
                Log::warning('A newly uploaded CUV could not be removed after a failed review decision.', [
                    'validacion_id' => $validacion->id,
                    'file_path' => $rutaCuvTemporal,
                ]);
            }

            throw $exception;
        }

        if ($rutaCuvTemporal !== null
            && $rutaCuvAnterior !== null
            && $rutaCuvAnterior !== $rutaCuvTemporal
            && ! Storage::disk('local')->delete($rutaCuvAnterior)) {
            Log::warning('A replaced CUV could not be removed from temporary storage.', [
                'validacion_id' => $validacion->id,
                'file_path' => $rutaCuvAnterior,
            ]);
        }

        if ($rutasImagenesTemporales !== []
            && ! Storage::disk('local')->delete($rutasImagenesTemporales)) {
            Log::warning('Approved vehicle images could not be removed from temporary storage.', [
                'validacion_id' => $validacion->id,
                'image_paths' => $rutasImagenesTemporales,
            ]);
        }

        $mensaje = $validated['decision'] === 'aprobada'
            ? 'Documento aprobado y vehículo incorporado al inventario.'
            : 'Documento rechazado.';

        return to_route('validacion-documentos.show', $validacion)->with('success', $mensaje);
    }

    public function reenviar(Request $request, ValidacionDocumento $validacion): RedirectResponse
    {
        abort_unless($validacion->subido_por === $request->user()->id, 404);
        abort_unless($validacion->estado_revision === 'rechazada', 409, 'Solo se pueden reenviar solicitudes rechazadas.');

        $validated = $request->validate([
            'comentario_reenvio' => ['required', 'string', 'max:2000'],
        ]);
        $comentario = trim($validated['comentario_reenvio']);

        if ($comentario === '') {
            throw ValidationException::withMessages([
                'comentario_reenvio' => 'Escribe un comentario antes de reenviar la solicitud.',
            ]);
        }

        DB::transaction(function () use ($request, $validacion, $comentario): void {
            $validacionBloqueada = ValidacionDocumento::query()
                ->whereKey($validacion->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($validacionBloqueada->subido_por === $request->user()->id, 404);
            abort_unless(
                $validacionBloqueada->estado_revision === 'rechazada',
                409,
                'Solo se pueden reenviar solicitudes rechazadas.',
            );

            $validacionBloqueada->update([
                'estado_revision' => 'pendiente',
                'comentario_reenvio' => $comentario,
                'revisado_por' => null,
                'revisado_en' => null,
            ]);
        });

        return to_route('validacion-documentos.show', $validacion)
            ->with('success', 'Solicitud reenviada a revisión.');
    }

    /**
     * @param  array<string>  $rutasImagenesPublicas
     * @return array<string>
     */
    private function crearVehiculoDesdeExpediente(
        ValidacionDocumento $validacion,
        array &$rutasImagenesPublicas,
    ): array {
        $datosIngreso = $validacion->datos_ingreso;
        if (! is_array($datosIngreso)) {
            throw ValidationException::withMessages([
                'decision' => 'El expediente no contiene los datos necesarios para ingresar el vehículo al inventario.',
            ]);
        }

        $datosValidados = Validator::make($datosIngreso, [
            'id_vehiculo' => ['required', 'string', 'max:20', 'unique:vehiculos,id_vehiculo'],
            'id_marca' => ['required', 'exists:marca_vehiculos,id_marca'],
            'id_clase' => ['required', 'exists:clase_vehiculos,id_clase'],
            'id_pais' => ['required', 'exists:paises,id_pais'],
            'id_color' => ['required', 'exists:color_vehiculos,id_color'],
            'id_tipo_combustible' => ['required', 'exists:tipo_combustibles,id_tipo_combustible'],
            'id_transmision' => ['required', 'exists:transmisiones,id_transmision'],
            'id_patio' => ['nullable', 'exists:patios,id_patio'],
            'id_proveedor' => ['nullable', 'exists:proveedores,id_proveedor'],
            'modelo' => ['required', 'string', 'max:100'],
            'anio' => ['required', 'integer', 'min:1980', 'max:'.(date('Y') + 1)],
            'motor' => ['required', 'string', 'max:100', 'unique:vehiculos,motor'],
            'chasis' => ['required', 'string', 'max:100', 'unique:vehiculos,chasis'],
            'cilindraje' => ['required', 'string', 'max:100'],
            'servicio' => ['nullable', 'string', 'max:100'],
            'tipo_vehiculo' => ['nullable', 'string', 'max:100'],
            'kilometraje' => ['nullable', 'integer', 'min:0'],
            'costo_adquisicion' => ['required', 'numeric', 'min:0'],
            'precio_venta' => ['nullable', 'numeric', 'min:0', 'gte:costo_adquisicion'],
            'imagenes' => ['nullable', 'array', 'max:10'],
            'imagenes.*' => ['required', 'string', 'max:255'],
        ])->validate();

        if ($datosValidados['id_vehiculo'] !== $validacion->placa) {
            throw ValidationException::withMessages([
                'decision' => 'La placa de los datos de adquisición no coincide con la matrícula auditada.',
            ]);
        }

        $rutasImagenesTemporales = $datosValidados['imagenes'] ?? [];
        $prefijoImagenesTemporales = "validacion-documentos/{$validacion->id}/imagenes/";

        foreach ($rutasImagenesTemporales as $rutaImagenTemporal) {
            if (! str_starts_with($rutaImagenTemporal, $prefijoImagenesTemporales)
                || ! Storage::disk('local')->exists($rutaImagenTemporal)) {
                throw ValidationException::withMessages([
                    'decision' => 'No se encontró una imagen temporal del vehículo. Reenvía el expediente.',
                ]);
            }
        }

        $tipoVehiculo = $datosValidados['tipo_vehiculo'] ?? null;
        $servicio = $datosValidados['servicio'] ?? null;
        $atributosVehiculo = Arr::except($datosValidados, ['imagenes', 'tipo_vehiculo', 'servicio']);
        $atributosVehiculo['id_estado'] = EstadoVehiculo::firstOrCreate(['nombre' => 'Creado'])->id_estado;
        $atributosVehiculo['id_tipo_vehiculo'] = $tipoVehiculo === null
            ? null
            : TipoVehiculo::firstOrCreate(['nombre' => $tipoVehiculo])->id_tipo_vehiculo;
        $atributosVehiculo['id_servicio'] = $servicio === null
            ? null
            : Servicio::firstOrCreate(['nombre' => $servicio])->id_servicio;

        $vehiculo = Vehiculo::create($atributosVehiculo);

        if ($validacion->ruta_cuv !== null) {
            if (! Storage::disk('local')->exists($validacion->ruta_cuv)) {
                throw ValidationException::withMessages([
                    'decision' => 'No se encontró el CUV adjunto al expediente. Verifica el archivo antes de aprobarlo.',
                ]);
            }

            Documentos_vehiculo::create([
                'id_vehiculo' => $vehiculo->id_vehiculo,
                'ruta_cuv' => $validacion->ruta_cuv,
            ]);
        }

        foreach ($rutasImagenesTemporales as $index => $rutaImagenTemporal) {
            $rutaImagenPublica = "vehiculos/{$vehiculo->id_vehiculo}/".basename($rutaImagenTemporal);
            $contenidoImagen = Storage::disk('local')->get($rutaImagenTemporal);
            $rutasImagenesPublicas[] = $rutaImagenPublica;

            if (! Storage::disk('public')->put($rutaImagenPublica, $contenidoImagen)) {
                throw new RuntimeException('No se pudo publicar una imagen del vehículo aprobado.');
            }

            ImagenVehiculo::create([
                'id_vehiculo' => $vehiculo->id_vehiculo,
                'ruta_imagen' => $rutaImagenPublica,
                'es_principal' => $index === 0,
            ]);
        }

        $datosIngreso['imagenes'] = $rutasImagenesPublicas;
        $validacion->update(['datos_ingreso' => $datosIngreso]);

        return $rutasImagenesTemporales;
    }
}
