<?php

namespace App\Services\VehiculoIngreso;

use App\Models\clase_vehiculo;
use App\Models\color_vehiculo;
use App\Models\Documentos_vehiculo;
use App\Models\estado_vehiculo;
use App\Models\imagen_vehiculo;
use App\Models\marca_vehiculo;
use App\Models\pais;
use App\Models\Servicio;
use App\Models\TipoVehiculo;
use App\Models\ValidacionDocumento;
use App\Models\vehiculo as Vehiculo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class VehiculoIngresoService
{
    public function registrarIngreso(Request $request): RedirectResponse
    {
        $validacionIdInicial = $request->validate([
            'validacion_id' => ['nullable', 'integer', 'exists:validacion_documentos,id'],
        ])['validacion_id'] ?? null;
        $validacionAuditoria = null;

        if ($validacionIdInicial !== null) {
            $validacionAuditoria = ValidacionDocumento::query()
                ->whereKey($validacionIdInicial)
                ->where('subido_por', $request->user()->id)
                ->where('estado_revision', 'borrador')
                ->firstOrFail();

            $request->merge($this->camposVehiculoDesdeAxis($validacionAuditoria, $request));
        }

        $plate = $request->input('id_vehiculo');

        if (is_string($plate)) {
            $request->merge(['id_vehiculo' => strtoupper(trim($plate))]);
        }

        foreach (['tipo_vehiculo', 'servicio'] as $field) {
            $value = $request->input($field);

            if (is_string($value)) {
                $normalizedValue = mb_strtoupper(trim($value), 'UTF-8');
                $request->merge([$field => $normalizedValue === '' ? null : $normalizedValue]);
            }
        }

        $validated = $request->validate([
            'id_vehiculo' => ['required', 'string', 'max:20', 'unique:vehiculos,id_vehiculo'],
            'validacion_id' => ['nullable', 'integer', 'exists:validacion_documentos,id'],
            'comentario_solicitud' => ['nullable', 'string', 'max:2000'],
            'ultima_fecha_revision' => [
                $validacionAuditoria !== null ? 'required' : 'nullable',
                'string',
                'max:100',
                ...($validacionAuditoria !== null ? ['date_format:d-m-Y H:i:s'] : []),
            ],
            'lugar_revision' => [
                $validacionAuditoria !== null ? 'required' : 'nullable',
                'string',
                'max:150',
            ],
            'documento_matricula' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'imagenes' => ['nullable', 'array', 'max:10'],
            'imagenes.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
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
        ]);

        $comentarioSolicitud = trim($validated['comentario_solicitud'] ?? '');
        $documentoMatricula = $validated['documento_matricula'] ?? null;
        unset(
            $validated['validacion_id'],
            $validated['comentario_solicitud'],
            $validated['documento_matricula'],
        );

        if ($validacionAuditoria === null && $documentoMatricula instanceof UploadedFile) {
            return $this->enviarDocumentoSinAuditoriaARevision(
                $request,
                $validated,
                $documentoMatricula,
                $comentarioSolicitud,
            );
        }

        if ($validacionAuditoria !== null) {
            return $this->enviarAuditoriaARevision(
                $request,
                $validacionAuditoria,
                $validated,
                $comentarioSolicitud,
            );
        }

        $placaNormalizada = strtoupper(preg_replace('/[^A-Z0-9]/', '', $validated['id_vehiculo']) ?? '');
        $auditoriaSinAprobacion = ValidacionDocumento::query()
            ->where('placa', $placaNormalizada)
            ->whereIn('estado_revision', ['borrador', 'pendiente', 'rechazada'])
            ->exists();

        if ($validacionAuditoria === null && $auditoriaSinAprobacion) {
            throw ValidationException::withMessages([
                'id_vehiculo' => 'Esta matrícula ya fue auditada y requiere revisión manual antes de ingresar al inventario.',
            ]);
        }

        $validated['id_estado'] = estado_vehiculo::firstOrCreate(['nombre' => 'Creado'])->id_estado;

        $images = $validated['imagenes'] ?? [];
        unset($validated['imagenes']);

        $imagePaths = [];

        foreach ($images as $image) {
            $imagePath = $image->store('vehiculos', 'public');

            if (! is_string($imagePath)) {
                Storage::disk('public')->delete($imagePaths);

                throw new RuntimeException('No se pudo guardar la imagen del vehículo.');
            }

            $imagePaths[] = $imagePath;
        }

        try {
            DB::transaction(function () use ($validated, $imagePaths, $validacionAuditoria): void {
                if ($validacionAuditoria !== null) {
                    $validacionBloqueada = ValidacionDocumento::query()
                        ->whereKey($validacionAuditoria->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($validacionBloqueada->subido_por !== $validacionAuditoria->subido_por
                        || $validacionBloqueada->estado_revision !== 'borrador'
                        || $validacionBloqueada->placa !== $validated['id_vehiculo']) {
                        throw ValidationException::withMessages([
                            'validacion_id' => 'La auditoría ya no está disponible para registrar este vehículo.',
                        ]);
                    }
                }

                $tipoVehiculo = $validated['tipo_vehiculo'] ?? null;
                $servicio = $validated['servicio'] ?? null;
                unset($validated['tipo_vehiculo'], $validated['servicio']);

                $validated['id_tipo_vehiculo'] = $tipoVehiculo === null
                    ? null
                    : TipoVehiculo::firstOrCreate(['nombre' => $tipoVehiculo])->id_tipo_vehiculo;
                $validated['id_servicio'] = $servicio === null
                    ? null
                    : Servicio::firstOrCreate(['nombre' => $servicio])->id_servicio;

                $vehiculo = Vehiculo::create($validated);

                foreach ($imagePaths as $index => $imagePath) {
                    imagen_vehiculo::create([
                        'id_vehiculo' => $vehiculo->id_vehiculo,
                        'ruta_imagen' => $imagePath,
                        'es_principal' => $index === 0,
                    ]);
                }

                if ($validacionAuditoria !== null) {
                    Documentos_vehiculo::create([
                        'id_vehiculo' => $vehiculo->id_vehiculo,
                        'ruta_matricula' => $validacionAuditoria->ruta_documento,
                    ]);

                    $validacionBloqueada->update(['estado_revision' => 'registrada']);
                }
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($imagePaths);

            throw $exception;
        }

        return redirect()
            ->route('vehiculos.create')
            ->with('success', 'Vehículo ingresado exitosamente al inventario.');
    }

    /**
     * @param  array<string, mixed>  $datosOficiales
     * @return list<string>
     */
    public function camposAxisPendientes(array $datosOficiales): array
    {
        $camposFormulario = [
            'id_vehiculo' => 'placa',
            'id_marca' => 'marca',
            'id_clase' => 'clase',
            'id_pais' => 'pais',
            'id_color' => 'color',
            'modelo' => 'modelo',
            'anio' => 'anio',
            'motor' => 'motor',
            'chasis' => 'chasis',
            'cilindraje' => 'cilindraje',
        ];
        $pendientes = [];

        foreach ($camposFormulario as $campoFormulario => $campoAxis) {
            $valor = $datosOficiales[$campoAxis] ?? null;
            $datoAusente = ! is_string($valor) || trim($valor) === '';

            if ($campoFormulario === 'anio' && ! $datoAusente) {
                $anio = filter_var($valor, FILTER_VALIDATE_INT);
                $datoAusente = $anio === false || $anio < 1980 || $anio > (int) date('Y') + 1;
            }

            if ($datoAusente) {
                $pendientes[] = $campoFormulario;
            }
        }

        return $pendientes;
    }

    /**
     * @return array<string, mixed>
     */
    private function camposVehiculoDesdeAxis(ValidacionDocumento $validacion, Request $request): array
    {
        $datosOficiales = $validacion->datos_oficiales ?? [];
        $camposPendientes = $this->camposAxisPendientes($datosOficiales);
        $placaAxis = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($datosOficiales['placa'] ?? '')) ?? '');
        $placaExpediente = strtoupper(preg_replace('/[^A-Z0-9]/', '', $validacion->placa) ?? '');

        if ($placaAxis !== '' && $placaAxis !== $placaExpediente) {
            throw ValidationException::withMessages([
                'validacion_id' => 'La placa devuelta por AxisCloud no coincide con la matrícula auditada.',
            ]);
        }

        if (in_array('id_vehiculo', $camposPendientes, true)) {
            $placaManual = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $request->input('id_vehiculo')) ?? '');

            if ($placaManual !== '' && $placaManual !== $placaExpediente) {
                throw ValidationException::withMessages([
                    'id_vehiculo' => 'La placa ingresada debe coincidir con la matrícula que se auditó.',
                ]);
            }
        }

        if (! Storage::disk('local')->exists($validacion->ruta_documento)) {
            throw ValidationException::withMessages([
                'validacion_id' => 'No se encontró el archivo privado de la matrícula auditada. Vuelve a subirlo.',
            ]);
        }

        $valorAxis = fn (string $campoFormulario, string $campoAxis): mixed => in_array($campoFormulario, $camposPendientes, true)
            ? $request->input($campoFormulario)
            : trim((string) $datosOficiales[$campoAxis]);

        return [
            'id_vehiculo' => $valorAxis('id_vehiculo', 'placa'),
            'id_marca' => in_array('id_marca', $camposPendientes, true)
                ? $request->input('id_marca')
                : marca_vehiculo::firstOrCreate(['nombre' => ucwords(mb_strtolower(trim($datosOficiales['marca']), 'UTF-8'))])->id_marca,
            'id_clase' => in_array('id_clase', $camposPendientes, true)
                ? $request->input('id_clase')
                : clase_vehiculo::firstOrCreate(['nombre' => ucwords(mb_strtolower(trim($datosOficiales['clase']), 'UTF-8'))])->id_clase,
            'id_pais' => in_array('id_pais', $camposPendientes, true)
                ? $request->input('id_pais')
                : pais::firstOrCreate(['nombre' => ucwords(mb_strtolower(trim($datosOficiales['pais']), 'UTF-8'))])->id_pais,
            'id_color' => in_array('id_color', $camposPendientes, true)
                ? $request->input('id_color')
                : color_vehiculo::firstOrCreate(['nombre' => ucwords(mb_strtolower(trim($datosOficiales['color']), 'UTF-8'))])->id_color,
            'modelo' => $valorAxis('modelo', 'modelo'),
            'anio' => in_array('anio', $camposPendientes, true)
                ? $request->input('anio')
                : (int) $datosOficiales['anio'],
            'motor' => $valorAxis('motor', 'motor'),
            'chasis' => $valorAxis('chasis', 'chasis'),
            'cilindraje' => $valorAxis('cilindraje', 'cilindraje'),
            'tipo_vehiculo' => trim((string) ($datosOficiales['tipo_vehiculo'] ?? '')) ?: null,
            'servicio' => trim((string) ($datosOficiales['servicio'] ?? '')) ?: null,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function enviarAuditoriaARevision(
        Request $request,
        ValidacionDocumento $validacion,
        array $validated,
        string $comentarioSolicitud,
    ): RedirectResponse {
        $placa = strtoupper(preg_replace('/[^A-Z0-9]/', '', $validated['id_vehiculo']) ?? '');

        if ($placa !== $validacion->placa) {
            throw ValidationException::withMessages([
                'id_vehiculo' => 'La placa de los datos de ingreso debe coincidir con la matrícula auditada.',
            ]);
        }

        if (Vehiculo::query()->whereKey($placa)->exists()) {
            throw ValidationException::withMessages([
                'id_vehiculo' => 'La placa ya existe en el inventario.',
            ]);
        }

        $imagenes = $validated['imagenes'] ?? [];
        unset($validated['imagenes']);
        $validated['id_vehiculo'] = $placa;
        $rutasImagenesTemporales = [];

        try {
            foreach ($imagenes as $imagen) {
                $rutaImagen = $imagen->store("validacion-documentos/{$validacion->id}/imagenes", 'local');

                if (! is_string($rutaImagen)) {
                    throw new RuntimeException('No se pudo guardar una imagen del vehículo para revisión.');
                }

                $rutasImagenesTemporales[] = $rutaImagen;
            }

            $datosIngreso = [...$validated, 'imagenes' => $rutasImagenesTemporales];

            DB::transaction(function () use ($request, $validacion, $datosIngreso, $comentarioSolicitud): void {
                $validacionBloqueada = ValidacionDocumento::query()
                    ->whereKey($validacion->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($validacionBloqueada->subido_por !== $request->user()->id
                    || $validacionBloqueada->estado_revision !== 'borrador'
                    || $validacionBloqueada->placa !== $datosIngreso['id_vehiculo']) {
                    throw ValidationException::withMessages([
                        'validacion_id' => 'La auditoría ya no está disponible para enviar a revisión.',
                    ]);
                }

                $validacionBloqueada->update([
                    'estado_revision' => 'pendiente',
                    'datos_ingreso' => $datosIngreso,
                    'comentario_solicitud' => $comentarioSolicitud === '' ? null : $comentarioSolicitud,
                ]);
            });
        } catch (Throwable $exception) {
            if ($rutasImagenesTemporales !== []
                && ! Storage::disk('local')->delete($rutasImagenesTemporales)) {
                Log::warning('Could not remove temporary vehicle images after audited submission failed.', [
                    'validacion_id' => $validacion->id,
                    'file_paths' => $rutasImagenesTemporales,
                ]);
            }

            throw $exception;
        }

        return to_route('validacion-documentos.show', $validacion)
            ->with('success', 'Auditoría enviada a revisión manual. El vehículo se incorporará al inventario solo si se aprueba.');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function enviarDocumentoSinAuditoriaARevision(
        Request $request,
        array $validated,
        UploadedFile $documentoMatricula,
        string $comentarioSolicitud,
    ): RedirectResponse {
        $placa = strtoupper(preg_replace('/[^A-Z0-9]/', '', $validated['id_vehiculo']) ?? '');

        if (! preg_match('/^[A-Z0-9]{6,10}$/D', $placa)) {
            throw ValidationException::withMessages([
                'id_vehiculo' => 'Ingresa una placa válida para enviar el documento a revisión manual.',
            ]);
        }

        if (Vehiculo::query()->whereKey($placa)->exists()) {
            throw ValidationException::withMessages([
                'id_vehiculo' => 'La placa ya existe en el inventario.',
            ]);
        }

        $rutaDocumento = $documentoMatricula->store('validacion-documentos', 'local');
        if (! is_string($rutaDocumento)) {
            throw ValidationException::withMessages([
                'documento_matricula' => 'No se pudo guardar la matrícula para revisión manual. Inténtalo nuevamente.',
            ]);
        }

        $rutasArchivosTemporales = [$rutaDocumento];
        try {
            $imagenes = $validated['imagenes'] ?? [];
            unset($validated['imagenes']);
            $validated['id_vehiculo'] = $placa;
            $rutasImagenesTemporales = [];

            foreach ($imagenes as $imagen) {
                $rutaImagen = $imagen->store("validacion-documentos/manual/{$placa}/imagenes", 'local');

                if (! is_string($rutaImagen)) {
                    throw ValidationException::withMessages([
                        'imagenes' => 'No se pudo guardar una imagen del vehículo para revisión manual.',
                    ]);
                }

                $rutasImagenesTemporales[] = $rutaImagen;
                $rutasArchivosTemporales[] = $rutaImagen;
            }

            $datosIngreso = [...$validated, 'imagenes' => $rutasImagenesTemporales];
            $validacion = DB::transaction(fn (): ValidacionDocumento => ValidacionDocumento::create([
                'placa' => $placa,
                'ruta_documento' => $rutaDocumento,
                'mime_type' => $documentoMatricula->getMimeType() ?? 'application/octet-stream',
                'datos_documento' => ['placa' => $placa],
                'datos_oficiales' => [],
                'comparacion' => [[
                    'campo' => 'Auditoría automática',
                    'clave' => 'ocr',
                    'documento' => 'No procesado',
                    'sistema_web' => 'No consultado',
                    'coincide' => false,
                    'estado' => 'no_verificable',
                    'nota' => 'El servicio de reconocimiento no pudo procesar la matrícula. Verifica los datos con el documento adjunto.',
                    'critico' => false,
                ]],
                'alertas_criticas' => 0,
                'estado_revision' => 'pendiente',
                'subido_por' => $request->user()->id,
                'datos_ingreso' => $datosIngreso,
                'comentario_solicitud' => $comentarioSolicitud === '' ? null : $comentarioSolicitud,
            ]));
        } catch (Throwable $exception) {
            if (! Storage::disk('local')->delete($rutasArchivosTemporales)) {
                Log::warning('Could not remove temporary files after a failed manual document submission.', [
                    'plate' => $placa,
                    'file_paths' => $rutasArchivosTemporales,
                ]);
            }

            throw $exception;
        }

        return to_route('validacion-documentos.show', $validacion)
            ->with('success', 'Documento enviado a revisión manual sin auditoría automática.');
    }
}
