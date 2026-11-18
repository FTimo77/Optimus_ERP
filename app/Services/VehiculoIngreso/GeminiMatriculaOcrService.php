<?php

namespace App\Services\VehiculoIngreso;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeminiMatriculaOcrService
{
    /**
     * Extrae los datos estructurados de una matrícula vehicular ecuatoriana usando Gemini API.
     *
     * @return array{ok: true, placa: string, datos: array<string, mixed>}|array{ok: false, response: JsonResponse}
     */
    public function extraerDatosMatricula(UploadedFile $archivo): array
    {
        $mimeType = $archivo->getMimeType() ?? 'application/octet-stream';
        $archivoBytes = file_get_contents($archivo->getRealPath());

        if (! is_string($archivoBytes)) {
            return [
                'ok' => false,
                'response' => response()->json([
                    'success' => false,
                    'message' => 'No se pudo leer el documento subido. Vuelve a seleccionarlo e inténtalo otra vez.',
                ], 422),
            ];
        }

        [$base64Data, $mimeType] = $this->prepararContenidoBase64($archivoBytes, $mimeType);

        $apiKey = config('services.gemini.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            return [
                'ok' => false,
                'response' => response()->json([
                    'success' => false,
                    'message' => 'Gemini no está configurado. Define GEMINI_API_KEY en .env y limpia la caché de configuración.',
                ], 503),
            ];
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

            return [
                'ok' => false,
                'response' => response()->json([
                    'success' => false,
                    'message' => 'No se pudo conectar con Gemini. Revisa la conexión del servidor e inténtalo otra vez.',
                ], 503),
            ];
        }

        if (! $responseIA->successful()) {
            return [
                'ok' => false,
                'response' => $this->construirRespuestaErrorGemini($responseIA->status(), $responseIA->json('error.status')),
            ];
        }

        $textoIA = $responseIA->json('candidates.0.content.parts.0.text');
        $datosMatricula = is_string($textoIA) ? json_decode($textoIA, true) : null;

        if (! is_array($datosMatricula) || empty($datosMatricula['placa'])) {
            return [
                'ok' => false,
                'response' => response()->json([
                    'success' => false,
                    'message' => 'La IA no logró identificar una placa legible en el documento subido.',
                ], 422),
            ];
        }

        $placa = $this->normalizarPlacaEcuatoriana((string) $datosMatricula['placa']);

        if (! preg_match('/^[A-Z0-9]{6,10}$/D', $placa)) {
            return [
                'ok' => false,
                'response' => response()->json([
                    'success' => false,
                    'message' => 'La IA devolvió una placa con formato no válido. Verifica el documento e inténtalo otra vez.',
                ], 422),
            ];
        }

        return [
            'ok' => true,
            'placa' => $placa,
            'datos' => $datosMatricula,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function prepararContenidoBase64(string $archivoBytes, string $mimeType): array
    {
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
                    return [base64_encode($jpegNitido), 'image/jpeg'];
                }
            }
        }

        return [base64_encode($archivoBytes), $mimeType];
    }

    private function construirRespuestaErrorGemini(int $httpStatus, mixed $providerStatus): JsonResponse
    {
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

    private function normalizarPlacaEcuatoriana(string $placaRaw): string
    {
        $placa = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($placaRaw)) ?? '');

        if (strlen($placa) >= 6) {
            $letras = strtr(substr($placa, 0, 3), ['0' => 'O', '1' => 'I', '8' => 'B', '5' => 'S']);
            $numeros = strtr(substr($placa, 3), ['O' => '0', 'I' => '1', 'B' => '8', 'S' => '5']);
            $placa = $letras.$numeros;
        }

        if (preg_match('/^([A-Z]{3})(\d{3})$/', $placa, $partes)) {
            $placa = $partes[1].'0'.$partes[2];
        }

        return $placa;
    }
}
