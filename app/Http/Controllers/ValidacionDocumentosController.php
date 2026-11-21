<?php

namespace App\Http\Controllers;

use App\Models\Credito;
use App\Models\Documentos_vehiculo;
use App\Models\estado_vehiculo as EstadoVehiculo;
use App\Models\imagen_vehiculo as ImagenVehiculo;
use App\Models\Movimientos_vehiculo;
use App\Models\PagoVehiculo;
use App\Models\Servicio;
use App\Models\Tipo_movimiento;
use App\Models\TipoContrato;
use App\Models\TipoVehiculo;
use App\Models\ValidacionDocumento;
use App\Models\vehiculo as Vehiculo;
use App\Services\VehiculoIngreso\AuditoriaVehicularService;
use App\Services\VehiculoIngreso\AxisCloudService;
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
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ValidacionDocumentosController extends Controller
{
    public function index(): Response
    {
        $marcasPorId = DB::table('marca_vehiculos')->pluck('nombre', 'id_marca');

        $validaciones = ValidacionDocumento::query()
            ->where('estado_revision', '!=', 'borrador')
            ->with('subidor:id,name')
            ->latest()
            ->paginate(15)
            ->through(function (ValidacionDocumento $validacion) use ($marcasPorId): array {
                $datosIngreso = is_array($validacion->datos_ingreso) ? $validacion->datos_ingreso : [];
                $marcaIngreso = isset($datosIngreso['id_marca'])
                    ? ($marcasPorId[$datosIngreso['id_marca']] ?? null)
                    : null;
                $modeloIngreso = isset($datosIngreso['modelo']) && trim((string) $datosIngreso['modelo']) !== ''
                    ? trim((string) $datosIngreso['modelo'])
                    : null;

                return [
                    'id' => $validacion->id,
                    'placa' => $validacion->placa,
                    'categoria_ingreso' => $validacion->categoria_ingreso ?? 'particular',
                    'marca' => $validacion->datos_documento['marca'] ?? $marcaIngreso ?? 'No detectada',
                    'modelo' => $validacion->datos_documento['modelo'] ?? $modeloIngreso ?? 'No detectado',
                    'estado_revision' => $validacion->estado_revision,
                    'tiene_contrato' => $validacion->ruta_contrato_generado !== null,
                    'alertas_criticas' => $validacion->alertas_criticas,
                    'subido_por' => $validacion->subidor?->name ?? 'Usuario eliminado',
                    'created_at' => $validacion->created_at?->toIso8601String(),
                ];
            });

        return Inertia::render('ValidacionDocumentos/Index', [
            'validaciones' => $validaciones,
            'pendientes' => ValidacionDocumento::query()->where('estado_revision', 'pendiente')->count(),
        ]);
    }

    public function show(Request $request, ValidacionDocumento $validacion): Response
    {
        abort_unless($validacion->estado_revision !== 'borrador', 404);

        $validacion->load([
            'subidor:id,name',
            'revisor:id,name',
            'tipoContrato:id_tipo_contrato,nombre,requiere_documento',
            'credito.registrador:id,name',
            'pagos' => fn ($query) => $query->with('registrador:id,name')->orderBy('id_pago'),
        ]);

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
                'documento_url' => $validacion->ruta_documento === null
                    ? null
                    : route('validacion-documentos.documento', $validacion),
                'categoria_ingreso' => $validacion->categoria_ingreso ?? 'particular',
                'cuv_url' => $validacion->ruta_cuv === null
                    ? null
                    : route('validacion-documentos.cuv', $validacion),
                'comprobante_url' => $validacion->ruta_comprobante_pago === null
                    ? null
                    : route('validacion-documentos.comprobante', $validacion),
                'forma_pago' => $validacion->forma_pago,
                'id_tipo_contrato' => $validacion->id_tipo_contrato,
                'tipo_contrato' => $validacion->tipoContrato?->nombre,
                'costo_adquisicion' => (float) ($datosIngresoOriginal['costo_adquisicion'] ?? 0),
                'credito' => $validacion->credito === null ? null : [
                    'id_credito' => $validacion->credito->id_credito,
                    'monto_total' => (float) $validacion->credito->monto_total,
                    'fecha_vencimiento' => $validacion->credito->fecha_vencimiento?->format('Y-m-d'),
                    'estado' => $validacion->credito->estado,
                    'observaciones' => $validacion->credito->observaciones,
                    'registrado_por' => $validacion->credito->registrador?->name,
                    'created_at' => $validacion->credito->created_at?->toIso8601String(),
                ],
                'pagos' => $validacion->pagos->map(static fn (PagoVehiculo $pago): array => [
                    'id_pago' => $pago->id_pago,
                    'monto' => (float) $pago->monto,
                    'tipo_pago' => $pago->tipo_pago,
                    'fecha_pago' => $pago->fecha_pago?->format('Y-m-d'),
                    'registrado_por' => $pago->registrador?->name,
                    'comprobante_url' => route('validacion-documentos.pago.comprobante', [$validacion, $pago]),
                ])->values(),
                'ruta_cedula_cliente' => $validacion->ruta_cedula_cliente,
                'datos_cedula_cliente' => $validacion->datos_cedula_cliente,
                'ruta_contrato_generado' => $validacion->ruta_contrato_generado,
                'cedula_url' => $validacion->ruta_cedula_cliente === null
                    ? null
                    : route('validacion-documentos.cedula', $validacion),
                'contrato_url' => $validacion->ruta_contrato_generado === null
                    ? null
                    : route('validacion-documentos.contrato', $validacion),
                'vehiculo_contrato' => $this->resolverDatosVehiculoContrato($validacion, $datosIngresoParaVista),
                'subido_por' => $validacion->subidor?->name ?? 'Usuario eliminado',
                'revisado_por' => $validacion->revisor?->name,
                'created_at' => $validacion->created_at?->toIso8601String(),
                'revisado_en' => $validacion->revisado_en?->toIso8601String(),
            ],
            'tiposContrato' => TipoContrato::query()
                ->orderBy('id_tipo_contrato')
                ->get(['id_tipo_contrato', 'nombre', 'requiere_documento']),
            'success' => session('success'),
        ]);
    }

    public function documento(ValidacionDocumento $validacion): StreamedResponse
    {
        abort_unless($validacion->estado_revision !== 'borrador', 404);
        abort_unless($validacion->ruta_documento !== null, 404);

        $disk = Storage::disk('local');

        abort_unless($disk->exists($validacion->ruta_documento), 404);

        $extension = pathinfo($validacion->ruta_documento, PATHINFO_EXTENSION) ?: 'bin';

        return $disk->download(
            $validacion->ruta_documento,
            "matricula_{$validacion->placa}.{$extension}",
            [
                'Content-Type' => $validacion->mime_type ?? 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function escanearMatricula(Request $request, ValidacionDocumento $validacion): JsonResponse
    {
        set_time_limit(180);

        abort_unless(
            ($validacion->categoria_ingreso ?? 'particular') === 'empresa',
            403,
            'El escaneo de matrícula desde revisión solo está habilitado para vehículos de categoría Empresa.',
        );

        abort_unless(
            $validacion->estado_revision === 'pendiente',
            409,
            'Solo se puede auditar la matrícula mientras el expediente está pendiente de revisión.',
        );

        $request->validate([
            'documento' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        return app(AuditoriaVehicularService::class)->escanearYComparar(
            $request,
            $request->file('documento'),
            fn (string $placa, string $archivoTemp, string $cedula): int => $this->ejecutarAuditoriaCompleta(
                $placa,
                $archivoTemp,
                $cedula,
            ),
            $validacion,
        );
    }

    protected function ejecutarAuditoriaCompleta(string $placa, string $archivoTemp, string $cedula): int
    {
        $nodeBin = file_exists('C:\laragon\bin\nodejs\node-v22\node.exe')
            ? '"C:\laragon\bin\nodejs\node-v22\node.exe"'
            : 'node';

        $scriptPath = file_exists(base_path('auditoria_completa.cjs'))
            ? base_path('auditoria_completa.cjs')
            : base_path('consultar_axis.cjs');

        $salidaConsola = [];
        $cedulaArg = escapeshellarg($cedula);
        $codigoSalida = 0;

        exec("{$nodeBin} \"{$scriptPath}\" {$placa} \"{$archivoTemp}\" {$cedulaArg} 2>&1", $salidaConsola, $codigoSalida);

        return $codigoSalida;
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

        $prompt = 'Analiza este Certificado Único Vehicular (CUV) emitido por la Agencia Nacional de Tránsito de Ecuador. '
            .'Extrae solo los datos que sean visibles; no inventes ni deduzcas valores. '
            .'Devuelve únicamente un objeto JSON con estas claves exactas: '
            .'{"numero_cuv": "", "placa": "", "chasis": "", "motor": "", "marca": "", "modelo": "", "anio": "", "anio_expedicion": "", "fecha_emision": "", "fecha_caduca": "", "ultima_fecha_revision": "", "lugar_revision": "", "color": "", "clase": "", "tipo_vehiculo": "", "servicio": "", "cilindraje": "", "pais": "", "combustible": "", "propietario": "", "cedula_propietario": "", "gravamenes_vigentes": "", "tiene_gravamenes": false, "detalle_gravamenes": [], "bloqueos_vigentes": "", "tiene_bloqueos": false, "detalle_bloqueos": [], "cantidad_propietarios": 0, "tiene_mas_de_un_dueno": false, "historia_dominio": [{"desde": "", "hasta": "", "documento_identidad": "", "nombre": "", "institucion": ""}]}. '
            .'En "anio" indica el "Año de Modelo", en "anio_expedicion" el "Último Año Autorizado", en "fecha_emision" la fecha de "Última Matrícula", en "fecha_caduca" la "Fecha Vigencia Matrícula", en "ultima_fecha_revision" la fecha de "Última Revisión" y en "lugar_revision" el "Lugar de Última Revisión". '
            .'En "cedula_propietario" extrae el número de "Documento de Identidad" de DATOS DEL PROPIETARIO (sin el prefijo "CED - "). '
            .'En "gravamenes_vigentes" y "bloqueos_vigentes" copia el texto exacto de "Información de Gravámenes Vigentes" e "Información de Bloqueos Vigentes"; pon "tiene_gravamenes" o "tiene_bloqueos" en true únicamente si existen gravámenes o bloqueos activos (false si dice "NO TIENE REGISTRADOS"). '
            .'En "historia_dominio" extrae todas las filas de la tabla ".:Historia de Dominio:.", coloca en "cantidad_propietarios" el número total de dueños registrados en esa tabla y pon "tiene_mas_de_un_dueno" en true si hay más de 1 dueño. '
            .'Si un dato no es legible o no aparece, usa una cadena vacía (o arreglo vacío).';

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
        $datosIngreso = is_array($validacion->datos_ingreso) ? $validacion->datos_ingreso : [];

        $normalizar = fn (mixed $valor): string => strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $valor) ?? '');
        $normalizarIdentificacion = static function (mixed $valor) use ($normalizar): string {
            $texto = trim((string) $valor);
            if ($texto === '') {
                return '';
            }

            $sinPrefijo = preg_replace('/^\s*(?:CED(?:ULA)?|RUC|PAS(?:APORTE)?)\s*[-:.\s]*/iu', '', $texto) ?? $texto;
            $soloDigitos = preg_replace('/\D+/', '', $sinPrefijo) ?? '';
            if (preg_match('/^(\d{10})001$/', $soloDigitos, $partesCedula)) {
                $soloDigitos = $partesCedula[1];
            }

            return strlen($soloDigitos) >= 9 ? $soloDigitos : $normalizar($sinPrefijo);
        };
        $normalizarTextoSinTildes = static fn (string $txt): string => trim(
            preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9\s]/', ' ', strtr(mb_strtoupper($txt, 'UTF-8'), [
                'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            ])) ?? '') ?? ''
        );
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
            'placa' => ['campo' => 'Placa', 'documento' => 'placa', 'cuv' => 'placa', 'origen' => 'matricula'],
            'propietario' => ['campo' => 'Propietario Actual', 'documento' => 'propietario', 'cuv' => 'propietario', 'origen' => 'matricula'],
            'cedula_propietario' => ['campo' => 'Cédula / RUC del Propietario', 'documento' => 'cedula_propietario', 'cuv' => 'cedula_propietario', 'origen' => 'matricula'],
            'chasis' => ['campo' => 'Chasis (VIN)', 'documento' => 'chasis', 'cuv' => 'chasis', 'origen' => 'matricula'],
            'motor' => ['campo' => 'Número de Motor', 'documento' => 'motor', 'cuv' => 'motor', 'origen' => 'matricula'],
            'marca' => ['campo' => 'Marca', 'documento' => 'marca', 'cuv' => 'marca', 'origen' => 'matricula'],
            'modelo' => ['campo' => 'Modelo', 'documento' => 'modelo', 'cuv' => 'modelo', 'origen' => 'matricula'],
            'anio' => ['campo' => 'Año de Fabricación', 'documento' => 'anio', 'cuv' => 'anio', 'origen' => 'matricula'],
            'fecha_emision' => ['campo' => 'Fecha de emisión / Última matrícula', 'documento' => 'fecha_emision', 'cuv' => 'fecha_emision', 'origen' => 'matricula'],
            'fecha_caduca' => ['campo' => 'Fecha de caducidad / Vigencia matrícula', 'documento' => 'fecha_caduca', 'cuv' => 'fecha_caduca', 'origen' => 'matricula'],
            'color' => ['campo' => 'Color Principal', 'documento' => 'color', 'cuv' => 'color', 'origen' => 'matricula'],
            'clase' => ['campo' => 'Clase de Vehículo', 'documento' => 'clase', 'cuv' => 'clase', 'origen' => 'matricula'],
            'servicio' => ['campo' => 'Tipo de Servicio', 'documento' => 'servicio', 'cuv' => 'servicio', 'origen' => 'matricula'],
            'ultima_fecha_revision' => [
                'campo' => 'Última fecha de revisión (Formulario de ingreso)',
                'documento' => 'ultima_fecha_revision',
                'cuv' => 'ultima_fecha_revision',
                'origen' => 'formulario_ingreso',
            ],
            'lugar_revision' => [
                'campo' => 'Lugar de última revisión (Formulario de ingreso)',
                'documento' => 'lugar_revision',
                'cuv' => 'lugar_revision',
                'origen' => 'formulario_ingreso',
            ],
        ];

        $camposFecha = ['fecha_emision', 'fecha_caduca', 'ultima_fecha_revision'];
        $camposCriticos = ['chasis', 'motor', 'placa', 'propietario', 'cedula_propietario', 'ultima_fecha_revision', 'lugar_revision'];
        $comparacion = [];
        $alertasCriticas = 0;
        $anioExpedicionMatricula = $normalizar($datosMatricula['anio_expedicion'] ?? '');
        $anioExpedicionCuv = $normalizar($datosCuv['anio_expedicion'] ?? '');

        foreach ($camposComparar as $clave => $configuracionCampo) {
            $esFormularioIngreso = ($configuracionCampo['origen'] ?? 'matricula') === 'formulario_ingreso';
            $fuenteBase = $esFormularioIngreso ? $datosIngreso : $datosMatricula;
            $valDoc = trim((string) ($fuenteBase[$configuracionCampo['documento']] ?? ''));
            $valCuv = trim((string) ($datosCuv[$configuracionCampo['cuv']] ?? ''));
            $normDoc = $clave === 'cedula_propietario' ? $normalizarIdentificacion($valDoc) : $normalizar($valDoc);
            $normCuv = $clave === 'cedula_propietario' ? $normalizarIdentificacion($valCuv) : $normalizar($valCuv);

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
                    ? ($esFormularioIngreso
                        ? 'Dato no enviado por el usuario en el formulario de ingreso.'
                        : 'Dato no legible en la matrícula.')
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
                    $nota = $clave === 'ultima_fecha_revision'
                        ? 'La fecha de revisión enviada en el formulario no coincide con la Última Revisión del CUV.'
                        : 'La fecha de la matrícula no coincide con la fecha del CUV.';
                }
            } elseif ($clave === 'lugar_revision') {
                $lugarUsuarioLimpio = $normalizarTextoSinTildes($valDoc);
                $lugarCuvLimpio = $normalizarTextoSinTildes($valCuv);
                $compactoUsuario = str_replace(' ', '', $lugarUsuarioLimpio);
                $compactoCuv = str_replace(' ', '', $lugarCuvLimpio);

                if ($compactoUsuario === $compactoCuv
                    || (min(strlen($compactoUsuario), strlen($compactoCuv)) >= 4
                        && (str_contains($compactoCuv, $compactoUsuario) || str_contains($compactoUsuario, $compactoCuv)))) {
                    $coincide = true;
                    $estado = 'coincide';
                } else {
                    $stopWordsLugar = ['GAD', 'DE', 'DEL', 'LA', 'EL', 'LOS', 'LAS', 'CENTRO', 'REVISION', 'TECNICA', 'VEHICULAR', 'MUNICIPAL', 'CANTON', 'PROVINCIA', 'EMPRESA', 'PUBLICA', 'TRANSITO', 'MOVILIDAD'];
                    $extraerPalabrasLugar = static fn (string $txt): array => array_values(array_filter(
                        explode(' ', $txt),
                        static fn (string $w): bool => strlen($w) >= 3 && ! in_array($w, $stopWordsLugar, true),
                    ));
                    $palabrasUsuario = $extraerPalabrasLugar($lugarUsuarioLimpio);
                    $palabrasCuv = $extraerPalabrasLugar($lugarCuvLimpio);
                    $interseccionLugar = count(array_intersect($palabrasUsuario, $palabrasCuv));
                    $minPalabrasLugar = min(count($palabrasUsuario), count($palabrasCuv));

                    if ($minPalabrasLugar > 0 && $interseccionLugar === $minPalabrasLugar) {
                        $coincide = true;
                        $estado = 'coincide';
                    } elseif ($interseccionLugar >= 1) {
                        $estado = 'casi_coincide';
                        $nota = 'Coincidencia parcial en el lugar de revisión. Confirma la localidad en el CUV.';
                    } else {
                        $estado = 'diferente';
                        $nota = 'El lugar de revisión enviado en el formulario no coincide con el Lugar de Última Revisión del CUV.';
                    }
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

            $etiquetaVacia = $esFormularioIngreso ? 'No enviado en formulario' : 'No detectado';

            $comparacion[] = [
                'campo' => $configuracionCampo['campo'],
                'clave' => $clave,
                'origen' => $configuracionCampo['origen'],
                'documento' => $valDoc ?: $etiquetaVacia,
                'cuv' => $valCuv ?: 'No detectado',
                'sistema_web' => $valCuv ?: 'No detectado',
                'coincide' => $coincide,
                'estado' => $estado,
                'nota' => $nota,
                'critico' => in_array($clave, $camposCriticos, true),
            ];
        }

        // Evaluación de alertas legales y de dominio del CUV (Bloqueos, Gravámenes y Cantidad de Dueños)
        $esSinRegistro = static function (string $texto) use ($normalizarTextoSinTildes): bool {
            $limpio = $normalizarTextoSinTildes($texto);

            return $limpio === ''
                || str_contains($limpio, 'NO TIENE REGISTRADO')
                || str_contains($limpio, 'NO REGISTRA')
                || str_contains($limpio, 'SIN REGISTRO')
                || str_contains($limpio, 'SIN GRAVAMEN')
                || str_contains($limpio, 'SIN BLOQUEO')
                || in_array($limpio, ['NO', 'NINGUNO', 'NINGUNA', 'N A', 'NA'], true);
        };

        $evaluarEstadoRestriccion = static function (
            mixed $textoRaw,
            mixed $flagRaw,
            mixed $detalleRaw,
            string $nombreRestriccion,
        ) use ($esSinRegistro): array {
            $texto = is_scalar($textoRaw) ? trim((string) $textoRaw) : '';
            $detalles = [];

            if (is_array($detalleRaw)) {
                foreach ($detalleRaw as $item) {
                    $linea = is_scalar($item)
                        ? trim((string) $item)
                        : (is_array($item) ? trim(implode(' · ', array_filter(array_map(
                            static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '',
                            $item,
                        )))) : '');

                    if ($linea !== '' && ! $esSinRegistro($linea)) {
                        $detalles[] = $linea;
                    }
                }
            }

            $textoIndicaSinRegistro = $texto !== '' && $esSinRegistro($texto);
            $tieneRestriccion = count($detalles) > 0
                || ($texto !== '' && ! $textoIndicaSinRegistro)
                || ($flagRaw === true && ! $textoIndicaSinRegistro);

            if ($tieneRestriccion && $detalles === [] && $texto !== '') {
                $detalles[] = $texto;
            }

            return [
                'tiene' => $tieneRestriccion,
                'estado_texto' => $texto !== ''
                    ? $texto
                    : ($tieneRestriccion ? "CON {$nombreRestriccion} VIGENTES" : 'NO TIENE REGISTRADOS.'),
                'detalle' => $detalles,
                'mensaje' => $tieneRestriccion
                    ? "¡Alerta! El CUV registra {$nombreRestriccion} vigentes sobre el vehículo."
                    : "Sin {$nombreRestriccion} vigentes registrados en el CUV.",
            ];
        };

        $alertaGravamenes = $evaluarEstadoRestriccion(
            $datosCuv['gravamenes_vigentes'] ?? null,
            $datosCuv['tiene_gravamenes'] ?? false,
            $datosCuv['detalle_gravamenes'] ?? [],
            'gravámenes',
        );
        $alertaBloqueos = $evaluarEstadoRestriccion(
            $datosCuv['bloqueos_vigentes'] ?? null,
            $datosCuv['tiene_bloqueos'] ?? false,
            $datosCuv['detalle_bloqueos'] ?? [],
            'bloqueos',
        );

        // Historia de Dominio (Propietarios históricos en el CUV)
        $historiaDominioRaw = is_array($datosCuv['historia_dominio'] ?? null) ? $datosCuv['historia_dominio'] : [];
        $historiaDominio = [];

        foreach ($historiaDominioRaw as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $nombre = trim((string) ($fila['nombre'] ?? $fila['nombre_completo'] ?? ''));
            $docId = trim((string) ($fila['documento_identidad'] ?? $fila['doc_id'] ?? $fila['cedula'] ?? ''));
            $desde = trim((string) ($fila['desde'] ?? ''));
            $hasta = trim((string) ($fila['hasta'] ?? ''));
            $institucion = trim((string) ($fila['institucion'] ?? ''));

            if ($nombre === '' && $docId === '') {
                continue;
            }

            $historiaDominio[] = [
                'desde' => $desde ?: 'No registrado',
                'hasta' => $hasta ?: 'NO REGISTRADO',
                'documento_identidad' => $docId ?: 'S/N',
                'nombre' => $nombre ?: 'No visible',
                'institucion' => $institucion ?: 'No registrada',
            ];
        }

        $cantidadPropietariosReportada = is_numeric($datosCuv['cantidad_propietarios'] ?? null)
            ? max(0, (int) $datosCuv['cantidad_propietarios'])
            : 0;
        $cantidadPropietarios = max($cantidadPropietariosReportada, count($historiaDominio));
        if ($cantidadPropietarios === 0 && ! empty($datosCuv['propietario'])) {
            $cantidadPropietarios = 1;
        }

        $tieneMasDeUnDueno = $cantidadPropietarios > 1
            || (($datosCuv['tiene_mas_de_un_dueno'] ?? false) === true && count($historiaDominio) !== 1);

        if ($tieneMasDeUnDueno && $cantidadPropietarios <= 1) {
            $cantidadPropietarios = 2;
        }

        $alertaPropietarios = [
            'tiene_mas_de_un_dueno' => $tieneMasDeUnDueno,
            'cantidad' => $cantidadPropietarios,
            'listado' => $historiaDominio,
            'mensaje' => $tieneMasDeUnDueno
                ? "¡Alerta! El vehículo ha tenido más de un dueño ({$cantidadPropietarios} propietarios registrados en Historia de Dominio)."
                : 'Un solo dueño registrado en la Historia de Dominio del CUV.',
        ];

        $listaAlertasCuv = [];
        if ($alertaBloqueos['tiene']) {
            $alertasCriticas++;
            $listaAlertasCuv[] = $alertaBloqueos['mensaje'];
        }
        if ($alertaGravamenes['tiene']) {
            $alertasCriticas++;
            $listaAlertasCuv[] = $alertaGravamenes['mensaje'];
        }
        if ($alertaPropietarios['tiene_mas_de_un_dueno']) {
            $alertasCriticas++;
            $listaAlertasCuv[] = $alertaPropietarios['mensaje'];
        }

        $alertasCuv = [
            'tiene_alertas' => count($listaAlertasCuv) > 0,
            'mensajes' => $listaAlertasCuv,
            'bloqueos' => $alertaBloqueos,
            'gravamenes' => $alertaGravamenes,
            'propietarios' => $alertaPropietarios,
        ];

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
            'alertas_cuv' => $alertasCuv,
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
            'alertas_cuv' => $alertasCuv,
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
            'id_tipo_contrato' => ['required', 'integer', 'exists:tipo_contrato,id_tipo_contrato'],
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
                    $this->validarDatosIngresoDesdeExpediente($validacionBloqueada);
                }

                $nuevoEstado = $validated['decision'] === 'aprobada'
                    ? 'pendiente_comprobante'
                    : 'rechazada';

                $validacionBloqueada->update([
                    'estado_revision' => $nuevoEstado,
                    'id_tipo_contrato' => (int) $validated['id_tipo_contrato'],
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
            ? 'Documento aprobado. Queda pendiente subir el comprobante de pago para ingresar el vehículo al inventario.'
            : 'Documento rechazado.';

        return to_route('validacion-documentos.show', $validacion)->with('success', $mensaje);
    }

    public function comprobante(ValidacionDocumento $validacion): StreamedResponse
    {
        abort_unless(
            $validacion->ruta_comprobante_pago !== null
                && Storage::disk('local')->exists($validacion->ruta_comprobante_pago),
            404,
        );

        $extension = pathinfo($validacion->ruta_comprobante_pago, PATHINFO_EXTENSION);
        $nombreDescarga = $extension !== ''
            ? "comprobante-{$validacion->placa}.{$extension}"
            : "comprobante-{$validacion->placa}";

        return Storage::disk('local')->download(
            $validacion->ruta_comprobante_pago,
            $nombreDescarga,
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function subirComprobante(Request $request, ValidacionDocumento $validacion): RedirectResponse
    {
        $request->validate([
            'documento_comprobante' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $rutaComprobante = $request->file('documento_comprobante')->store(
            "validacion-documentos/{$validacion->id}/comprobante",
            'local',
        );

        if ($rutaComprobante === false) {
            throw new RuntimeException('No se pudo almacenar el comprobante de pago.');
        }

        $rutaComprobanteAnterior = null;
        $rutasImagenesPublicas = [];
        $rutasImagenesTemporales = [];

        try {
            DB::transaction(function () use (
                $validacion,
                $rutaComprobante,
                &$rutaComprobanteAnterior,
                &$rutasImagenesPublicas,
                &$rutasImagenesTemporales,
            ): void {
                $validacionBloqueada = ValidacionDocumento::query()
                    ->whereKey($validacion->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless(
                    $validacionBloqueada->estado_revision === 'pendiente_comprobante',
                    409,
                    'Este expediente no está pendiente de comprobante de pago.',
                );

                $rutaComprobanteAnterior = $validacionBloqueada->ruta_comprobante_pago;

                $validacionBloqueada->update([
                    'ruta_comprobante_pago' => $rutaComprobante,
                ]);

                $rutasImagenesTemporales = $this->crearVehiculoDesdeExpediente(
                    $validacionBloqueada,
                    $rutasImagenesPublicas,
                );

                $validacionBloqueada->update([
                    'estado_revision' => 'aprobada',
                ]);
            });
        } catch (Throwable $exception) {
            if ($rutasImagenesPublicas !== []
                && ! Storage::disk('public')->delete($rutasImagenesPublicas)) {
                Log::error('Could not remove vehicle images after a failed payment receipt upload.', [
                    'validacion_id' => $validacion->id,
                    'image_paths' => $rutasImagenesPublicas,
                ]);
            }

            if (! Storage::disk('local')->delete($rutaComprobante)) {
                Log::warning('A newly uploaded payment receipt could not be removed after a failed upload.', [
                    'validacion_id' => $validacion->id,
                    'file_path' => $rutaComprobante,
                ]);
            }

            throw $exception;
        }

        if ($rutaComprobanteAnterior !== null
            && $rutaComprobanteAnterior !== $rutaComprobante
            && ! Storage::disk('local')->delete($rutaComprobanteAnterior)) {
            Log::warning('A replaced payment receipt could not be removed from temporary storage.', [
                'validacion_id' => $validacion->id,
                'file_path' => $rutaComprobanteAnterior,
            ]);
        }

        if ($rutasImagenesTemporales !== []
            && ! Storage::disk('local')->delete($rutasImagenesTemporales)) {
            Log::warning('Approved vehicle images could not be removed from temporary storage.', [
                'validacion_id' => $validacion->id,
                'image_paths' => $rutasImagenesTemporales,
            ]);
        }

        return back()->with(
            'success',
            "Comprobante de pago registrado y vehículo {$validacion->placa} incorporado al inventario. Ahora puedes generar la constancia.",
        );
    }

    public function registrarFormaPago(Request $request, ValidacionDocumento $validacion): RedirectResponse
    {
        $datosIngreso = is_array($validacion->datos_ingreso) ? $validacion->datos_ingreso : [];
        $costoAdquisicion = round((float) ($datosIngreso['costo_adquisicion'] ?? 0), 2);

        $formaPago = $request->validate([
            'forma_pago' => ['required', Rule::in(['contado', 'cuotas', 'credito'])],
        ])['forma_pago'];

        $reglasEspecificas = match ($formaPago) {
            'credito' => [
                'fecha_vencimiento' => ['required', 'date'],
                'observaciones' => ['nullable', 'string', 'max:1000'],
            ],
            'contado' => [
                'documento_comprobante' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
                'fecha_pago' => ['nullable', 'date'],
            ],
            'cuotas' => [
                'cuotas' => ['required', 'array', 'min:1'],
                'cuotas.*.comprobante' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
                'cuotas.*.monto' => ['required', 'numeric', 'gt:0'],
                'cuotas.*.fecha_pago' => ['nullable', 'date'],
            ],
        };

        $validated = array_merge(
            ['forma_pago' => $formaPago],
            $request->validate($reglasEspecificas),
        );

        $rutasSubidas = [];
        $pagosPreparados = [];

        if ($formaPago === 'cuotas') {
            $sumaCuotas = round(
                array_reduce(
                    $validated['cuotas'],
                    static fn (float $carry, array $item): float => $carry + (float) $item['monto'],
                    0.0,
                ),
                2,
            );

            if ($costoAdquisicion > 0 && $sumaCuotas > ($costoAdquisicion + 0.01)) {
                throw ValidationException::withMessages([
                    'cuotas' => 'La suma de las cuotas ($'.number_format($sumaCuotas, 2).') no puede exceder el costo de adquisición ($'.number_format($costoAdquisicion, 2).').',
                ]);
            }

            foreach ($validated['cuotas'] as $index => $cuota) {
                $archivoCuota = $request->file("cuotas.{$index}.comprobante");
                $ruta = $archivoCuota->store("validacion-documentos/{$validacion->id}/pagos", 'local');
                if ($ruta === false) {
                    Storage::disk('local')->delete($rutasSubidas);
                    throw new RuntimeException('No se pudo almacenar uno de los comprobantes de cuota.');
                }

                $rutasSubidas[] = $ruta;
                $pagosPreparados[] = [
                    'ruta_comprobante' => $ruta,
                    'monto' => round((float) $cuota['monto'], 2),
                    'tipo_pago' => 'cuota',
                    'fecha_pago' => ! empty($cuota['fecha_pago']) ? $cuota['fecha_pago'] : now()->format('Y-m-d'),
                ];
            }
        } elseif ($formaPago === 'contado') {
            $archivoContado = $request->file('documento_comprobante');
            $ruta = $archivoContado->store("validacion-documentos/{$validacion->id}/pagos", 'local');
            if ($ruta === false) {
                throw new RuntimeException('No se pudo almacenar el comprobante de pago de contado.');
            }

            $rutasSubidas[] = $ruta;
            $pagosPreparados[] = [
                'ruta_comprobante' => $ruta,
                'monto' => $costoAdquisicion,
                'tipo_pago' => 'contado',
                'fecha_pago' => ! empty($validated['fecha_pago']) ? $validated['fecha_pago'] : now()->format('Y-m-d'),
            ];
        }

        $rutasImagenesPublicas = [];
        $rutasImagenesTemporales = [];

        try {
            DB::transaction(function () use (
                $request,
                $validacion,
                $formaPago,
                $validated,
                $costoAdquisicion,
                $pagosPreparados,
                &$rutasImagenesPublicas,
                &$rutasImagenesTemporales,
            ): void {
                $validacionBloqueada = ValidacionDocumento::query()
                    ->whereKey($validacion->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless(
                    $validacionBloqueada->estado_revision === 'pendiente_comprobante',
                    409,
                    'Este expediente no está pendiente de registro de forma de pago.',
                );

                if ($pagosPreparados !== []) {
                    $validacionBloqueada->update([
                        'ruta_comprobante_pago' => $pagosPreparados[0]['ruta_comprobante'],
                    ]);
                }

                $rutasImagenesTemporales = $this->crearVehiculoDesdeExpediente(
                    $validacionBloqueada,
                    $rutasImagenesPublicas,
                );

                $estadoInventario = EstadoVehiculo::firstOrCreate(['nombre' => 'En Inventario']);
                Vehiculo::query()
                    ->whereKey($validacionBloqueada->placa)
                    ->update(['id_estado' => $estadoInventario->id_estado]);

                if ($formaPago === 'credito') {
                    Credito::create([
                        'validacion_documento_id' => $validacionBloqueada->id,
                        'id_vehiculo' => $validacionBloqueada->placa,
                        'monto_total' => $costoAdquisicion,
                        'fecha_vencimiento' => $validated['fecha_vencimiento'],
                        'estado' => 'pendiente',
                        'observaciones' => trim((string) ($validated['observaciones'] ?? '')) ?: null,
                        'registrado_por' => $request->user()->id,
                    ]);
                } else {
                    foreach ($pagosPreparados as $pagoData) {
                        PagoVehiculo::create([
                            'validacion_documento_id' => $validacionBloqueada->id,
                            'id_vehiculo' => $validacionBloqueada->placa,
                            'ruta_comprobante' => $pagoData['ruta_comprobante'],
                            'monto' => $pagoData['monto'],
                            'tipo_pago' => $pagoData['tipo_pago'],
                            'fecha_pago' => $pagoData['fecha_pago'],
                            'registrado_por' => $request->user()->id,
                        ]);
                    }
                }

                $tipoMovimiento = Tipo_movimiento::firstOrCreate(['nombre' => 'Ingreso a Inventario']);
                $etiquetaPago = match ($formaPago) {
                    'contado' => 'Contado',
                    'cuotas' => 'Cuotas ('.count($pagosPreparados).' abono(s))',
                    'credito' => 'Crédito (vence '.$validated['fecha_vencimiento'].')',
                };

                Movimientos_vehiculo::create([
                    'id_vehiculo' => $validacionBloqueada->placa,
                    'id_tipo_movimiento' => $tipoMovimiento->id_tipo_movimiento,
                    'id_usuario' => $request->user()->id,
                    'descripcion' => sprintf(
                        'Ingreso efectivo al inventario · Forma de pago: %s · Costo de adquisición: $%s.',
                        $etiquetaPago,
                        number_format($costoAdquisicion, 2, '.', ','),
                    ),
                    'fecha_movimiento' => now(),
                ]);

                $validacionBloqueada->update([
                    'estado_revision' => 'aprobada',
                    'forma_pago' => $formaPago,
                ]);
            });
        } catch (Throwable $exception) {
            if ($rutasImagenesPublicas !== []) {
                Storage::disk('public')->delete($rutasImagenesPublicas);
            }

            if ($rutasSubidas !== []) {
                Storage::disk('local')->delete($rutasSubidas);
            }

            throw $exception;
        }

        if ($rutasImagenesTemporales !== []
            && ! Storage::disk('local')->delete($rutasImagenesTemporales)) {
            Log::warning('Approved vehicle images could not be removed from temporary storage.', [
                'validacion_id' => $validacion->id,
                'image_paths' => $rutasImagenesTemporales,
            ]);
        }

        return back()->with(
            'success',
            "Forma de pago registrada ({$formaPago}), vehículo {$validacion->placa} incorporado al inventario y movimiento asentado en Kardex.",
        );
    }

    public function descargarComprobantePago(ValidacionDocumento $validacion, PagoVehiculo $pago): StreamedResponse
    {
        abort_unless(
            $pago->validacion_documento_id === $validacion->id
                && Storage::disk('local')->exists($pago->ruta_comprobante),
            404,
        );

        $extension = pathinfo($pago->ruta_comprobante, PATHINFO_EXTENSION);
        $nombreDescarga = $extension !== ''
            ? "comprobante-pago-{$validacion->placa}-{$pago->id_pago}.{$extension}"
            : "comprobante-pago-{$validacion->placa}-{$pago->id_pago}";

        return Storage::disk('local')->download(
            $pago->ruta_comprobante,
            $nombreDescarga,
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function registrarAbonoCuota(Request $request, ValidacionDocumento $validacion): RedirectResponse
    {
        abort_unless(
            $validacion->estado_revision === 'aprobada',
            409,
            'Solo se pueden agregar cuotas adicionales en expedientes ya ingresados al inventario.',
        );

        $validated = $request->validate([
            'comprobante' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'monto' => ['required', 'numeric', 'gt:0'],
            'fecha_pago' => ['nullable', 'date'],
        ]);

        $datosIngreso = is_array($validacion->datos_ingreso) ? $validacion->datos_ingreso : [];
        $costoAdquisicion = round((float) ($datosIngreso['costo_adquisicion'] ?? 0), 2);
        $montoNuevo = round((float) $validated['monto'], 2);

        $ruta = $request->file('comprobante')->store("validacion-documentos/{$validacion->id}/pagos", 'local');
        if ($ruta === false) {
            throw new RuntimeException('No se pudo almacenar el comprobante de la cuota.');
        }

        try {
            DB::transaction(function () use ($request, $validacion, $validated, $costoAdquisicion, $montoNuevo, $ruta): void {
                $validacionBloqueada = ValidacionDocumento::query()
                    ->whereKey($validacion->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $pagadoActual = round((float) PagoVehiculo::query()
                    ->where(function ($q) use ($validacionBloqueada): void {
                        $q->where('validacion_documento_id', $validacionBloqueada->id)
                            ->orWhere('id_vehiculo', $validacionBloqueada->placa);
                    })
                    ->sum('monto'), 2);

                if ($costoAdquisicion > 0 && ($pagadoActual + $montoNuevo) > ($costoAdquisicion + 0.01)) {
                    $saldo = max(0, round($costoAdquisicion - $pagadoActual, 2));
                    throw ValidationException::withMessages([
                        'monto' => 'El abono ($'.number_format($montoNuevo, 2).') excede el saldo pendiente ($'.number_format($saldo, 2).').',
                    ]);
                }

                PagoVehiculo::create([
                    'validacion_documento_id' => $validacionBloqueada->id,
                    'id_vehiculo' => $validacionBloqueada->placa,
                    'ruta_comprobante' => $ruta,
                    'monto' => $montoNuevo,
                    'tipo_pago' => 'cuota',
                    'fecha_pago' => ! empty($validated['fecha_pago']) ? $validated['fecha_pago'] : now()->format('Y-m-d'),
                    'registrado_por' => $request->user()->id,
                ]);

                if ($costoAdquisicion > 0 && ($pagadoActual + $montoNuevo) >= ($costoAdquisicion - 0.01)) {
                    Credito::query()
                        ->where('id_vehiculo', $validacionBloqueada->placa)
                        ->where('estado', 'pendiente')
                        ->update(['estado' => 'pagado']);
                }

                if (Vehiculo::query()->whereKey($validacionBloqueada->placa)->exists()) {
                    $tipoMovimiento = Tipo_movimiento::firstOrCreate(['nombre' => 'Abono de Pago']);
                    Movimientos_vehiculo::create([
                        'id_vehiculo' => $validacionBloqueada->placa,
                        'id_tipo_movimiento' => $tipoMovimiento->id_tipo_movimiento,
                        'id_usuario' => $request->user()->id,
                        'descripcion' => sprintf(
                            'Registro de cuota/abono por $%s (acumulado: $%s / $%s).',
                            number_format($montoNuevo, 2, '.', ','),
                            number_format($pagadoActual + $montoNuevo, 2, '.', ','),
                            number_format($costoAdquisicion, 2, '.', ','),
                        ),
                        'fecha_movimiento' => now(),
                    ]);
                }
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($ruta);
            throw $exception;
        }

        return back()->with('success', 'Cuota y comprobante registrados correctamente.');
    }

    public function escanearCedula(Request $request, ValidacionDocumento $validacion): JsonResponse
    {
        $request->validate([
            'documento_cedula' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $archivo = $request->file('documento_cedula');
        $mimeType = $archivo->getMimeType() ?: 'image/jpeg';
        $archivoBytes = file_get_contents($archivo->getRealPath());

        if ($archivoBytes === false) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo leer el archivo de la cédula cargado.',
            ], 422);
        }

        $apiKey = config('services.gemini.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            return response()->json([
                'success' => false,
                'message' => 'Gemini no está configurado. Define GEMINI_API_KEY en .env y limpia la caché de configuración.',
            ], 503);
        }

        $rutaAnterior = $validacion->ruta_cedula_cliente;
        $rutaCedula = $archivo->store('documentos/cedulas', 'local');

        if ($rutaCedula === false) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo almacenar la cédula escaneada.',
            ], 500);
        }

        $base64Data = null;
        if (str_starts_with($mimeType, 'image/') && function_exists('imagecreatefromstring')) {
            $imgRaw = @imagecreatefromstring($archivoBytes);
            if ($imgRaw !== false) {
                $ancho = imagesx($imgRaw);
                $alto = imagesy($imgRaw);
                if ($ancho > 1800) {
                    $nuevoAncho = 1800;
                    $nuevoAlto = (int) floor(($alto / $ancho) * $nuevoAncho);
                    $imgResized = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
                    imagecopyresampled($imgResized, $imgRaw, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);
                    imagedestroy($imgRaw);
                    $imgRaw = $imgResized;
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

        if ($base64Data === null) {
            $base64Data = base64_encode($archivoBytes);
        }

        $prompt = 'Analiza esta cédula de identidad de Ecuador (anverso y/o reverso). '
            .'Extrae únicamente los datos visibles del titular; no inventes ni deduzcas información que no aparezca. '
            .'Devuelve únicamente un objeto JSON válido con estas claves exactas: '
            .'{"nombre_completo": "", "cedula": "", "estado_civil": "", "es_casado": false, "nombre_conyuge": ""}. '
            .'En "nombre_completo" coloca nombres y apellidos completos en mayúsculas tal como figuran en la cédula. '
            .'En "cedula" coloca el número de cédula de 10 dígitos sin espacios ni guiones. '
            .'En "estado_civil" coloca el estado civil exacto (ej. SOLTERO, CASADO, DIVORCIADO, VIUDO, UNIÓN DE HECHO). '
            .'Pon "es_casado" en true únicamente si el estado civil es CASADO o UNIÓN DE HECHO (en caso contrario false). '
            .'En "nombre_conyuge" coloca el nombre completo del cónyuge si aparece visible en la cédula; si no aparece o no aplica, devuelve cadena vacía.';

        $payload = [
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
                'temperature' => 0.0,
            ],
        ];

        try {
            $respuestaGemini = $this->ejecutarEscaneoGemini($apiKey, $payload);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($rutaCedula);
            Log::warning('Error al escanear cédula con escanear_gemini.cjs.', [
                'validacion_id' => $validacion->id,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo completar el escaneo de la cédula con Gemini: '.$exception->getMessage(),
            ], 502);
        }

        if (isset($respuestaGemini['error']['message'])) {
            Storage::disk('local')->delete($rutaCedula);

            return response()->json([
                'success' => false,
                'message' => 'Gemini devolvió un error al escanear la cédula: '.$respuestaGemini['error']['message'],
            ], 502);
        }

        $textoJson = $respuestaGemini['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (! is_string($textoJson) || trim($textoJson) === '') {
            Storage::disk('local')->delete($rutaCedula);

            return response()->json([
                'success' => false,
                'message' => 'Gemini no devolvió datos legibles de la cédula. Intenta con una foto más nítida.',
            ], 422);
        }

        $textoLimpio = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($textoJson)) ?? trim($textoJson);
        $datosExtraidos = json_decode($textoLimpio, true);

        if (! is_array($datosExtraidos)) {
            Storage::disk('local')->delete($rutaCedula);

            return response()->json([
                'success' => false,
                'message' => 'La respuesta de Gemini no tiene un formato JSON válido.',
            ], 422);
        }

        $estadoCivil = strtoupper(trim((string) ($datosExtraidos['estado_civil'] ?? '')));
        $esCasado = filter_var($datosExtraidos['es_casado'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || str_contains($estadoCivil, 'CASAD')
            || str_contains($estadoCivil, 'UNION');

        $datosCedula = [
            'nombre_completo' => strtoupper(trim((string) ($datosExtraidos['nombre_completo'] ?? ''))),
            'cedula' => preg_replace('/[^0-9]/', '', (string) ($datosExtraidos['cedula'] ?? '')) ?? '',
            'estado_civil' => $estadoCivil,
            'es_casado' => $esCasado,
            'nombre_conyuge' => $esCasado ? strtoupper(trim((string) ($datosExtraidos['nombre_conyuge'] ?? ''))) : '',
            'cedula_conyuge' => '',
        ];

        $validacion->update([
            'ruta_cedula_cliente' => $rutaCedula,
            'datos_cedula_cliente' => $datosCedula,
        ]);

        if ($rutaAnterior !== null && $rutaAnterior !== $rutaCedula) {
            Storage::disk('local')->delete($rutaAnterior);
        }

        return response()->json([
            'success' => true,
            'ruta_cedula_cliente' => $rutaCedula,
            'datos_cedula_cliente' => $datosCedula,
            'cedula_url' => route('validacion-documentos.cedula', $validacion),
        ]);
    }

    public function generarConstancia(Request $request, ValidacionDocumento $validacion): JsonResponse
    {
        abort_unless(
            $validacion->estado_revision === 'aprobada',
            409,
            'Debes subir el comprobante de pago antes de generar la constancia.',
        );

        $validated = $request->validate([
            'nombre_completo' => ['required', 'string', 'max:200'],
            'cedula' => ['required', 'string', 'max:30'],
            'estado_civil' => ['nullable', 'string', 'max:60'],
            'es_casado' => ['required', 'boolean'],
            'nombre_conyuge' => ['nullable', 'string', 'max:200', 'required_if:es_casado,true'],
            'cedula_conyuge' => ['nullable', 'string', 'max:30'],
        ]);

        $plantilla = storage_path('app/plantillas/Contrato_compra_venta_vehiculo_editable.docx');
        if (! is_file($plantilla)) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró la plantilla Contrato_compra_venta_vehiculo_editable.docx en storage/app/plantillas/.',
            ], 500);
        }

        $vehiculoDatos = $this->resolverDatosVehiculoContrato($validacion);

        $nombreCompleto = mb_strtoupper(trim($validated['nombre_completo']), 'UTF-8');
        $cedula = trim($validated['cedula']);
        $estadoCivil = mb_strtoupper(trim((string) ($validated['estado_civil'] ?? '')), 'UTF-8');
        $esCasado = (bool) $validated['es_casado'];
        $nombreConyuge = $esCasado ? mb_strtoupper(trim((string) ($validated['nombre_conyuge'] ?? '')), 'UTF-8') : '';
        $cedulaConyuge = $esCasado ? trim((string) ($validated['cedula_conyuge'] ?? '')) : '';

        $ahora = Carbon::now();
        $meses = [
            1 => 'enero',
            2 => 'febrero',
            3 => 'marzo',
            4 => 'abril',
            5 => 'mayo',
            6 => 'junio',
            7 => 'julio',
            8 => 'agosto',
            9 => 'septiembre',
            10 => 'octubre',
            11 => 'noviembre',
            12 => 'diciembre',
        ];
        $diaNum = (int) $ahora->format('j');
        $anioNum = (int) $ahora->format('Y');
        $mesNombre = $meses[(int) $ahora->format('n')] ?? $ahora->format('F');

        $costoAdquisicion = (float) ($vehiculoDatos['costo_adquisicion'] ?? 0);
        $costoFormateado = number_format($costoAdquisicion, 2, '.', ',');
        $costoLetras = $this->montoALetras($costoAdquisicion);

        $rutaRelativa = sprintf(
            'documentos/contratos/contrato_%s_%s.docx',
            preg_replace('/[^A-Z0-9]/i', '', $validacion->placa) ?: $validacion->id,
            $ahora->format('Ymd_His'),
        );
        $rutaAbsoluta = Storage::disk('local')->path($rutaRelativa);
        $directorio = dirname($rutaAbsoluta);

        if (! is_dir($directorio) && ! mkdir($directorio, 0775, true) && ! is_dir($directorio)) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo crear el directorio para guardar el contrato generado.',
            ], 500);
        }

        try {
            $template = new TemplateProcessor($plantilla);
            $template->setValue('dia_letras', $this->numeroALetras($diaNum));
            $template->setValue('dia', (string) $diaNum);
            $template->setValue('mes', $mesNombre);
            $template->setValue('anio_letra', $this->numeroALetras($anioNum));
            $template->setValue('anio', (string) $anioNum);

            $template->setValue('cliente', $nombreCompleto);
            $template->setValue('ci_cliente', $cedula);

            // Primera ocurrencia (cláusula PRIMERA) y segunda ocurrencia (bloque de firma del cónyuge)
            $textoConyugeClausula = $esCasado && $nombreConyuge !== '' ? "y su cónyuge {$nombreConyuge}" : '';
            $textoConyugeFirma = $esCasado ? $nombreConyuge : '';
            $template->setValue('esta_casado', $textoConyugeClausula, 1);
            $template->setValue('esta_casado', $textoConyugeFirma, 1);
            $template->setValue('ci_esta_casado', $esCasado ? $cedulaConyuge : '');

            $template->setValue('marca_vehiculo', (string) ($vehiculoDatos['marca'] ?? ''));
            $template->setValue('modelo_vehiculo ', (string) ($vehiculoDatos['modelo'] ?? ''));
            $template->setValue('modelo_vehiculo', (string) ($vehiculoDatos['modelo'] ?? ''));
            $template->setValue('anio_vehiculo ', (string) ($vehiculoDatos['anio'] ?? ''));
            $template->setValue('anio_vehiculo', (string) ($vehiculoDatos['anio'] ?? ''));
            $template->setValue('color_vehiculo ', (string) ($vehiculoDatos['color'] ?? ''));
            $template->setValue('color_vehiculo', (string) ($vehiculoDatos['color'] ?? ''));
            $template->setValue('chasis_vehiculo ', (string) ($vehiculoDatos['chasis'] ?? ''));
            $template->setValue('chasis_vehiculo', (string) ($vehiculoDatos['chasis'] ?? ''));
            $template->setValue('motor_vehiculo ', (string) ($vehiculoDatos['motor'] ?? ''));
            $template->setValue('motor_vehiculo', (string) ($vehiculoDatos['motor'] ?? ''));
            $template->setValue('id_vehiculo', (string) ($vehiculoDatos['id_vehiculo'] ?? $validacion->placa));

            $template->setValue('costo_adquisicion', $costoFormateado);
            $template->setValue('costo_letras', $costoLetras);

            $template->saveAs($rutaAbsoluta);
        } catch (Throwable $exception) {
            Log::error('Error al generar contrato Word con PhpWord.', [
                'validacion_id' => $validacion->id,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo generar el documento Word del contrato: '.$exception->getMessage(),
            ], 500);
        }

        $rutaAnterior = $validacion->ruta_contrato_generado;
        $datosCedulaActualizados = [
            'nombre_completo' => $nombreCompleto,
            'cedula' => $cedula,
            'estado_civil' => $estadoCivil,
            'es_casado' => $esCasado,
            'nombre_conyuge' => $nombreConyuge,
            'cedula_conyuge' => $cedulaConyuge,
        ];

        $validacion->update([
            'datos_cedula_cliente' => $datosCedulaActualizados,
            'ruta_contrato_generado' => $rutaRelativa,
        ]);

        Documentos_vehiculo::query()
            ->where('id_vehiculo', $validacion->placa)
            ->update([
                'ruta_contrato' => $rutaRelativa,
                'ruta_constancia' => $rutaRelativa,
            ]);

        if ($rutaAnterior !== null && $rutaAnterior !== $rutaRelativa) {
            Storage::disk('local')->delete($rutaAnterior);
        }

        return response()->json([
            'success' => true,
            'ruta_contrato_generado' => $rutaRelativa,
            'contrato_url' => route('validacion-documentos.contrato', $validacion),
            'datos_cedula_cliente' => $datosCedulaActualizados,
        ]);
    }

    public function descargarContrato(ValidacionDocumento $validacion): StreamedResponse
    {
        abort_unless(
            $validacion->ruta_contrato_generado !== null
                && Storage::disk('local')->exists($validacion->ruta_contrato_generado),
            404,
        );

        return Storage::disk('local')->download(
            $validacion->ruta_contrato_generado,
            "contrato-compra-venta-{$validacion->placa}.docx",
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function descargarCedula(ValidacionDocumento $validacion): StreamedResponse
    {
        abort_unless(
            $validacion->ruta_cedula_cliente !== null
                && Storage::disk('local')->exists($validacion->ruta_cedula_cliente),
            404,
        );

        $extension = pathinfo($validacion->ruta_cedula_cliente, PATHINFO_EXTENSION);
        $nombreDescarga = $extension !== ''
            ? "cedula-cliente-{$validacion->placa}.{$extension}"
            : "cedula-cliente-{$validacion->placa}";

        return Storage::disk('local')->download(
            $validacion->ruta_cedula_cliente,
            $nombreDescarga,
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function ejecutarEscaneoGemini(string $apiKey, array $payload): array
    {
        $scriptPath = base_path('escanear_gemini.cjs');
        if (! is_file($scriptPath)) {
            throw new RuntimeException('No se encontró el script escanear_gemini.cjs en la raíz del proyecto.');
        }

        $token = bin2hex(random_bytes(12));
        $archivoEntrada = storage_path("app/gemini_in_{$token}.json");
        $archivoSalida = storage_path("app/gemini_out_{$token}.json");

        try {
            $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if ($jsonPayload === false || file_put_contents($archivoEntrada, $jsonPayload) === false) {
                throw new RuntimeException('No se pudo crear el archivo temporal de entrada para Gemini.');
            }

            $comando = sprintf(
                'node %s %s %s %s 2>&1',
                escapeshellarg($scriptPath),
                escapeshellarg($apiKey),
                escapeshellarg($archivoEntrada),
                escapeshellarg($archivoSalida),
            );

            $salida = [];
            $codigo = 0;
            exec($comando, $salida, $codigo);

            if (! is_file($archivoSalida)) {
                throw new RuntimeException(
                    'escanear_gemini.cjs no generó archivo de salida (código '.$codigo.').',
                );
            }

            $contenido = file_get_contents($archivoSalida);
            $decodificado = is_string($contenido) ? json_decode($contenido, true) : null;

            if (! is_array($decodificado)) {
                throw new RuntimeException('La salida de escanear_gemini.cjs no es un JSON válido.');
            }

            return $decodificado;
        } finally {
            @unlink($archivoEntrada);
            @unlink($archivoSalida);
        }
    }

    /**
     * @param  array<string, mixed>|null  $datosIngresoParaVista
     * @return array<string, mixed>
     */
    private function resolverDatosVehiculoContrato(
        ValidacionDocumento $validacion,
        ?array $datosIngresoParaVista = null,
    ): array {
        $vehiculo = Vehiculo::query()
            ->with(['marca', 'color'])
            ->find($validacion->placa);

        if ($vehiculo !== null) {
            return [
                'id_vehiculo' => $vehiculo->id_vehiculo,
                'marca' => $vehiculo->marca?->nombre ?? '',
                'modelo' => $vehiculo->modelo ?? '',
                'anio' => $vehiculo->anio ?? '',
                'color' => $vehiculo->color?->nombre ?? '',
                'chasis' => $vehiculo->chasis ?? '',
                'motor' => $vehiculo->motor ?? '',
                'costo_adquisicion' => $vehiculo->costo_adquisicion ?? 0,
            ];
        }

        $datosIngreso = is_array($validacion->datos_ingreso) ? $validacion->datos_ingreso : [];
        $marca = $datosIngresoParaVista['id_marca']
            ?? (isset($datosIngreso['id_marca']) ? DB::table('marca_vehiculos')->where('id_marca', $datosIngreso['id_marca'])->value('nombre') : null)
            ?? ($validacion->datos_documento['marca'] ?? '');
        $color = $datosIngresoParaVista['id_color']
            ?? (isset($datosIngreso['id_color']) ? DB::table('color_vehiculos')->where('id_color', $datosIngreso['id_color'])->value('nombre') : null)
            ?? ($validacion->datos_documento['color'] ?? '');

        return [
            'id_vehiculo' => $datosIngreso['id_vehiculo'] ?? $validacion->placa,
            'marca' => (string) $marca,
            'modelo' => (string) ($datosIngreso['modelo'] ?? $validacion->datos_documento['modelo'] ?? ''),
            'anio' => (string) ($datosIngreso['anio'] ?? $validacion->datos_documento['anio'] ?? ''),
            'color' => (string) $color,
            'chasis' => (string) ($datosIngreso['chasis'] ?? $validacion->datos_documento['chasis'] ?? ''),
            'motor' => (string) ($datosIngreso['motor'] ?? $validacion->datos_documento['motor'] ?? ''),
            'costo_adquisicion' => (float) ($datosIngreso['costo_adquisicion'] ?? 0),
        ];
    }

    private function montoALetras(float $monto): string
    {
        $entero = (int) floor($monto);
        $centavos = (int) round(($monto - $entero) * 100);
        if ($centavos >= 100) {
            $entero++;
            $centavos = 0;
        }

        $textoEntero = mb_strtoupper($this->numeroALetras($entero), 'UTF-8');

        return $centavos > 0
            ? sprintf('%s CON %02d/100', $textoEntero, $centavos)
            : $textoEntero;
    }

    private function numeroALetras(int $numero): string
    {
        if ($numero === 0) {
            return 'cero';
        }

        $unidades = [
            '', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
            'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve',
            'veinte', 'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve',
        ];
        $decenas = ['', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
        $centenas = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

        if ($numero < 30) {
            return $unidades[$numero];
        }

        if ($numero < 100) {
            $d = intdiv($numero, 10);
            $u = $numero % 10;

            return $u === 0 ? $decenas[$d] : "{$decenas[$d]} y {$unidades[$u]}";
        }

        if ($numero === 100) {
            return 'cien';
        }

        if ($numero < 1000) {
            $c = intdiv($numero, 100);
            $r = $numero % 100;

            return $r === 0 ? $centenas[$c] : "{$centenas[$c]} {$this->numeroALetras($r)}";
        }

        if ($numero < 1000000) {
            $miles = intdiv($numero, 1000);
            $r = $numero % 1000;
            $prefijo = $miles === 1 ? 'mil' : $this->numeroALetras($miles).' mil';
            $prefijo = preg_replace('/uno mil$/', 'un mil', $prefijo) ?? $prefijo;

            return $r === 0 ? $prefijo : "{$prefijo} {$this->numeroALetras($r)}";
        }

        $millones = intdiv($numero, 1000000);
        $r = $numero % 1000000;
        $prefijo = $millones === 1 ? 'un millón' : $this->numeroALetras($millones).' millones';

        return $r === 0 ? $prefijo : "{$prefijo} {$this->numeroALetras($r)}";
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
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string>}
     */
    private function validarDatosIngresoDesdeExpediente(ValidacionDocumento $validacion): array
    {
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
            'fecha_caducidad_revision' => ['nullable', 'date_format:Y-m-d'],
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

        return [$datosIngreso, $datosValidados, $rutasImagenesTemporales];
    }

    /**
     * @param  array<string>  $rutasImagenesPublicas
     * @return array<string>
     */
    private function crearVehiculoDesdeExpediente(
        ValidacionDocumento $validacion,
        array &$rutasImagenesPublicas,
    ): array {
        [$datosIngreso, $datosValidados, $rutasImagenesTemporales] = $this->validarDatosIngresoDesdeExpediente($validacion);

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
        $atributosVehiculo['id_tipo_contrato'] = $validacion->id_tipo_contrato;
        $atributosVehiculo['metodo_ingreso'] = strtolower(trim((string) ($validacion->categoria_ingreso ?? 'particular'))) ?: 'particular';
        $atributosVehiculo['fecha_revision'] = $fechaRevision === null
            ? null
            : Carbon::createFromFormat('d-m-Y H:i:s', $fechaRevision)->format('Y-m-d H:i:s');
        $atributosVehiculo['fecha_caducidad_revision'] = $datosValidados['fecha_caducidad_revision']
            ?? AxisCloudService::normalizarFechaMysql(
                $validacion->datos_oficiales['fecha_caducidad_revision']
                    ?? $validacion->datos_oficiales['fecha_ultima_revision_hasta']
                    ?? null,
            );
        $atributosVehiculo['lugar_revision'] = $datosValidados['lugar_revision'] ?? null;

        $vehiculo = Vehiculo::create($atributosVehiculo);

        $documentosVehiculo = ['id_vehiculo' => $vehiculo->id_vehiculo];

        if ($validacion->id_tipo_contrato !== null) {
            $documentosVehiculo['id_tipo_contrato'] = $validacion->id_tipo_contrato;
        }

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

        if ($validacion->ruta_comprobante_pago !== null) {
            if (! Storage::disk('local')->exists($validacion->ruta_comprobante_pago)) {
                throw ValidationException::withMessages([
                    'documento_comprobante' => 'No se encontró el comprobante de pago adjunto al expediente.',
                ]);
            }

            $documentosVehiculo['ruta_comprobante_pago'] = $validacion->ruta_comprobante_pago;
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
