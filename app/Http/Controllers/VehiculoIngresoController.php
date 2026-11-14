<?php

namespace App\Http\Controllers;

use App\Models\clase_vehiculo;
use App\Models\color_vehiculo;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
            DB::transaction(function () use ($validated, $imagePaths): void {
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
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($imagePaths);

            throw $exception;
        }

        return redirect()
            ->route('vehiculos.create')
            ->with('success', 'Vehículo ingresado exitosamente al inventario.');
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

        // Borramos archivo previo si existía
        if (file_exists($archivoTemp)) {
            @unlink($archivoTemp);
        }

        // Ejecutamos Node directamente con exec() nativo de PHP para heredar el entorno Windows intacto
        $nodeBin = file_exists('C:\laragon\bin\nodejs\node-v22\node.exe')
            ? '"C:\laragon\bin\nodejs\node-v22\node.exe"'
            : 'node';

        $comando = "{$nodeBin} \"{$scriptPath}\" {$placa} \"{$archivoTemp}\" 2>&1";
        exec($comando, $salidaConsola, $codigoSalida);

        if (! file_exists($archivoTemp)) {
            return response()->json([
                'success' => false,
                'message' => 'Error al ejecutar Node: '.implode(' ', $salidaConsola),
            ], 500);
        }

        $jsonTexto = file_get_contents($archivoTemp);
        @unlink($archivoTemp); // Limpiamos el archivo temporal

        $respuestaExterna = json_decode($jsonTexto, true);

        if (isset($respuestaExterna['error_script'])) {
            return response()->json([
                'success' => false,
                'message' => 'Aviso de AxisCloud: '.$respuestaExterna['error_script'],
            ], 422);
        }

        // Desempaquetamos el campo "campos" que envía AxisCloud
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

        // Función auxiliar para buscar cualquier etiqueta dentro de los arreglos
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
        $nombreClase = $extraerValor($otrasInfo, 'Clase Veh'); // Ej: AUTOMOVIL
        $tipoVehiculo = $extraerValor($otrasInfo, 'Tipo Veh');  // Ej: SEDAN
        $nombreColor = $extraerValor($otrasInfo, 'Color 1');

        // Si el modelo trae la cilindrada en el nombre (ej. "GOL 1.6 CL COUPE"), sugerimos "1600"
        $cilindraje = $extraerValor($otrasInfo, 'Cilindraje');
        if (! $cilindraje && preg_match('/\b(\d\.\d)\b/', $nombreModelo, $coincidencias)) {
            $cilindraje = (string) ((float) $coincidencias[1] * 1000);
        }

        // Buscamos o creamos automáticamente en tus tablas catálogo de MySQL
        // Buscamos o creamos automáticamente usando los nombres exactos de tus modelos importados arriba
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

        $archivoTemp = storage_path('app/axis_'.bin2hex(random_bytes(12)).'.json');
        if (file_exists($archivoTemp)) {
            @unlink($archivoTemp);
        }

        $codigoSalidaAxis = $this->ejecutarConsultaAxis($placa, $archivoTemp);

        $datosOficiales = [];
        $motivoFalloAxis = null;

        if (file_exists($archivoTemp)) {
            $contenidoArchivo = file_get_contents($archivoTemp);
            @unlink($archivoTemp);

            $respuestaExterna = is_string($contenidoArchivo) ? json_decode($contenidoArchivo, true) : null;

            if (is_array($respuestaExterna) && isset($respuestaExterna['error_script'])) {
                $motivoFalloAxis = $respuestaExterna['error_script'];
            } else {
                $rawAxis = is_array($respuestaExterna) && isset($respuestaExterna['campos'])
                    ? (is_string($respuestaExterna['campos']) ? json_decode($respuestaExterna['campos'], true) : $respuestaExterna['campos'])
                    : (is_array($respuestaExterna) && ! empty($respuestaExterna['lsDatosIdentificacion']) ? $respuestaExterna : null);

                if (is_array($rawAxis) && ! empty($rawAxis['lsDatosIdentificacion'])) {
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
                        'placa' => $placa,
                        'chasis' => $extraerValor($identificacion, 'VIN'),
                        'motor' => $extraerValor($identificacion, 'Motor'),
                        'marca' => $extraerValor($modeloInfo, 'Marca'),
                        'modelo' => $extraerValor($modeloInfo, 'Modelo'),
                        'anio' => $extraerValor($modeloInfo, 'Fabricaci'),
                        'color' => $extraerValor($otrasInfo, 'Color 1'),
                        'clase' => $extraerValor($otrasInfo, 'Clase') ?: $extraerValor($otrasInfo, 'Tipo'),
                        'servicio' => trim((string) ($rawAxis['lsServicio'] ?? '')),
                        'fecha_matricula_desde' => $extraerValor($revisionInfo, 'Matrícula Desde'),
                        'fecha_matricula_hasta' => $extraerValor($revisionInfo, 'Matrícula Hasta'),
                        'fecha_ultima_revision_desde' => $extraerValor($revisionInfo, 'Última Revisión Desde'),
                        'fecha_ultima_revision_hasta' => $extraerValor($revisionInfo, 'Última Revisión Hasta'),
                        'anio_ultima_revision' => $extraerValor($revisionInfo, 'Año Última Revisión'),
                    ];

                } else {
                    $motivoFalloAxis = "Sin registros para {$placa}";
                }
            }
        } else {
            $motivoFalloAxis = 'Sin respuesta del robot';
            Log::warning('AxisCloud vehicle lookup did not produce an output file.', [
                'exit_code' => $codigoSalidaAxis,
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
        $camposCriticos = ['chasis', 'motor', 'placa'];
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
                    : ($motivoFalloAxis ?: 'Dato no disponible en AxisCloud.');
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
                'alertas_criticas' => $alertasCriticas,
                'estado_revision' => 'pendiente',
                'subido_por' => $request->user()->id,
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($rutaDocumento);

            throw $exception;
        }

        return response()->json([
            'success' => true,
            'validacion_id' => $validacion->id,
            'estado_revision' => $validacion->estado_revision,
            'alertas_criticas' => $alertasCriticas,
            'propietario_doc' => [
                'nombre' => $datosMatricula['propietario'] ?? 'No visible',
                'cedula' => $datosMatricula['cedula_propietario'] ?? 'No visible',
            ],
            'comparacion' => $comparacion,
            'datos_oficiales' => $datosOficiales,
            'datos_documento' => $datosMatricula,
        ]);
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
