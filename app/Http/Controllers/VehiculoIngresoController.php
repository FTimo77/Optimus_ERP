<?php

namespace App\Http\Controllers;

use App\Models\clase_vehiculo;
use App\Models\color_vehiculo;
use App\Models\Documentos_vehiculo;
use App\Models\estado_vehiculo;
use App\Models\imagen_vehiculo;
use App\Models\marca_vehiculo;
use App\Models\pais;
use App\Models\Servicio;
use App\Models\tipo_combustible;
use App\Models\TipoVehiculo;
use App\Models\transmision;
use App\Models\ValidacionDocumento;
use App\Models\vehiculo as Vehiculo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class VehiculoIngresoController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Vehiculos/Ingreso', [
            'marcas' => marca_vehiculo::all(),
            'clases' => clase_vehiculo::all(),
            'paises' => pais::all(),
            'colores' => color_vehiculo::all(),
            'combustibles' => tipo_combustible::all(),
            'transmisiones' => transmision::all(),
            'success' => session('success'),
        ]);
    }

    public function store(Request $request): RedirectResponse
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
     * @param  array<string, mixed>  $datosOficiales
     * @return list<string>
     */
    private function camposAxisPendientes(array $datosOficiales): array
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

    public function consultarAxisCloud(Request $request)
    {
        set_time_limit(90);

        $placa = strtoupper(str_replace(['-', ' '], '', trim($request->input('placa', ''))));

        if (! $placa) {
            return response()->json(['success' => false, 'message' => 'Ingrese un número de placa.'], 422);
        }

        $scriptPath = base_path('consultar_axis.cjs');
        $archivoTemp = storage_path("app/axis_{$placa}.json");

        if (file_exists($archivoTemp)) {
            @unlink($archivoTemp);
        }

        $nodeBin = env('NODE_BINARY_PATH', file_exists('C:\laragon\bin\nodejs\node-v22\node.exe')
             ? '"C:\laragon\bin\nodejs\node-v22\node.exe"'
            : 'node');

        $comando = "{$nodeBin} \"{$scriptPath}\" {$placa} \"{$archivoTemp}\" 2>&1";
        exec($comando, $salidaConsola, $codigoSalida);

        if (! file_exists($archivoTemp)) {
            return response()->json([
                'success' => false,
                'message' => 'Error al ejecutar Node: '.implode(' ', $salidaConsola),
            ], 500);
        }

        $jsonTexto = file_get_contents($archivoTemp);
        @unlink($archivoTemp);

        $respuestaExterna = json_decode($jsonTexto, true);

        if (isset($respuestaExterna['error_script'])) {
            return response()->json([
                'success' => false,
                'message' => 'Aviso de AxisCloud: '.$respuestaExterna['error_script'],
            ], 422);
        }

        $datos = null;
        if (isset($respuestaExterna['campos'])) {
            $datos = is_string($respuestaExterna['campos'])
                ? json_decode($respuestaExterna['campos'], true)
                : $respuestaExterna['campos'];
        } elseif (isset($respuestaExterna['lsDatosIdentificacion'])) {
            $datos = $respuestaExterna;
        }

        if (! $datos || empty($datos['lsDatosIdentificacion'])) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontraron datos para la placa '.$placa.' en AxisCloud.',
            ], 404);
        }

        $extraerValor = function (array $lista, string $etiquetaBuscada) {
            foreach ($lista as $item) {
                if (isset($item['etiqueta']) && mb_stripos($item['etiqueta'], $etiquetaBuscada, 0, 'UTF-8') !== false) {
                    return trim($item['valor'] ?? '');
                }
            }

            return '';
        };

        $identificacion = $datos['lsDatosIdentificacion'] ?? [];
        $modeloInfo = $datos['lsDatosModelo'] ?? [];
        $otrasInfo = $datos['lsOtrasCaracteristicas'] ?? [];

        $servicio = trim($datos['lsServicio'] ?? '');

        $chasis = $extraerValor($identificacion, 'VIN');
        $motor = $extraerValor($identificacion, 'Motor');
        $nombreMarca = $extraerValor($modeloInfo, 'Marca');
        $nombreModelo = $extraerValor($modeloInfo, 'Modelo');
        $anio = $extraerValor($modeloInfo, 'Fabricaci');
        $nombrePais = $extraerValor($modeloInfo, 'Pa');
        $nombreClase = $extraerValor($otrasInfo, 'Clase Veh');
        $tipoVehiculo = $extraerValor($otrasInfo, 'Tipo Veh');
        $nombreColor = $extraerValor($otrasInfo, 'Color 1');

        $cilindraje = $extraerValor($otrasInfo, 'Cilindraje');
        if (! $cilindraje && preg_match('/\b(\d\.\d)\b/', $nombreModelo, $coincidencias)) {
            $cilindraje = (string) ((float) $coincidencias[1] * 1000);
        }

        $marca = $nombreMarca ? marca_vehiculo::firstOrCreate(['nombre' => ucwords(mb_strtolower($nombreMarca, 'UTF-8'))]) : null;
        $pais = $nombrePais ? pais::firstOrCreate(['nombre' => ucwords(mb_strtolower($nombrePais, 'UTF-8'))]) : null;
        $color = $nombreColor ? color_vehiculo::firstOrCreate(['nombre' => ucwords(mb_strtolower($nombreColor, 'UTF-8'))]) : null;
        $clase = $nombreClase ? clase_vehiculo::firstOrCreate(['nombre' => ucwords(mb_strtolower($nombreClase, 'UTF-8'))]) : null;

        return response()->json([
            'success' => true,
            'vehiculo' => [
                'id_vehiculo' => $placa,
                'chasis' => $chasis,
                'motor' => $motor,
                'modelo' => $nombreModelo,
                'anio' => is_numeric($anio) ? (int) $anio : (int) date('Y'),
                'cilindraje' => $cilindraje,
                'servicio' => $servicio,
                'tipo_vehiculo' => $tipoVehiculo,
                'id_marca' => $marca ? $marca->id_marca : '',
                'id_pais' => $pais ? $pais->id_pais : '',
                'id_color' => $color ? $color->id_color : '',
                'id_clase' => $clase ? $clase->id_clase : '',
            ],
            'catalogos' => [
                'marcas' => marca_vehiculo::all(),
                'paises' => pais::all(),
                'colores' => color_vehiculo::all(),
                'clases' => clase_vehiculo::all(),
            ],
        ]);
    }

    public function escanearYCompararMatricula(Request $request): JsonResponse
    {
        set_time_limit(180);

        $request->validate([
            'documento' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $archivo = $request->file('documento');
        $mimeType = $archivo->getMimeType();
        $archivoBytes = file_get_contents($archivo->getRealPath());

        if (! is_string($archivoBytes)) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo leer el documento subido. Vuelve a seleccionarlo e inténtalo otra vez.',
            ], 422);
        }

        // 1. Procesamiento de imagen sin perder nitidez en letras pequeñas
        $base64Data = null;
        if (str_starts_with($mimeType, 'image/') && function_exists('imagecreatefromstring')) {
            $imgRaw = @imagecreatefromstring($archivoBytes);
            if ($imgRaw !== false) {
                $ancho = imagesx($imgRaw);
                $alto = imagesy($imgRaw);
                $maxDim = 2200;

                if ($ancho > $maxDim || $alto > $maxDim) {
                    $ratio = min($maxDim / $ancho, $maxDim / $alto);
                    $nuevoAncho = (int) round($ancho * $ratio);
                    $nuevoAlto = (int) round($alto * $ratio);
                    $imgResized = @imagescale($imgRaw, $nuevoAncho, $nuevoAlto);
                    if ($imgResized !== false) {
                        imagedestroy($imgRaw);
                        $imgRaw = $imgResized;
                    }
                }

                ob_start();
                imagejpeg($imgRaw, null, 92);
                $jpegNitido = ob_get_clean();
                imagedestroy($imgRaw);

                if ($jpegNitido) {
                    $base64Data = base64_encode($jpegNitido);
                    $mimeType = 'image/jpeg';
                }
            }
        }

        if (! $base64Data) {
            $base64Data = base64_encode($archivoBytes);
        }

        $apiKey = config('services.gemini.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            return response()->json([
                'success' => false,
                'message' => 'Gemini no está configurado. Define GEMINI_API_KEY en .env y limpia la caché de configuración.',
            ], 503);
        }

        $prompt = 'Analiza este documento de matrícula vehicular de Ecuador. Extrae solo los datos que sean visibles; no inventes ni deduzcas valores. '
            .'Devuelve únicamente un objeto JSON con estas claves exactas: '
            .'{"placa": "", "chasis": "", "motor": "", "marca": "", "modelo": "", "anio": "", "anio_expedicion": "", "fecha_emision": "", "fecha_caduca": "", "color": "", "clase": "", "servicio": "", "propietario": "", "cedula_propietario": ""}. '
            .'En "anio" indica únicamente el año de fabricación/modelo y en "anio_expedicion" el año de emisión o revisión. '
            .'Copia "fecha_emision" y "fecha_caduca" tal como aparecen, sin inferirlas de otras fechas. '
            .'Si un dato no es legible o no aparece, usa una cadena vacía.';

        $model = config('services.gemini.model', 'gemini-3.5-flash-lite');

        try {
            $responseIA = Http::acceptJson()
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->connectTimeout(10)
                ->timeout(60)
                ->retry(
                    [1000],
                    0,
                    fn (Throwable $exception): bool => $exception instanceof RequestException
                        && $exception->response->status() === 503,
                    false,
                )
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                                [
                                    'inline_data' => [
                                        'mime_type' => $mimeType,
                                        'data' => $base64Data,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                    ],
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Gemini vehicle document OCR request could not connect.', [
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo conectar con Gemini. Revisa la conexión del servidor e inténtalo otra vez.',
            ], 503);
        }

        if (! $responseIA->successful()) {
            $httpStatus = $responseIA->status();
            $providerStatus = $responseIA->json('error.status');

            Log::warning('Gemini vehicle document OCR request failed.', [
                'http_status' => $httpStatus,
                'provider_status' => is_string($providerStatus) ? $providerStatus : null,
            ]);

            if (in_array($httpStatus, [401, 403], true)
                || in_array($providerStatus, ['UNAUTHENTICATED', 'PERMISSION_DENIED', 'API_KEY_INVALID'], true)) {
                $message = 'Gemini rechazó la clave o sus permisos. Confirma que la clave sea válida y que el proyecto tenga acceso a Gemini API.';
            } elseif ($httpStatus === 429 || $providerStatus === 'RESOURCE_EXHAUSTED') {
                $message = 'Se alcanzó la cuota o el límite de solicitudes de Gemini. Inténtalo más tarde o revisa el plan del proyecto.';
            } elseif ($httpStatus === 404) {
                $message = 'No se encontró el modelo de Gemini configurado. Revisa GEMINI_MODEL en .env.';
            } elseif ($httpStatus === 503) {
                $message = 'Gemini está temporalmente no disponible. El sistema reintentó una vez; espera un momento y vuelve a escanear.';
            } elseif ($httpStatus === 400) {
                $message = 'Gemini rechazó el archivo o la solicitud. Confirma el modelo y que el documento sea válido.';
            } else {
                $message = "Gemini no pudo procesar el documento (HTTP {$httpStatus}). Revisa la configuración del servicio.";
            }

            return response()->json([
                'success' => false,
                'message' => $message,
                'manual_review_available' => true,
            ], $httpStatus === 429 ? 503 : 502);
        }

        $textoIA = $responseIA->json('candidates.0.content.parts.0.text');
        $datosMatricula = is_string($textoIA) ? json_decode($textoIA, true) : null;

        if (! is_array($datosMatricula) || empty($datosMatricula['placa'])) {
            return response()->json([
                'success' => false,
                'message' => 'La IA no logró identificar una placa legible en el documento subido.',
            ], 422);
        }

        // Limpieza de placa ecuatoriana
        $placa = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim((string) $datosMatricula['placa'])));
        if (strlen($placa) >= 6) {
            $letras = strtr(substr($placa, 0, 3), ['0' => 'O', '1' => 'I', '8' => 'B', '5' => 'S']);
            $numeros = strtr(substr($placa, 3), ['O' => '0', 'I' => '1', 'B' => '8', 'S' => '5']);
            $placa = $letras.$numeros;
        }
        if (preg_match('/^([A-Z]{3})(\d{3})$/', $placa, $partes)) {
            $placa = $partes[1].'0'.$partes[2];
        }

        if (! preg_match('/^[A-Z0-9]{6,10}$/D', $placa)) {
            return response()->json([
                'success' => false,
                'message' => 'La IA devolvió una placa con formato no válido. Verifica el documento e inténtalo otra vez.',
            ], 422);
        }

        // 2. Ejecutamos la Auditoría Completa en Paralelo (AxisCloud + Propietario + Multas Autority + SRI)
        $archivoTemp = storage_path('app/auditoria_'.bin2hex(random_bytes(12)).'.json');
        if (file_exists($archivoTemp)) {
            @unlink($archivoTemp);
        }

        $codigoSalidaAuditoria = $this->ejecutarAuditoriaCompleta($placa, $archivoTemp);

        $datosOficiales = [];
        $axisDisponible = false;
        $motivoFalloAxis = null;
        $resumenMultas = [
            'ok' => false,
            'tiene_multas' => false,
            'cantidad' => 0,
            'total' => 0.0,
            'listado' => [],
        ];
        $resumenSRI = [
            'ok' => false,
            'tiene_deuda' => false,
            'total' => 0.0,
            'ultimo_anio' => 'No disponible',
            'camv_cpn' => '',
            'rubros' => [],
        ];

        if (file_exists($archivoTemp)) {
            $contenidoArchivo = file_get_contents($archivoTemp);
            @unlink($archivoTemp);

            $paquete = is_string($contenidoArchivo) ? json_decode($contenidoArchivo, true) : null;

            if (is_array($paquete)) {
                // A. Propietario Oficial (privynote)
                $nombrePropietarioWeb = trim((string) ($paquete['propietario']['nombre'] ?? ''));

                // B. Valores de Matrícula y Datos Base del SRI
                $vehiculoSRI = is_array($paquete['sri']['vehiculo'] ?? null) ? $paquete['sri']['vehiculo'] : [];
                $rubrosSRI = is_array($paquete['sri']['rubros'] ?? null) ? $paquete['sri']['rubros'] : [];
                $totalSRI = 0.0;
                $listaRubrosSRI = [];

                foreach ($rubrosSRI as $r) {
                    if (! is_array($r)) {
                        continue;
                    }
                    $monto = (float) ($r['valorRubro'] ?? $r['valor'] ?? $r['total'] ?? $r['valorTotal'] ?? 0);
                    $totalSRI += $monto;
                    $listaRubrosSRI[] = [
                        'concepto' => trim((string) ($r['descripcionRubro'] ?? $r['nombreCorto'] ?? $r['descripcion'] ?? 'Rubro SRI')),
                        'periodo' => (string) ($r['anioDesde'] ?? $r['periodo'] ?? ''),
                        'valor' => round($monto, 2),
                    ];
                }

                $resumenSRI = [
                    'ok' => (bool) ($paquete['sri']['ok'] ?? false),
                    'tiene_deuda' => $totalSRI > 0,
                    'total' => round($totalSRI, 2),
                    'ultimo_anio' => (string) ($vehiculoSRI['ultimoAnioPagado'] ?? 'S/N'),
                    'camv_cpn' => (string) ($vehiculoSRI['numeroCamvCpn'] ?? ''),
                    'rubros' => $listaRubrosSRI,
                ];

                // C. Multas de Tránsito (Autority)
                $listaMultasRaw = is_array($paquete['multas']['data'] ?? null) ? $paquete['multas']['data'] : [];
                $totalMultas = 0.0;
                $listadoMultas = [];

                foreach ($listaMultasRaw as $m) {
                    if (! is_array($m)) {
                        continue;
                    }
                    $val = (float) ($m['totalValue'] ?? $m['valueFine'] ?? 0);
                    $totalMultas += $val;
                    $listadoMultas[] = [
                        'citacion' => (string) ($m['nCitacion'] ?? $m['nInfraccion'] ?? 'S/N'),
                        'entidad' => (string) ($m['entity'] ?? 'Tránsito'),
                        'fecha' => (string) ($m['issuanceDate'] ?? $m['registrationDate'] ?? ''),
                        'descripcion' => (string) ($m['description'] ?? 'Infracción de tránsito'),
                        'valor_base' => round((float) ($m['valueFine'] ?? $val), 2),
                        'interes' => round((float) ($m['interestValue'] ?? 0), 2),
                        'descuento' => round((float) ($m['exemptValue'] ?? 0), 2),
                        'total' => round($val, 2),
                        'puntos' => (int) ($m['points'] ?? 0),
                    ];
                }

                $resumenMultas = [
                    'ok' => ($paquete['multas']['status'] ?? '') === 'success',
                    'tiene_multas' => count($listadoMultas) > 0,
                    'cantidad' => count($listadoMultas),
                    'total' => round($totalMultas, 2),
                    'listado' => $listadoMultas,
                ];

                // D. Ficha Técnica de AxisCloud
                $respuestaExterna = is_array($paquete['axis'] ?? null) ? $paquete['axis'] : $paquete;

                if (isset($respuestaExterna['error_script'])) {
                    $motivoFalloAxis = (string) $respuestaExterna['error_script'];
                }

                $rawAxis = isset($respuestaExterna['campos'])
                    ? (is_string($respuestaExterna['campos']) ? json_decode($respuestaExterna['campos'], true) : $respuestaExterna['campos'])
                    : (! empty($respuestaExterna['lsDatosIdentificacion']) ? $respuestaExterna : null);

                if (is_array($rawAxis) && ! empty($rawAxis['lsDatosIdentificacion'])) {
                    $axisDisponible = true;
                    $extraerValor = function (array $lista, string $etiquetaBuscada) {
                        foreach ($lista as $item) {
                            if (! is_array($item)) {
                                continue;
                            }

                            if (isset($item['etiqueta']) && mb_stripos($item['etiqueta'], $etiquetaBuscada, 0, 'UTF-8') !== false) {
                                return trim($item['valor'] ?? '');
                            }
                        }

                        return '';
                    };

                    $identificacion = is_array($rawAxis['lsDatosIdentificacion'] ?? null) ? $rawAxis['lsDatosIdentificacion'] : [];
                    $modeloInfo = is_array($rawAxis['lsDatosModelo'] ?? null) ? $rawAxis['lsDatosModelo'] : [];
                    $otrasInfo = is_array($rawAxis['lsOtrasCaracteristicas'] ?? null) ? $rawAxis['lsOtrasCaracteristicas'] : [];
                    $revisionInfo = is_array($rawAxis['lsRevision'] ?? null) ? $rawAxis['lsRevision'] : [];

                    $datosOficiales = [
                        'placa' => $extraerValor($identificacion, 'Placa'),
                        'propietario' => $nombrePropietarioWeb,
                        'chasis' => $extraerValor($identificacion, 'VIN') ?: $extraerValor($identificacion, 'Chasis'),
                        'motor' => $extraerValor($identificacion, 'Motor'),
                        'marca' => $extraerValor($modeloInfo, 'Marca'),
                        'modelo' => $extraerValor($modeloInfo, 'Modelo'),
                        'anio' => $extraerValor($modeloInfo, 'Fabricaci'),
                        'pais' => $extraerValor($modeloInfo, 'País') ?: $extraerValor($modeloInfo, 'Pais'),
                        'color' => $extraerValor($otrasInfo, 'Color 1'),
                        'clase' => $extraerValor($otrasInfo, 'Clase'),
                        'tipo_vehiculo' => $extraerValor($otrasInfo, 'Tipo'),
                        'cilindraje' => $extraerValor($otrasInfo, 'Cilindraje'),
                        'servicio' => trim((string) ($rawAxis['lsServicio'] ?? '')),
                        'fecha_matricula_desde' => $extraerValor($revisionInfo, 'Matrícula Desde'),
                        'fecha_matricula_hasta' => $extraerValor($revisionInfo, 'Matrícula Hasta'),
                        'fecha_ultima_revision_desde' => $extraerValor($revisionInfo, 'Última Revisión Desde'),
                        'fecha_ultima_revision_hasta' => $extraerValor($revisionInfo, 'Última Revisión Hasta'),
                        'anio_ultima_revision' => $extraerValor($revisionInfo, 'Año Última Revisión'),
                        'ultimo_anio_pagado_sri' => $resumenSRI['ultimo_anio'],
                        'total_multas' => $resumenMultas['total'],
                        'total_sri' => $resumenSRI['total'],
                    ];
                } else {
                    $datosOficiales = [
                        'placa' => '',
                        'propietario' => $nombrePropietarioWeb,
                        'chasis' => '',
                        'motor' => '',
                        'marca' => '',
                        'modelo' => '',
                        'anio' => '',
                        'pais' => '',
                        'color' => '',
                        'clase' => '',
                        'tipo_vehiculo' => '',
                        'cilindraje' => '',
                        'servicio' => '',
                        'ultimo_anio_pagado_sri' => $resumenSRI['ultimo_anio'],
                        'total_multas' => $resumenMultas['total'],
                        'total_sri' => $resumenSRI['total'],
                    ];

                    if (! $motivoFalloAxis) {
                        $motivoFalloAxis = "Sin registros en AxisCloud para {$placa}";
                    }
                }
            } else {
                $motivoFalloAxis = 'Respuesta de auditoría inválida';
            }
        } else {
            $motivoFalloAxis = 'Sin respuesta del robot';
            Log::warning('Vehicle full audit lookup did not produce an output file.', [
                'exit_code' => $codigoSalidaAuditoria,
            ]);
        }

        $camposAxisPendientes = $this->camposAxisPendientes($datosOficiales);
        $placaAxisNormalizada = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($datosOficiales['placa'] ?? '')) ?? '');
        $placaAxisNoCoincide = $placaAxisNormalizada !== '' && $placaAxisNormalizada !== $placa;

        if (! $axisDisponible || $placaAxisNoCoincide) {
            Log::warning('AxisCloud could not complete the official vehicle audit.', [
                'missing_fields' => $camposAxisPendientes,
                'axis_available' => $axisDisponible,
                'plate_mismatch' => $placaAxisNoCoincide,
            ]);

            return response()->json([
                'success' => false,
                'message' => $placaAxisNoCoincide
                    ? 'La placa devuelta por AxisCloud no coincide con la matrícula auditada.'
                    : 'AxisCloud no pudo completar la auditoría. Verifica los datos en el formulario manual.',
                'manual_review_available' => true,
            ], 422);
        }

        if ($camposAxisPendientes !== []) {
            Log::info('AxisCloud audit completed with fields to be completed manually.', [
                'missing_fields' => $camposAxisPendientes,
            ]);
        }

        $normalizar = fn (mixed $valor): string => strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $valor) ?? '');
        $normalizarFecha = function (string $fecha): ?string {
            $fecha = trim($fecha);

            if (preg_match('/^(\d{1,2})[-\/.\s]([A-Za-zÁÉÍÓÚÜÑ]{3,})[-\/.\s](\d{4})(?:[T\s].*)?$/u', $fecha, $partes)) {
                $meses = [
                    'ENE' => 1, 'ENERO' => 1, 'JAN' => 1, 'JANUARY' => 1,
                    'FEB' => 2, 'FEBRERO' => 2, 'FEBRUARY' => 2,
                    'MAR' => 3, 'MARZO' => 3, 'MARCH' => 3,
                    'ABR' => 4, 'ABRIL' => 4, 'APR' => 4, 'APRIL' => 4,
                    'MAY' => 5, 'MAYO' => 5,
                    'JUN' => 6, 'JUNIO' => 6, 'JUNE' => 6,
                    'JUL' => 7, 'JULIO' => 7, 'JULY' => 7,
                    'AGO' => 8, 'AGOSTO' => 8, 'AUG' => 8, 'AUGUST' => 8,
                    'SEP' => 9, 'SEPT' => 9, 'SET' => 9, 'SEPTIEMBRE' => 9, 'SEPTEMBER' => 9,
                    'OCT' => 10, 'OCTUBRE' => 10, 'OCTOBER' => 10,
                    'NOV' => 11, 'NOVIEMBRE' => 11, 'NOVEMBER' => 11,
                    'DIC' => 12, 'DICIEMBRE' => 12, 'DEC' => 12, 'DECEMBER' => 12,
                ];
                $mes = $meses[mb_strtoupper(rtrim($partes[2], '.'), 'UTF-8')] ?? 0;
                $dia = (int) $partes[1];
                $anio = (int) $partes[3];
            } elseif (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})(?:[T\s].*)?$/', $fecha, $partes)) {
                [$dia, $mes, $anio] = [(int) $partes[1], (int) $partes[2], (int) $partes[3]];
            } elseif (preg_match('/^(\d{4})[\/.-](\d{1,2})[\/.-](\d{1,2})(?:[T\s].*)?$/', $fecha, $partes)) {
                [$anio, $mes, $dia] = [(int) $partes[1], (int) $partes[2], (int) $partes[3]];
            } else {
                return null;
            }

            return checkdate($mes, $dia, $anio) ? sprintf('%04d-%02d-%02d', $anio, $mes, $dia) : null;
        };

        $camposComparar = [
            'placa' => ['campo' => 'Placa', 'documento' => 'placa', 'sistema_web' => 'placa'],
            'propietario' => ['campo' => 'Propietario Actual', 'documento' => 'propietario', 'sistema_web' => 'propietario'],
            'chasis' => ['campo' => 'Chasis (VIN)', 'documento' => 'chasis', 'sistema_web' => 'chasis'],
            'motor' => ['campo' => 'Número de Motor', 'documento' => 'motor', 'sistema_web' => 'motor'],
            'marca' => ['campo' => 'Marca', 'documento' => 'marca', 'sistema_web' => 'marca'],
            'modelo' => ['campo' => 'Modelo', 'documento' => 'modelo', 'sistema_web' => 'modelo'],
            'anio' => ['campo' => 'Año de Fabricación', 'documento' => 'anio', 'sistema_web' => 'anio'],
            'fecha_emision' => [
                'campo' => 'Fecha de emisión / Matrícula desde',
                'documento' => 'fecha_emision',
                'sistema_web' => 'fecha_matricula_desde',
            ],
            'fecha_caduca' => [
                'campo' => 'Fecha de caducidad / Matrícula hasta',
                'documento' => 'fecha_caduca',
                'sistema_web' => 'fecha_matricula_hasta',
            ],
            'color' => ['campo' => 'Color Principal', 'documento' => 'color', 'sistema_web' => 'color'],
            'clase' => ['campo' => 'Clase de Vehículo', 'documento' => 'clase', 'sistema_web' => 'clase'],
            'servicio' => ['campo' => 'Tipo de Servicio', 'documento' => 'servicio', 'sistema_web' => 'servicio'],
        ];

        $camposFecha = ['fecha_emision', 'fecha_caduca'];
        $camposCriticos = ['chasis', 'motor', 'placa', 'propietario'];
        $comparacion = [];
        $alertasCriticas = 0;
        $anioExpedicion = $normalizar($datosMatricula['anio_expedicion'] ?? '');

        foreach ($camposComparar as $clave => $configuracionCampo) {
            $valDoc = trim((string) ($datosMatricula[$configuracionCampo['documento']] ?? ''));
            $valWeb = trim((string) ($datosOficiales[$configuracionCampo['sistema_web']] ?? ''));
            $normDoc = $normalizar($valDoc);
            $normWeb = $normalizar($valWeb);
            $coincide = false;
            $estado = 'no_verificable';
            $nota = '';

            if ($normDoc === '' || $normWeb === '') {
                $nota = $normDoc === ''
                    ? 'Dato no legible en la matrícula.'
                    : ($motivoFalloAxis ?: 'Dato no disponible en el sistema web.');
            } elseif (in_array($clave, $camposFecha, true)) {
                $fechaDocumento = $normalizarFecha($valDoc);
                $fechaAxis = $normalizarFecha($valWeb);

                if ($fechaDocumento === null || $fechaAxis === null) {
                    $nota = 'No se pudo interpretar una de las fechas; confirma el formato en el documento.';
                } elseif ($fechaDocumento === $fechaAxis) {
                    $coincide = true;
                    $estado = 'coincide';
                } else {
                    $estado = 'diferente';
                    $nota = 'La fecha del documento no coincide con la fecha de AxisCloud.';
                }
            } elseif ($normDoc === $normWeb) {
                $coincide = true;
                $estado = 'coincide';
            } elseif ($clave === 'propietario') {
                // Compara apellidos y nombres sin importar el orden ("APELLIDOS NOMBRES" vs "NOMBRES APELLIDOS")
                $limpiarPalabras = fn (string $txt): array => array_values(array_filter(
                    explode(' ', preg_replace('/[^A-Z\s]/', '', strtr(mb_strtoupper($txt, 'UTF-8'), [
                        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
                    ])) ?? ''),
                    fn (string $w): bool => strlen($w) >= 2
                ));

                $palabrasDoc = $limpiarPalabras($valDoc);
                $palabrasWeb = $limpiarPalabras($valWeb);
                $coincidencias = count(array_intersect($palabrasDoc, $palabrasWeb));
                $minimoEsperado = min(count($palabrasDoc), count($palabrasWeb));

                if ($minimoEsperado > 0 && ($coincidencias >= 2 || $coincidencias === $minimoEsperado)) {
                    $coincide = true;
                    $estado = 'coincide';
                } elseif ($coincidencias === 1) {
                    $estado = 'casi_coincide';
                    $nota = 'Coincidencia parcial en el nombre del propietario. Verifica en el documento.';
                } else {
                    $estado = 'diferente';
                    $nota = 'El propietario de la matrícula no coincide con el propietario actual registrado.';
                }
            } elseif (in_array($clave, ['modelo', 'servicio', 'clase', 'color'], true)) {
                $limpioDoc = str_replace('USO', '', $normDoc);
                $limpioWeb = str_replace('USO', '', $normWeb);
                $longitudMinima = min(strlen($limpioDoc), strlen($limpioWeb));

                if ($longitudMinima >= 3
                    && ($limpioDoc === $limpioWeb || str_contains($limpioWeb, $limpioDoc) || str_contains($limpioDoc, $limpioWeb))) {
                    $coincide = true;
                    $estado = 'coincide';
                } else {
                    $estado = 'diferente';
                }
            } elseif (in_array($clave, ['chasis', 'motor'], true)
                && min(strlen($normDoc), strlen($normWeb)) >= 6
                && levenshtein($normDoc, $normWeb) <= 2) {
                $estado = 'casi_coincide';
                $nota = 'Posible diferencia de lectura OCR. Confirma los caracteres en el documento.';
            } elseif ($clave === 'anio'
                && $normDoc === $anioExpedicion) {
                $nota = 'El año leído coincide con la emisión; no confirma el año de fabricación.';
            } else {
                $estado = 'diferente';
            }

            if (in_array($clave, $camposCriticos, true) && in_array($estado, ['diferente', 'casi_coincide'], true)) {
                $alertasCriticas++;
            }

            $comparacion[] = [
                'campo' => $configuracionCampo['campo'],
                'clave' => $clave,
                'documento' => $valDoc ?: 'No detectado',
                'sistema_web' => $valWeb ?: ($motivoFalloAxis ?: 'No disponible'),
                'coincide' => $coincide,
                'estado' => $estado,
                'nota' => $nota,
                'critico' => in_array($clave, $camposCriticos, true),
            ];
        }

        // E. Procesar Denuncias (Fiscalía General del Estado)
        $resumenFiscalia = [
            'tiene_denuncias' => false,
            'cantidad' => 0,
            'listado' => [],
        ];
        $listaDenunciasRaw = is_array($paquete['fiscalia']['listado'] ?? null) ? $paquete['fiscalia']['listado'] : [];
        if (count($listaDenunciasRaw) > 0) {
            $listadoDenuncias = [];
            foreach ($listaDenunciasRaw as $d) {
                if (! is_array($d)) {
                    continue;
                }
                $listadoDenuncias[] = [
                    'noticia_delito' => (string) ($d['noticiaDelito'] ?? $d['ndd'] ?? 'S/N'),
                    'fecha' => (string) ($d['fecha'] ?? ''),
                    'localidad' => trim(($d['provincia'] ?? '').' / '.($d['canton'] ?? ''), ' /'),
                    'descripcion' => (string) ($d['delito'] ?? $d['descripcion'] ?? 'Noticia de delito registrada'),
                ];
            }
            $resumenFiscalia = [
                'tiene_denuncias' => true,
                'cantidad' => count($listadoDenuncias),
                'listado' => $listadoDenuncias,
            ];
        }

        // F. Procesar Cantidad de Transferencias de Dominio (SRI)
        $infoSRI = $paquete['sri'] ?? [];
        $totalTransferencias = (int) ($infoSRI['total_transferencias'] ?? 0);

        $resumenPropietarios = [
            'total_traspasos' => $totalTransferencias,
            'mensaje' => $totalTransferencias > 0
                ? "Se registran {$totalTransferencias} transferencia(s) de dominio históricas en el SRI."
                : 'No se registran transferencias de dominio previas en el SRI.',
        ];

        $rutaDocumento = $archivo->store('validacion-documentos', 'local');
        if (! is_string($rutaDocumento)) {
            Log::error('Uploaded vehicle document could not be stored for human review.', [
                'plate' => $placa,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo guardar el documento para revisión humana. Inténtalo nuevamente.',
            ], 500);
        }

        try {
            $validacion = ValidacionDocumento::create([
                'placa' => $placa,
                'ruta_documento' => $rutaDocumento,
                'mime_type' => $archivo->getMimeType() ?? 'application/octet-stream',
                'datos_documento' => $datosMatricula,
                'datos_oficiales' => $datosOficiales,
                'comparacion' => $comparacion,
                'resumen_auditoria' => [
                    'multas' => $resumenMultas,
                    'sri' => $resumenSRI,
                    'propietarios' => $resumenPropietarios,
                    'fiscalia' => $resumenFiscalia,
                    'total_deudas' => round($resumenMultas['total'] + $resumenSRI['total'], 2),
                ],
                'alertas_criticas' => $alertasCriticas,
                'estado_revision' => 'borrador',
                'subido_por' => $request->user()->id,
            ]);
        } catch (Throwable $exception) {
            if (! Storage::disk('local')->delete($rutaDocumento)) {
                Log::warning('Registration document could not be removed after draft creation failed.', [
                    'plate' => $placa,
                    'file_path' => $rutaDocumento,
                ]);
            }

            throw $exception;
        }

        return response()->json([
            'success' => true,
            'validacion_id' => $validacion->id,
            'estado_revision' => $validacion->estado_revision,
            'alertas_criticas' => $alertasCriticas,
            'propietario_doc' => [
                'nombre' => $datosMatricula['propietario'] ?? 'No visible',
                'nombre_web' => $datosOficiales['propietario'] ?? 'No disponible',
                'cedula' => $datosMatricula['cedula_propietario'] ?? 'No visible',
            ],
            'comparacion' => $comparacion,
            'multas' => $resumenMultas,
            'sri' => $resumenSRI,
            'propietarios' => $resumenPropietarios,
            'fiscalia' => $resumenFiscalia,
            'total_deudas' => round($resumenMultas['total'] + $resumenSRI['total'], 2),
            'datos_oficiales' => $datosOficiales,
            'datos_documento' => $datosMatricula,
            'campos_axis_pendientes' => $camposAxisPendientes,
            'resumen_auditoria' => $validacion->resumen_auditoria,
        ]);
    }

    protected function ejecutarAuditoriaCompleta(string $placa, string $archivoTemp): int
    {
        $nodeBin = file_exists('C:\laragon\bin\nodejs\node-v22\node.exe')
            ? '"C:\laragon\bin\nodejs\node-v22\node.exe"'
            : 'node';

        // Usa auditoria_completa.cjs si existe, con fallback a consultar_axis.cjs
        $scriptPath = file_exists(base_path('auditoria_completa.cjs'))
            ? base_path('auditoria_completa.cjs')
            : base_path('consultar_axis.cjs');

        $salidaConsola = [];
        $codigoSalida = 0;

        exec("{$nodeBin} \"{$scriptPath}\" {$placa} \"{$archivoTemp}\" 2>&1", $salidaConsola, $codigoSalida);

        return $codigoSalida;
    }

    protected function ejecutarConsultaAxis(string $placa, string $archivoTemp): int
    {
        $nodeBin = file_exists('C:\laragon\bin\nodejs\node-v22\node.exe')
            ? '"C:\laragon\bin\nodejs\node-v22\node.exe"'
            : 'node';
        $scriptPath = base_path('consultar_axis.cjs');
        $salidaAxis = [];
        $codigoSalidaAxis = 0;

        exec("{$nodeBin} \"{$scriptPath}\" {$placa} \"{$archivoTemp}\" 2>&1", $salidaAxis, $codigoSalidaAxis);

        return $codigoSalidaAxis;
    }
}
