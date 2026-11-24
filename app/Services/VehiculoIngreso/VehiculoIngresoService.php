<?php

namespace App\Services\VehiculoIngreso;

use App\Models\clase_vehiculo;
use App\Models\color_vehiculo;
use App\Models\Documentos_vehiculo;
use App\Models\estado_vehiculo;
use App\Models\imagen_vehiculo;
use App\Models\marca_vehiculo;
use App\Models\Movimientos_vehiculo;
use App\Models\pais;
use App\Models\Servicio;
use App\Models\Tipo_movimiento;
use App\Models\tipo_combustible;
use App\Models\TipoContrato;
use App\Models\TipoVehiculo;
use App\Models\transmision;
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
        if ($request->input('categoria_ingreso') === 'nuevos') {
            return $this->registrarVehiculoNuevo($request);
        }

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
            'categoria_ingreso' => ['nullable', 'string', 'in:particular,empresa'],
            'validacion_id' => ['nullable', 'integer', 'exists:validacion_documentos,id'],
            'comentario_solicitud' => ['nullable', 'string', 'max:2000'],
            'ultima_fecha_revision' => [
                $validacionAuditoria !== null ? 'required' : 'nullable',
                'string',
                'max:100',
                ...($validacionAuditoria !== null ? ['date_format:d-m-Y H:i:s'] : []),
            ],
            'fecha_caducidad_revision' => ['nullable', 'date_format:Y-m-d'],
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

        $categoriaIngreso = $validated['categoria_ingreso'] ?? 'particular';
        $comentarioSolicitud = trim($validated['comentario_solicitud'] ?? '');
        $documentoMatricula = $validated['documento_matricula'] ?? null;
        unset(
            $validated['categoria_ingreso'],
            $validated['validacion_id'],
            $validated['comentario_solicitud'],
            $validated['documento_matricula'],
        );

        if ($validacionAuditoria === null && $categoriaIngreso === 'empresa') {
            return $this->enviarEmpresaARevision(
                $request,
                $validated,
                $documentoMatricula instanceof UploadedFile ? $documentoMatricula : null,
                $comentarioSolicitud,
            );
        }

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
            ->whereIn('estado_revision', ['borrador', 'pendiente', 'pendiente_comprobante', 'rechazada'])
            ->exists();

        if ($validacionAuditoria === null && $auditoriaSinAprobacion) {
            throw ValidationException::withMessages([
                'id_vehiculo' => 'Esta matrícula ya fue auditada y requiere revisión manual antes de ingresar al inventario.',
            ]);
        }

        $validated['id_estado'] = estado_vehiculo::firstOrCreate(['nombre' => 'Creado'])->id_estado;
        $validated['metodo_ingreso'] = $categoriaIngreso;

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
            'fecha_caducidad_revision' => AxisCloudService::normalizarFechaMysql(
                $datosOficiales['fecha_caducidad_revision']
                    ?? $datosOficiales['fecha_ultima_revision_hasta']
                    ?? null,
            ) ?? $request->input('fecha_caducidad_revision'),
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

        return to_route('vehiculos.create')
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

        return to_route('vehiculos.create')
            ->with('success', 'Documento enviado a revisión manual sin auditoría automática.');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function enviarEmpresaARevision(
        Request $request,
        array $validated,
        ?UploadedFile $documentoMatricula,
        string $comentarioSolicitud,
    ): RedirectResponse {
        $placa = strtoupper(preg_replace('/[^A-Z0-9]/', '', $validated['id_vehiculo']) ?? '');

        if (! preg_match('/^[A-Z0-9]{6,10}$/D', $placa)) {
            throw ValidationException::withMessages([
                'id_vehiculo' => 'Ingresa una placa válida para enviar el vehículo a revisión manual.',
            ]);
        }

        if (Vehiculo::query()->whereKey($placa)->exists()) {
            throw ValidationException::withMessages([
                'id_vehiculo' => 'La placa ya existe en el inventario.',
            ]);
        }

        $existePendiente = ValidacionDocumento::query()
            ->where('placa', $placa)
            ->whereIn('estado_revision', ['borrador', 'pendiente', 'pendiente_comprobante', 'rechazada'])
            ->exists();

        if ($existePendiente) {
            throw ValidationException::withMessages([
                'id_vehiculo' => 'Esta placa ya tiene un expediente en revisión.',
            ]);
        }

        $rutaDocumento = null;
        $mimeType = null;
        $rutasArchivosTemporales = [];

        if ($documentoMatricula !== null) {
            $rutaGuardada = $documentoMatricula->store('validacion-documentos', 'local');
            if (! is_string($rutaGuardada)) {
                throw ValidationException::withMessages([
                    'documento_matricula' => 'No se pudo guardar la matrícula para revisión manual. Inténtalo nuevamente.',
                ]);
            }

            $rutaDocumento = $rutaGuardada;
            $mimeType = $documentoMatricula->getMimeType() ?? 'application/octet-stream';
            $rutasArchivosTemporales[] = $rutaGuardada;
        }

        $imagenes = $validated['imagenes'] ?? [];
        unset($validated['imagenes']);
        $validated['id_vehiculo'] = $placa;

        try {
            $validacion = DB::transaction(function () use (
                $request,
                $placa,
                $rutaDocumento,
                $mimeType,
                $validated,
                $imagenes,
                $comentarioSolicitud,
                &$rutasArchivosTemporales,
            ): ValidacionDocumento {
                $nombreMarca = isset($validated['id_marca'])
                    ? DB::table('marca_vehiculos')->where('id_marca', $validated['id_marca'])->value('nombre')
                    : null;

                $validacion = ValidacionDocumento::create([
                    'placa' => $placa,
                    'categoria_ingreso' => 'empresa',
                    'ruta_documento' => $rutaDocumento,
                    'mime_type' => $mimeType,
                    'datos_documento' => array_filter([
                        'placa' => $placa,
                        'marca' => is_string($nombreMarca) && trim($nombreMarca) !== '' ? trim($nombreMarca) : null,
                        'modelo' => isset($validated['modelo']) && trim((string) $validated['modelo']) !== '' ? trim((string) $validated['modelo']) : null,
                    ], static fn (mixed $v): bool => $v !== null),
                    'datos_oficiales' => [],
                    'comparacion' => [[
                        'campo' => 'Auditoría de matrícula (Empresa)',
                        'clave' => 'ocr',
                        'documento' => 'Pendiente de escaneo',
                        'sistema_web' => 'Pendiente de consulta',
                        'coincide' => false,
                        'estado' => 'no_verificable',
                        'nota' => 'Vehículo ingresado bajo categoría Empresa. Escanea y compara la matrícula desde este expediente.',
                        'critico' => false,
                    ]],
                    'alertas_criticas' => 0,
                    'estado_revision' => 'pendiente',
                    'subido_por' => $request->user()->id,
                    'datos_ingreso' => [...$validated, 'imagenes' => []],
                    'comentario_solicitud' => $comentarioSolicitud === '' ? null : $comentarioSolicitud,
                ]);

                $rutasImagenesTemporales = [];
                foreach ($imagenes as $imagen) {
                    $rutaImagen = $imagen->store("validacion-documentos/{$validacion->id}/imagenes", 'local');

                    if (! is_string($rutaImagen)) {
                        throw ValidationException::withMessages([
                            'imagenes' => 'No se pudo guardar una imagen del vehículo para revisión manual.',
                        ]);
                    }

                    $rutasImagenesTemporales[] = $rutaImagen;
                    $rutasArchivosTemporales[] = $rutaImagen;
                }

                if ($rutasImagenesTemporales !== []) {
                    $validacion->update([
                        'datos_ingreso' => [...$validated, 'imagenes' => $rutasImagenesTemporales],
                    ]);
                }

                return $validacion;
            });
        } catch (Throwable $exception) {
            if ($rutasArchivosTemporales !== [] && ! Storage::disk('local')->delete($rutasArchivosTemporales)) {
                Log::warning('Could not remove temporary files after a failed company vehicle submission.', [
                    'plate' => $placa,
                    'file_paths' => $rutasArchivosTemporales,
                ]);
            }

            throw $exception;
        }

        return to_route('vehiculos.create')
            ->with('success', 'Vehículo de categoría Empresa enviado a revisión manual.');
    }

    private function registrarVehiculoNuevo(Request $request): RedirectResponse
    {
        $placaNormalizada = $this->normalizarPlacaOpcional($request->input('id_vehiculo'));
        $request->merge([
            'id_vehiculo' => $placaNormalizada,
            'marca_nombre' => is_string($request->input('marca_nombre'))
                ? trim($request->input('marca_nombre'))
                : null,
            'color_nombre' => is_string($request->input('color_nombre'))
                ? trim($request->input('color_nombre'))
                : null,
            'modelo' => is_string($request->input('modelo'))
                ? trim($request->input('modelo'))
                : null,
        ]);

        foreach (['tipo_vehiculo', 'servicio'] as $field) {
            $value = $request->input($field);

            if (is_string($value)) {
                $normalizedValue = mb_strtoupper(trim($value), 'UTF-8');
                $request->merge([$field => $normalizedValue === '' ? null : $normalizedValue]);
            }
        }

        $validated = $request->validate([
            'id_vehiculo' => ['nullable', 'string', 'max:20', 'unique:vehiculos,id_vehiculo'],
            'marca_nombre' => ['required', 'string', 'max:100'],
            'modelo' => ['required', 'string', 'max:100'],
            'anio' => ['required', 'integer', 'min:1980', 'max:'.((int) date('Y') + 1)],
            'color_nombre' => ['required', 'string', 'max:100'],
            'imagenes' => ['nullable', 'array', 'max:10'],
            'imagenes.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'id_clase' => ['nullable', 'integer', 'exists:clase_vehiculos,id_clase'],
            'id_pais' => ['nullable', 'integer', 'exists:paises,id_pais'],
            'motor' => ['nullable', 'string', 'max:100'],
            'chasis' => ['nullable', 'string', 'max:100'],
            'cilindraje' => ['nullable', 'string', 'max:100'],
            'servicio' => ['nullable', 'string', 'max:100'],
            'tipo_vehiculo' => ['nullable', 'string', 'max:100'],
            'fecha_caducidad_revision' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $images = $validated['imagenes'] ?? [];
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
            $vehiculo = DB::transaction(function () use ($validated, $imagePaths, $request): Vehiculo {
                $placaFinal = $validated['id_vehiculo'] ?? $this->generarPlacaTemporalUnica();
                $marca = $this->resolverMarcaPorNombre($validated['marca_nombre']);
                $color = $this->resolverColorPorNombre($validated['color_nombre']);

                $idClase = $validated['id_clase']
                    ?? clase_vehiculo::firstOrCreate(['nombre' => 'POR DEFINIR'])->id_clase;
                $idPais = $validated['id_pais']
                    ?? pais::firstOrCreate(['nombre' => 'POR DEFINIR'])->id_pais;
                $idCombustible = tipo_combustible::firstOrCreate(['nombre' => 'POR DEFINIR'])->id_tipo_combustible;
                $idTransmision = transmision::firstOrCreate(['nombre' => 'POR DEFINIR'])->id_transmision;
                $idEstado = estado_vehiculo::firstOrCreate(['nombre' => 'En Inventario'])->id_estado;
                $tipoContratoPropietario = TipoContrato::firstOrCreate(
                    ['nombre' => 'Propietario Optimus'],
                    ['requiere_documento' => false, 'cantidad_documentos' => 0],
                );

                $tipoVehiculo = $validated['tipo_vehiculo'] ?? null;
                $servicio = $validated['servicio'] ?? null;

                $idTipoVehiculo = $tipoVehiculo === null
                    ? null
                    : TipoVehiculo::firstOrCreate(['nombre' => $tipoVehiculo])->id_tipo_vehiculo;
                $idServicio = $servicio === null
                    ? null
                    : Servicio::firstOrCreate(['nombre' => $servicio])->id_servicio;

                $motor = isset($validated['motor']) && trim($validated['motor']) !== ''
                    ? strtoupper(trim($validated['motor']))
                    : 'PENDIENTE';
                $chasis = isset($validated['chasis']) && trim($validated['chasis']) !== ''
                    ? strtoupper(trim($validated['chasis']))
                    : 'PENDIENTE';
                $cilindraje = isset($validated['cilindraje']) && trim($validated['cilindraje']) !== ''
                    ? trim($validated['cilindraje'])
                    : 'POR DEFINIR';

                $vehiculo = Vehiculo::create([
                    'id_vehiculo' => $placaFinal,
                    'id_marca' => $marca->id_marca,
                    'id_clase' => $idClase,
                    'id_pais' => $idPais,
                    'id_color' => $color->id_color,
                    'id_tipo_combustible' => $idCombustible,
                    'id_transmision' => $idTransmision,
                    'id_tipo_vehiculo' => $idTipoVehiculo,
                    'id_servicio' => $idServicio,
                    'id_patio' => $request->user()?->id_patio,
                    'id_estado' => $idEstado,
                    'id_tipo_contrato' => $tipoContratoPropietario->id_tipo_contrato,
                    'metodo_ingreso' => 'nuevos',
                    'modelo' => mb_strtoupper($validated['modelo'], 'UTF-8'),
                    'anio' => (int) $validated['anio'],
                    'motor' => $motor,
                    'chasis' => $chasis,
                    'cilindraje' => $cilindraje,
                    'kilometraje' => 0,
                    'costo_adquisicion' => 0,
                    'fecha_caducidad_revision' => $validated['fecha_caducidad_revision'] ?? null,
                ]);

                foreach ($imagePaths as $index => $imagePath) {
                    imagen_vehiculo::create([
                        'id_vehiculo' => $vehiculo->id_vehiculo,
                        'ruta_imagen' => $imagePath,
                        'es_principal' => $index === 0,
                    ]);
                }

                // Inicializa expediente documental base (checklist de documentos del vehículo)
                Documentos_vehiculo::firstOrCreate(
                    ['id_vehiculo' => $vehiculo->id_vehiculo],
                    ['id_tipo_contrato' => $tipoContratoPropietario->id_tipo_contrato],
                );

                // Registra asiento inicial en Kardex (movimientos_vehiculos)
                $tipoMovimiento = Tipo_movimiento::firstOrCreate(['nombre' => 'Ingreso a Inventario']);
                Movimientos_vehiculo::create([
                    'id_vehiculo' => $vehiculo->id_vehiculo,
                    'id_tipo_movimiento' => $tipoMovimiento->id_tipo_movimiento,
                    'id_usuario' => $request->user()->id,
                    'descripcion' => sprintf(
                        'Ingreso directo de vehículo nuevo al inventario (%s %s %d · Color: %s).',
                        $marca->nombre,
                        $vehiculo->modelo,
                        $vehiculo->anio,
                        $color->nombre,
                    ),
                    'fecha_movimiento' => now(),
                ]);

                return $vehiculo;
            });
        } catch (Throwable $exception) {
            if ($imagePaths !== []) {
                Storage::disk('public')->delete($imagePaths);
            }

            throw $exception;
        }

        return to_route('vehiculos.index')
            ->with('success', "Vehículo nuevo ({$vehiculo->id_vehiculo}) ingresado directamente al inventario.");
    }

    private function normalizarPlacaOpcional(mixed $placa): ?string
    {
        if (! is_string($placa)) {
            return null;
        }

        $limpia = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($placa)) ?? '');
        if ($limpia === '') {
            return null;
        }

        if (preg_match('/^([A-Z]{3})(\d{3})$/', $limpia, $matches) === 1) {
            return $matches[1].'0'.$matches[2];
        }

        return $limpia;
    }

    private function generarPlacaTemporalUnica(): string
    {
        do {
            $candidata = 'NUEVO-'.strtoupper(bin2hex(random_bytes(4)));
        } while (Vehiculo::query()->where('id_vehiculo', $candidata)->exists());

        return $candidata;
    }

    private function resolverMarcaPorNombre(string $nombre): marca_vehiculo
    {
        $normalizado = mb_strtoupper(trim($nombre), 'UTF-8');

        $existente = marca_vehiculo::query()
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($normalizado, 'UTF-8')])
            ->first();

        return $existente ?? marca_vehiculo::firstOrCreate(['nombre' => $normalizado]);
    }

    private function resolverColorPorNombre(string $nombre): color_vehiculo
    {
        $normalizado = mb_strtoupper(trim($nombre), 'UTF-8');

        $existente = color_vehiculo::query()
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($normalizado, 'UTF-8')])
            ->first();

        return $existente ?? color_vehiculo::firstOrCreate(['nombre' => $normalizado]);
    }
}
