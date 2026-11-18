<?php

namespace App\Http\Controllers;

use App\Models\Documentos_vehiculo;
use App\Models\estado_vehiculo as EstadoVehiculo;
use App\Models\imagen_vehiculo as ImagenVehiculo;
use App\Models\Servicio;
use App\Models\TipoVehiculo;
use App\Models\ValidacionDocumento;
use App\Models\vehiculo as Vehiculo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

        $comparacion = $validacion->comparacion ?? [];
        $ultimaFechaRevisionUsuario = trim((string) ($datosIngresoOriginal['ultima_fecha_revision'] ?? ''));
        $ultimaFechaRevisionAxis = trim((string) (
            $validacion->datos_oficiales['ultima_fecha_revision']
            ?? $validacion->datos_oficiales['fecha_ultima_revision_desde']
            ?? ''
        ));

        if ($ultimaFechaRevisionUsuario !== '' || $ultimaFechaRevisionAxis !== '') {
            $parseFechaRevision = static function (string $fecha): ?\DateTimeImmutable {
                $fechaParseada = \DateTimeImmutable::createFromFormat('!d-m-Y H:i:s', $fecha);

                return $fechaParseada !== false && $fechaParseada->format('d-m-Y H:i:s') === $fecha
                    ? $fechaParseada
                    : null;
            };
            $fechaUsuario = $parseFechaRevision($ultimaFechaRevisionUsuario);
            $fechaAxis = $parseFechaRevision($ultimaFechaRevisionAxis);
            $fechaCoincide = $fechaUsuario !== null
                && $fechaAxis !== null
                && $fechaUsuario->format('Y-m-d H:i:s') === $fechaAxis->format('Y-m-d H:i:s');
            $fechaVerificable = $fechaUsuario !== null && $fechaAxis !== null;

            $comparacion[] = [
                'campo' => 'Última fecha de revisión',
                'clave' => 'ultima_fecha_revision',
                'documento' => $ultimaFechaRevisionUsuario ?: 'No ingresada por el usuario',
                'sistema_web' => $ultimaFechaRevisionAxis ?: 'No disponible',
                'coincide' => $fechaCoincide,
                'estado' => ! $fechaVerificable ? 'no_verificable' : ($fechaCoincide ? 'coincide' : 'diferente'),
                'nota' => ! $fechaVerificable
                    ? 'No se pudo comparar la fecha ingresada con la fecha de AxisCloud.'
                    : '',
                'critico' => false,
            ];
        }

        $lugarRevision = trim((string) ($datosIngresoOriginal['lugar_revision'] ?? ''));

        if ($lugarRevision !== '') {
            $comparacion[] = [
                'campo' => 'Lugar de revisión (dato informativo)',
                'clave' => 'lugar_revision',
                'documento' => $lugarRevision,
                'sistema_web' => 'No se compara con AxisCloud',
                'coincide' => false,
                'estado' => 'informativo',
                'nota' => 'Dato ingresado por el usuario; se muestra solo como información.',
                'critico' => false,
            ];
        }

        return Inertia::render('ValidacionDocumentos/Show', [
            'validacion' => [
                'id' => $validacion->id,
                'placa' => $validacion->placa,
                'estado_revision' => $validacion->estado_revision,
                'datos_documento' => $validacion->datos_documento,
                'datos_oficiales' => $validacion->datos_oficiales,
                'comparacion' => $comparacion,
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

    public function escanearYCompararCuv(Request $request, ValidacionDocumento $validacion): JsonResponse
    {
        set_time_limit(180);

        abort_unless($validacion->estado_revision !== 'borrador', 404);

        if (! $request->hasFile('documento_cuv') && $request->hasFile('documento')) {
            $request->files->set('documento_cuv', $request->file('documento'));
        }

        $request->validate([
            'documento_cuv' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $archivo = $request->file('documento_cuv');
        $mimeType = $archivo->getMimeType();
        $archivoBytes = file_get_contents($archivo->getRealPath());

        if (! is_string($archivoBytes)) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo leer el documento CUV subido. Vuelve a seleccionarlo e inténtalo otra vez.',
            ], 422);
        }

        // 1. Procesamiento de imagen sin perder nitidez en letras pequeñas
        $base64Data = null;
        if (is_string($mimeType) && str_starts_with($mimeType, 'image/') && function_exists('imagecreatefromstring')) {
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

        $prompt = 'Analiza este Certificado Único Vehicular (CUV) de Ecuador. Extrae solo los datos que sean visibles; no inventes ni deduzcas valores. '
            .'Devuelve únicamente un objeto JSON con estas claves exactas: '
            .'{"placa": "", "chasis": "", "motor": "", "marca": "", "modelo": "", "anio": "", "anio_expedicion": "", "fecha_emision": "", "fecha_caduca": "", "color": "", "clase": "", "servicio": "", "propietario": "", "cedula_propietario": ""}. '
            .'En "anio" indica únicamente el año de fabricación/modelo y en "anio_expedicion" el año de emisión o certificado. '
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
            Log::warning('Gemini CUV OCR request could not connect.', [
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

            Log::warning('Gemini CUV OCR request failed.', [
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
                $message = "Gemini no pudo procesar el CUV (HTTP {$httpStatus}). Revisa la configuración del servicio.";
            }

            return response()->json([
                'success' => false,
                'message' => $message,
            ], $httpStatus === 429 ? 503 : 502);
        }

        $textoIA = $responseIA->json('candidates.0.content.parts.0.text');
        $datosCuv = is_string($textoIA) ? json_decode($textoIA, true) : null;

        if (! is_array($datosCuv) || empty($datosCuv['placa'])) {
            return response()->json([
                'success' => false,
                'message' => 'La IA no logró identificar una placa legible en el CUV subido.',
            ], 422);
        }

        // Limpieza de placa ecuatoriana extraída del CUV
        $placaCuv = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim((string) $datosCuv['placa'])));
        if (strlen($placaCuv) >= 6) {
            $letras = strtr(substr($placaCuv, 0, 3), ['0' => 'O', '1' => 'I', '8' => 'B', '5' => 'S']);
            $numeros = strtr(substr($placaCuv, 3), ['O' => '0', 'I' => '1', 'B' => '8', 'S' => '5']);
            $placaCuv = $letras.$numeros;
        }
        if (preg_match('/^([A-Z]{3})(\d{3})$/', $placaCuv, $partes)) {
            $placaCuv = $partes[1].'0'.$partes[2];
        }

        if (! preg_match('/^[A-Z0-9]{6,10}$/D', $placaCuv)) {
            return response()->json([
                'success' => false,
                'message' => 'La IA devolvió una placa con formato no válido en el CUV. Verifica el documento e inténtalo otra vez.',
            ], 422);
        }

        $datosCuv['placa'] = $placaCuv;
        $datosMatricula = is_array($validacion->datos_documento) ? $validacion->datos_documento : [];
        if (empty($datosMatricula['placa'])) {
            $datosMatricula['placa'] = $validacion->placa;
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
            'placa' => ['campo' => 'Placa', 'documento' => 'placa', 'cuv' => 'placa'],
            'propietario' => ['campo' => 'Propietario Actual', 'documento' => 'propietario', 'cuv' => 'propietario'],
            'cedula_propietario' => ['campo' => 'Cédula / RUC del Propietario', 'documento' => 'cedula_propietario', 'cuv' => 'cedula_propietario'],
            'chasis' => ['campo' => 'Chasis (VIN)', 'documento' => 'chasis', 'cuv' => 'chasis'],
            'motor' => ['campo' => 'Número de Motor', 'documento' => 'motor', 'cuv' => 'motor'],
            'marca' => ['campo' => 'Marca', 'documento' => 'marca', 'cuv' => 'marca'],
            'modelo' => ['campo' => 'Modelo', 'documento' => 'modelo', 'cuv' => 'modelo'],
            'anio' => ['campo' => 'Año de Fabricación', 'documento' => 'anio', 'cuv' => 'anio'],
            'fecha_emision' => ['campo' => 'Fecha de emisión', 'documento' => 'fecha_emision', 'cuv' => 'fecha_emision'],
            'fecha_caduca' => ['campo' => 'Fecha de caducidad', 'documento' => 'fecha_caduca', 'cuv' => 'fecha_caduca'],
            'color' => ['campo' => 'Color Principal', 'documento' => 'color', 'cuv' => 'color'],
            'clase' => ['campo' => 'Clase de Vehículo', 'documento' => 'clase', 'cuv' => 'clase'],
            'servicio' => ['campo' => 'Tipo de Servicio', 'documento' => 'servicio', 'cuv' => 'servicio'],
        ];

        $camposFecha = ['fecha_emision', 'fecha_caduca'];
        $camposCriticos = ['chasis', 'motor', 'placa', 'propietario', 'cedula_propietario'];
        $comparacion = [];
        $alertasCriticas = 0;
        $anioExpedicionMatricula = $normalizar($datosMatricula['anio_expedicion'] ?? '');
        $anioExpedicionCuv = $normalizar($datosCuv['anio_expedicion'] ?? '');

        foreach ($camposComparar as $clave => $configuracionCampo) {
            $valDoc = trim((string) ($datosMatricula[$configuracionCampo['documento']] ?? ''));
            $valCuv = trim((string) ($datosCuv[$configuracionCampo['cuv']] ?? ''));
            $normDoc = $normalizar($valDoc);
            $normCuv = $normalizar($valCuv);

            if ($clave === 'placa' && strlen($normDoc) >= 6) {
                if (preg_match('/^([A-Z]{3})(\d{3})$/', $normDoc, $partesDoc)) {
                    $normDoc = $partesDoc[1].'0'.$partesDoc[2];
                }
            }

            $coincide = false;
            $estado = 'no_verificable';
            $nota = '';

            if ($normDoc === '' || $normCuv === '') {
                $nota = $normDoc === ''
                    ? 'Dato no legible en la matrícula.'
                    : 'Dato no legible en el CUV.';
            } elseif (in_array($clave, $camposFecha, true)) {
                $fechaDocumento = $normalizarFecha($valDoc);
                $fechaCuv = $normalizarFecha($valCuv);

                if ($fechaDocumento === null || $fechaCuv === null) {
                    $nota = 'No se pudo interpretar una de las fechas; confirma el formato en los documentos.';
                } elseif ($fechaDocumento === $fechaCuv) {
                    $coincide = true;
                    $estado = 'coincide';
                } else {
                    $estado = 'diferente';
                    $nota = 'La fecha de la matrícula no coincide con la fecha del CUV.';
                }
            } elseif ($normDoc === $normCuv) {
                $coincide = true;
                $estado = 'coincide';
            } elseif ($clave === 'propietario') {
                $limpiarPalabras = fn (string $txt): array => array_values(array_filter(
                    explode(' ', preg_replace('/[^A-Z\s]/', '', strtr(mb_strtoupper($txt, 'UTF-8'), [
                        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
                    ])) ?? ''),
                    fn (string $w): bool => strlen($w) >= 2
                ));

                $palabrasDoc = $limpiarPalabras($valDoc);
                $palabrasCuv = $limpiarPalabras($valCuv);
                $coincidencias = count(array_intersect($palabrasDoc, $palabrasCuv));
                $minimoEsperado = min(count($palabrasDoc), count($palabrasCuv));

                if ($minimoEsperado > 0 && ($coincidencias >= 2 || $coincidencias === $minimoEsperado)) {
                    $coincide = true;
                    $estado = 'coincide';
                } elseif ($coincidencias === 1) {
                    $estado = 'casi_coincide';
                    $nota = 'Coincidencia parcial en el nombre del propietario. Verifica en ambos documentos.';
                } else {
                    $estado = 'diferente';
                    $nota = 'El propietario de la matrícula no coincide con el propietario del CUV.';
                }
            } elseif (in_array($clave, ['modelo', 'servicio', 'clase', 'color'], true)) {
                $limpioDoc = str_replace('USO', '', $normDoc);
                $limpioCuv = str_replace('USO', '', $normCuv);
                $longitudMinima = min(strlen($limpioDoc), strlen($limpioCuv));

                if ($longitudMinima >= 3
                    && ($limpioDoc === $limpioCuv || str_contains($limpioCuv, $limpioDoc) || str_contains($limpioDoc, $limpioCuv))) {
                    $coincide = true;
                    $estado = 'coincide';
                } else {
                    $estado = 'diferente';
                }
            } elseif (in_array($clave, ['chasis', 'motor'], true)
                && min(strlen($normDoc), strlen($normCuv)) >= 6
                && levenshtein($normDoc, $normCuv) <= 2) {
                $estado = 'casi_coincide';
                $nota = 'Posible diferencia de lectura OCR. Confirma los caracteres en la matrícula y el CUV.';
            } elseif ($clave === 'anio'
                && ($normDoc === $anioExpedicionMatricula || $normCuv === $anioExpedicionCuv)) {
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
                'cuv' => $valCuv ?: 'No detectado',
                'sistema_web' => $valCuv ?: 'No detectado',
                'coincide' => $coincide,
                'estado' => $estado,
                'nota' => $nota,
                'critico' => in_array($clave, $camposCriticos, true),
            ];
        }

        $rutaCuvNueva = $archivo->store("validacion-documentos/{$validacion->id}/cuv", 'local');
        if (! is_string($rutaCuvNueva)) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo guardar el CUV para revisión. Inténtalo nuevamente.',
            ], 500);
        }

        $rutaCuvAnterior = $validacion->ruta_cuv;
        $resumenCuv = [
            'alertas_criticas' => $alertasCriticas,
            'datos_cuv' => $datosCuv,
            'comparacion' => $comparacion,
            'propietario_comparacion' => [
                'nombre_matricula' => $datosMatricula['propietario'] ?? 'No visible',
                'cedula_matricula' => $datosMatricula['cedula_propietario'] ?? 'No visible',
                'nombre_cuv' => $datosCuv['propietario'] ?? 'No visible',
                'cedula_cuv' => $datosCuv['cedula_propietario'] ?? 'No visible',
            ],
            'escaneado_en' => now()->toIso8601String(),
        ];

        try {
            DB::transaction(function () use ($validacion, $rutaCuvNueva, $resumenCuv): void {
                $validacionBloqueada = ValidacionDocumento::query()
                    ->whereKey($validacion->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $resumenAuditoria = is_array($validacionBloqueada->resumen_auditoria)
                    ? $validacionBloqueada->resumen_auditoria
                    : [];
                $resumenAuditoria['cuv'] = $resumenCuv;

                $validacionBloqueada->update([
                    'ruta_cuv' => $rutaCuvNueva,
                    'resumen_auditoria' => $resumenAuditoria,
                ]);
            });
        } catch (Throwable $exception) {
            if (! Storage::disk('local')->delete($rutaCuvNueva)) {
                Log::warning('Uploaded CUV could not be removed after scan persistence failed.', [
                    'validacion_id' => $validacion->id,
                    'file_path' => $rutaCuvNueva,
                ]);
            }

            throw $exception;
        }

        if ($rutaCuvAnterior !== null
            && $rutaCuvAnterior !== $rutaCuvNueva
            && ! Storage::disk('local')->delete($rutaCuvAnterior)) {
            Log::warning('A replaced CUV could not be removed after scanning a new CUV.', [
                'validacion_id' => $validacion->id,
                'file_path' => $rutaCuvAnterior,
            ]);
        }

        return response()->json([
            'success' => true,
            'validacion_id' => $validacion->id,
            'alertas_criticas' => $alertasCriticas,
            'propietario_comparacion' => $resumenCuv['propietario_comparacion'],
            'datos_matricula' => $datosMatricula,
            'datos_cuv' => $datosCuv,
            'comparacion' => $comparacion,
            'cuv_url' => route('validacion-documentos.cuv', $validacion),
        ]);
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
            'ultima_fecha_revision' => ['nullable', 'date_format:d-m-Y H:i:s'],
            'lugar_revision' => ['nullable', 'string', 'max:150'],
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
        $fechaRevision = $datosValidados['ultima_fecha_revision'] ?? null;
        $atributosVehiculo = Arr::except($datosValidados, [
            'imagenes',
            'tipo_vehiculo',
            'servicio',
            'ultima_fecha_revision',
            'lugar_revision',
        ]);
        $atributosVehiculo['id_estado'] = EstadoVehiculo::firstOrCreate(['nombre' => 'Creado'])->id_estado;
        $atributosVehiculo['id_tipo_vehiculo'] = $tipoVehiculo === null
            ? null
            : TipoVehiculo::firstOrCreate(['nombre' => $tipoVehiculo])->id_tipo_vehiculo;
        $atributosVehiculo['id_servicio'] = $servicio === null
            ? null
            : Servicio::firstOrCreate(['nombre' => $servicio])->id_servicio;
        $atributosVehiculo['fecha_revision'] = $fechaRevision === null
            ? null
            : Carbon::createFromFormat('d-m-Y H:i:s', $fechaRevision)->format('Y-m-d H:i:s');
        $atributosVehiculo['lugar_revision'] = $datosValidados['lugar_revision'] ?? null;

        $vehiculo = Vehiculo::create($atributosVehiculo);

        $documentosVehiculo = ['id_vehiculo' => $vehiculo->id_vehiculo];

        if ($validacion->ruta_documento !== null) {
            $documentosVehiculo['ruta_matricula'] = $validacion->ruta_documento;
        }

        if ($validacion->ruta_cuv !== null) {
            if (! Storage::disk('local')->exists($validacion->ruta_cuv)) {
                throw ValidationException::withMessages([
                    'decision' => 'No se encontró el CUV adjunto al expediente. Verifica el archivo antes de aprobarlo.',
                ]);
            }

            $documentosVehiculo['ruta_cuv'] = $validacion->ruta_cuv;
        }

        if (count($documentosVehiculo) > 1) {
            Documentos_vehiculo::create($documentosVehiculo);
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
