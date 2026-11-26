<?php

namespace App\Services\VehiculoIngreso;

use App\Models\ValidacionDocumento;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AuditoriaVehicularService
{
    public function __construct(
        private readonly GeminiMatriculaOcrService $geminiOcrService,
        private readonly VehiculoIngresoService $vehiculoIngresoService,
    ) {}

    /**
     * Escanea la matrícula con IA, ejecuta la auditoría en fuentes oficiales y crea el borrador.
     *
     * @param  Closure(string, string, string): int  $ejecutarAuditoriaCompleta
     */
    public function escanearYComparar(
        Request $request,
        UploadedFile $archivo,
        Closure $ejecutarAuditoriaCompleta,
        ?ValidacionDocumento $validacionExistente = null,
    ): JsonResponse {
        $resultadoOcr = $this->geminiOcrService->extraerDatosMatricula($archivo);

        if (! $resultadoOcr['ok']) {
            return $resultadoOcr['response'];
        }

        $placa = $resultadoOcr['placa'];
        $datosMatricula = $resultadoOcr['datos'];

        $cedulaPropietario = $request->input('cedula_propietario')
            ?? ($datosMatricula['cedula_propietario'] ?? '');
        $cedulaDoc = is_scalar($cedulaPropietario)
            ? preg_replace('/[^0-9]/', '', trim((string) $cedulaPropietario))
            : '';

        if (is_string($cedulaDoc) && preg_match('/^(\d{10})001$/', $cedulaDoc, $partesCedula)) {
            $cedulaDoc = $partesCedula[1];
        }

        $archivoTemp = storage_path('app/auditoria_'.bin2hex(random_bytes(12)).'.json');
        if (file_exists($archivoTemp)) {
            @unlink($archivoTemp);
        }

        $codigoSalidaAuditoria = $ejecutarAuditoriaCompleta($placa, $archivoTemp, (string) $cedulaDoc);

        $paquete = null;
        if (file_exists($archivoTemp)) {
            $contenidoArchivo = file_get_contents($archivoTemp);
            @unlink($archivoTemp);
            $paquete = is_string($contenidoArchivo) ? json_decode($contenidoArchivo, true) : null;
        } else {
            Log::warning('Vehicle full audit lookup did not produce an output file.', [
                'exit_code' => $codigoSalidaAuditoria,
            ]);
        }

        $resumenSRI = $this->procesarSri($paquete);
        $resumenMultas = $this->procesarMultas($paquete);
        $nombrePropietarioWeb = is_array($paquete) ? trim((string) ($paquete['propietario']['nombre'] ?? '')) : '';

        [$datosOficiales, $axisDisponible, $motivoFalloAxis] = $this->procesarAxis(
            $paquete,
            $placa,
            $nombrePropietarioWeb,
            $resumenSRI,
            $resumenMultas,
            file_exists($archivoTemp) || is_array($paquete),
        );

        $camposAxisPendientes = $this->vehiculoIngresoService->camposAxisPendientes($datosOficiales);
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

        [$comparacion, $alertasCriticas] = $this->compararMatriculaConOficiales(
            $datosMatricula,
            $datosOficiales,
            $motivoFalloAxis,
        );

        $resumenFiscalia = $this->procesarFiscaliaPlaca($paquete);
        $resumenFiscaliaCedula = $this->procesarFiscaliaCedula($paquete, (string) $cedulaDoc);
        $resumenPropietarios = $this->procesarTransferenciasSri($paquete);
        $resumenAmt = $this->procesarFuenteMultaDirecta($paquete, 'amt', 'AMT (Quito)');
        $resumenCte = $this->procesarFuenteMultaDirecta($paquete, 'cte', 'CTE (Comisión de Tránsito)');
        $resumenAtm = $this->procesarFuenteMultaDirecta($paquete, 'atm', 'ATM (Guayaquil)');
        $resumenSupa = $this->procesarSupa($paquete, (string) $cedulaDoc);
        $resumenSatje = $this->procesarSatje($paquete, (string) $cedulaDoc);

        $rutaDocumento = $archivo->store('validacion-documentos', config('filesystems.default'));
        if (! is_string($rutaDocumento)) {
            Log::error('Uploaded vehicle document could not be stored for human review.', [
                'plate' => $placa,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo guardar el documento para revisión humana. Inténtalo nuevamente.',
            ], 500);
        }

        $resumenAuditoria = [
            'multas' => $resumenMultas,
            'amt' => $resumenAmt,
            'cte' => $resumenCte,
            'atm' => $resumenAtm,
            'sri' => $resumenSRI,
            'propietarios' => $resumenPropietarios,
            'fiscalia' => $resumenFiscalia,
            'fiscalia_cedula' => $resumenFiscaliaCedula,
            'supa' => $resumenSupa,
            'satje' => $resumenSatje,
            'total_deudas' => round(($resumenMultas['total'] ?? 0) + ($resumenSRI['total'] ?? 0), 2),
        ];

        try {
            if ($validacionExistente !== null) {
                $rutaAnterior = $validacionExistente->ruta_documento;
                $resumenAnterior = is_array($validacionExistente->resumen_auditoria)
                    ? $validacionExistente->resumen_auditoria
                    : [];

                if (isset($resumenAnterior['cuv'])) {
                    $resumenAuditoria['cuv'] = $resumenAnterior['cuv'];
                }

                $validacionExistente->update([
                    'ruta_documento' => $rutaDocumento,
                    'mime_type' => $archivo->getMimeType() ?? 'application/octet-stream',
                    'datos_documento' => $datosMatricula,
                    'datos_oficiales' => $datosOficiales,
                    'comparacion' => $comparacion,
                    'resumen_auditoria' => $resumenAuditoria,
                    'alertas_criticas' => $alertasCriticas,
                ]);

                if ($rutaAnterior !== null && $rutaAnterior !== $rutaDocumento) {
                    Storage::disk(config('filesystems.default'))->delete($rutaAnterior);
                }

                $validacion = $validacionExistente->fresh();
            } else {
                $validacion = ValidacionDocumento::create([
                    'placa' => $placa,
                    'categoria_ingreso' => 'particular',
                    'ruta_documento' => $rutaDocumento,
                    'mime_type' => $archivo->getMimeType() ?? 'application/octet-stream',
                    'datos_documento' => $datosMatricula,
                    'datos_oficiales' => $datosOficiales,
                    'comparacion' => $comparacion,
                    'resumen_auditoria' => $resumenAuditoria,
                    'alertas_criticas' => $alertasCriticas,
                    'estado_revision' => 'borrador',
                    'subido_por' => $request->user()->id,
                ]);
            }
        } catch (Throwable $exception) {
            if (! Storage::disk(config('filesystems.default'))->delete($rutaDocumento)) {
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
            'amt' => $resumenAmt,
            'cte' => $resumenCte,
            'atm' => $resumenAtm,
            'sri' => $resumenSRI,
            'propietarios' => $resumenPropietarios,
            'fiscalia' => $resumenFiscalia,
            'fiscalia_cedula' => $resumenFiscaliaCedula,
            'supa' => $resumenSupa,
            'satje' => $resumenSatje,
            'total_deudas' => round(($resumenMultas['total'] ?? 0) + ($resumenSRI['total'] ?? 0), 2),
            'datos_oficiales' => $datosOficiales,
            'ultima_fecha_revision' => $datosOficiales['ultima_fecha_revision'] ?? '',
            'fecha_caducidad_revision' => $datosOficiales['fecha_caducidad_revision'] ?? null,
            'datos_documento' => $datosMatricula,
            'campos_axis_pendientes' => $camposAxisPendientes,
            'resumen_auditoria' => $validacion->resumen_auditoria,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function procesarSri(mixed $paquete): array
    {
        if (! is_array($paquete)) {
            return [
                'ok' => false,
                'tiene_deuda' => false,
                'total' => 0.0,
                'ultimo_anio' => 'No disponible',
                'camv_cpn' => '',
                'rubros' => [],
            ];
        }

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

        return [
            'ok' => (bool) ($paquete['sri']['ok'] ?? false),
            'tiene_deuda' => $totalSRI > 0,
            'total' => round($totalSRI, 2),
            'ultimo_anio' => (string) ($vehiculoSRI['ultimoAnioPagado'] ?? 'S/N'),
            'camv_cpn' => (string) ($vehiculoSRI['numeroCamvCpn'] ?? ''),
            'rubros' => $listaRubrosSRI,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function procesarMultas(mixed $paquete): array
    {
        if (! is_array($paquete)) {
            return [
                'ok' => false,
                'tiene_multas' => false,
                'cantidad' => 0,
                'total' => 0.0,
                'listado' => [],
            ];
        }

        $autorityRespondio = ($paquete['multas']['status'] ?? '') === 'success';
        $listaMultasRaw = ($autorityRespondio && is_array($paquete['multas']['data'] ?? null))
            ? $paquete['multas']['data']
            : [];

        $totalMultas = 0.0;
        $cantidadTotalMultas = 0;
        $listadoMultas = [];

        foreach ($listaMultasRaw as $m) {
            if (! is_array($m) || ! empty($m['paid'])) {
                continue;
            }

            $val = (float) (
                $m['totalValue']
                ?? $m['valorTotal']
                ?? $m['valueFine']
                ?? ($m['data_extra']['valueFine'] ?? null)
                ?? $m['total']
                ?? 0
            );

            $totalMultas += $val;
            $numInfraccionesItem = (int) ($m['totalFines'] ?? 1);
            $cantidadTotalMultas += max(1, $numInfraccionesItem);

            $listadoMultas[] = [
                'citacion' => (string) ($m['nCitacion'] ?? $m['nInfraccion'] ?? $m['id'] ?? 'S/N'),
                'entidad' => (string) ($m['entity'] ?? $m['entidad'] ?? $m['entityName'] ?? 'Tránsito'),
                'fecha' => (string) ($m['issuanceDate'] ?? $m['registrationDate'] ?? $m['fecha'] ?? ''),
                'descripcion' => (string) ($m['description'] ?? $m['descripcion'] ?? ($m['data_extra']['serviceName'] ?? 'Infracción de tránsito')),
                'total' => round($val, 2),
                'puntos' => (int) ($m['points'] ?? 0),
            ];
        }

        $tieneMultas = $autorityRespondio && count($listadoMultas) > 0;
        $totalRedondeado = $autorityRespondio ? round($totalMultas, 2) : null;
        $requiereRevisionManual = $tieneMultas && ($totalRedondeado ?? 0.0) <= 0.0;

        return [
            'ok' => $autorityRespondio,
            'tiene_multas' => $tieneMultas,
            'requiere_revision_manual' => $requiereRevisionManual,
            'cantidad' => $cantidadTotalMultas,
            'cantidad_multas' => $cantidadTotalMultas,
            'total' => $totalRedondeado,
            'total_pagar' => $totalRedondeado,
            'mensaje' => ! $autorityRespondio
                ? 'Autority no respondió en este intento. Verifique manualmente o reintente.'
                : ($requiereRevisionManual
                    ? 'Multa detectada con valor $0.00: requiere revisión manual.'
                    : 'Fuente oficial: Autority'),
            'listado' => $listadoMultas,
        ];
    }

    /**
     * @param  array<string, mixed>  $resumenSRI
     * @param  array<string, mixed>  $resumenMultas
     * @return array{0: array<string, mixed>, 1: bool, 2: ?string}
     */
    private function procesarAxis(
        mixed $paquete,
        string $placa,
        string $nombrePropietarioWeb,
        array $resumenSRI,
        array $resumenMultas,
        bool $huboArchivo,
    ): array {
        if (! is_array($paquete)) {
            return [[], false, $huboArchivo ? 'Respuesta de auditoría inválida' : 'Sin respuesta del robot'];
        }

        $motivoFalloAxis = null;
        $respuestaExterna = is_array($paquete['axis'] ?? null) ? $paquete['axis'] : $paquete;

        if (isset($respuestaExterna['error_script'])) {
            $motivoFalloAxis = (string) $respuestaExterna['error_script'];
        }

        $rawAxis = isset($respuestaExterna['campos'])
            ? (is_string($respuestaExterna['campos']) ? json_decode($respuestaExterna['campos'], true) : $respuestaExterna['campos'])
            : (! empty($respuestaExterna['lsDatosIdentificacion']) ? $respuestaExterna : null);

        if (is_array($rawAxis) && ! empty($rawAxis['lsDatosIdentificacion'])) {
            $extraerValor = static function (array $lista, string $etiquetaBuscada): string {
                foreach ($lista as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    if (isset($item['etiqueta']) && mb_stripos($item['etiqueta'], $etiquetaBuscada, 0, 'UTF-8') !== false) {
                        return trim((string) ($item['valor'] ?? ''));
                    }
                }

                return '';
            };

            $identificacion = is_array($rawAxis['lsDatosIdentificacion'] ?? null) ? $rawAxis['lsDatosIdentificacion'] : [];
            $modeloInfo = is_array($rawAxis['lsDatosModelo'] ?? null) ? $rawAxis['lsDatosModelo'] : [];
            $otrasInfo = is_array($rawAxis['lsOtrasCaracteristicas'] ?? null) ? $rawAxis['lsOtrasCaracteristicas'] : [];
            $revisionInfo = is_array($rawAxis['lsRevision'] ?? null) ? $rawAxis['lsRevision'] : [];
            $ultimaFechaRevision = $extraerValor($revisionInfo, 'Última Revisión Desde');

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
                'ultima_fecha_revision' => $ultimaFechaRevision,
                'fecha_ultima_revision_desde' => $ultimaFechaRevision,
                'fecha_ultima_revision_hasta' => $fechaRevisionHasta = $extraerValor($revisionInfo, 'Última Revisión Hasta'),
                'fecha_caducidad_revision' => AxisCloudService::normalizarFechaMysql($fechaRevisionHasta),
                'anio_ultima_revision' => $extraerValor($revisionInfo, 'Año Última Revisión'),
                'ultimo_anio_pagado_sri' => $resumenSRI['ultimo_anio'],
                'total_multas' => $resumenMultas['total'],
                'total_sri' => $resumenSRI['total'],
            ];

            return [$datosOficiales, true, $motivoFalloAxis];
        }

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

        return [$datosOficiales, false, $motivoFalloAxis];
    }

    /**
     * @param  array<string, mixed>  $datosMatricula
     * @param  array<string, mixed>  $datosOficiales
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function compararMatriculaConOficiales(
        array $datosMatricula,
        array $datosOficiales,
        ?string $motivoFalloAxis,
    ): array {
        $normalizar = fn (mixed $valor): string => strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $valor) ?? '');
        $normalizarFecha = static function (string $fecha): ?string {
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
            } elseif ($clave === 'anio' && $normDoc === $anioExpedicion) {
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

        return [$comparacion, $alertasCriticas];
    }

    /**
     * @return array<string, mixed>
     */
    private function procesarFiscaliaPlaca(mixed $paquete): array
    {
        $fiscPlacaRaw = is_array($paquete['fiscalia'] ?? null) ? $paquete['fiscalia'] : [];
        $resumenFiscalia = [
            'ok' => ($fiscPlacaRaw['ok'] ?? false) === true,
            'tiene_denuncias' => false,
            'cantidad' => 0,
            'listado' => [],
        ];
        $listaDenunciasRaw = is_array($fiscPlacaRaw['listado'] ?? null) ? $fiscPlacaRaw['listado'] : [];

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
                'ok' => ($fiscPlacaRaw['ok'] ?? false) === true,
                'tiene_denuncias' => true,
                'cantidad' => count($listadoDenuncias),
                'listado' => $listadoDenuncias,
            ];
        }

        return $resumenFiscalia;
    }

    /**
     * @return array<string, mixed>
     */
    private function procesarFiscaliaCedula(mixed $paquete, string $cedulaDoc): array
    {
        $fiscCedRaw = is_array($paquete['fiscalia_cedula'] ?? null) ? $paquete['fiscalia_cedula'] : [];
        $listaCedulaRaw = is_array($fiscCedRaw['listado'] ?? null) ? $fiscCedRaw['listado'] : [];
        $listadoDenunciasCedula = [];
        $rolesAlertaCedula = ['SOSPECHOSO', 'DENUNCIADO'];
        $textoFiscaliaCedula = static fn (mixed $valor, string $porDefecto = ''): string => is_scalar($valor)
            ? trim((string) $valor)
            : $porDefecto;

        foreach ($listaCedulaRaw as $d) {
            if (! is_array($d)) {
                continue;
            }

            $rol = strtoupper($textoFiscaliaCedula($d['rol'] ?? ''));
            if (! in_array($rol, $rolesAlertaCedula, true)) {
                continue;
            }

            $provincia = $textoFiscaliaCedula($d['provincia'] ?? '');
            $canton = $textoFiscaliaCedula($d['canton'] ?? '');
            $listadoDenunciasCedula[] = [
                'noticia_delito' => $textoFiscaliaCedula($d['ndd'] ?? $d['noticiaDelito'] ?? null, 'S/N'),
                'fecha' => $textoFiscaliaCedula($d['fecha'] ?? null),
                'localidad' => trim($provincia.' / '.$canton, ' /'),
                'descripcion' => $textoFiscaliaCedula($d['delito'] ?? $d['descripcion'] ?? null, 'Investigación fiscal registrada'),
                'rol' => $rol,
                'nombre_sujeto' => $textoFiscaliaCedula($d['nombre_sujeto'] ?? null),
            ];
        }

        $totalProcesosRaw = $fiscCedRaw['total_procesos_vista'] ?? 0;
        $totalProcesosVista = is_numeric($totalProcesosRaw) ? max(0, (int) $totalProcesosRaw) : 0;
        $consultaFiscaliaCedulaExitosa = ($fiscCedRaw['ok'] ?? false) === true;
        $procesosOtrosRolesRaw = $fiscCedRaw['procesos_otros_roles'] ?? null;
        $procesosOtrosRoles = $consultaFiscaliaCedulaExitosa
            ? max(0, is_numeric($procesosOtrosRolesRaw)
                ? (int) $procesosOtrosRolesRaw
                : $totalProcesosVista - count($listadoDenunciasCedula))
            : 0;

        return [
            'ok' => $consultaFiscaliaCedulaExitosa,
            'consultado' => (bool) ($fiscCedRaw['consultado'] ?? false),
            'cedula' => $textoFiscaliaCedula($fiscCedRaw['cedula'] ?? null, $cedulaDoc ?: 'No detectada'),
            'tiene_denuncias' => count($listadoDenunciasCedula) > 0,
            'cantidad' => count($listadoDenunciasCedula),
            'procesos_otros_roles' => $procesosOtrosRoles,
            'listado' => $listadoDenunciasCedula,
        ];
    }

    /**
     * @return array{total_traspasos: int, mensaje: string}
     */
    private function procesarTransferenciasSri(mixed $paquete): array
    {
        $infoSRI = is_array($paquete['sri'] ?? null) ? $paquete['sri'] : [];
        $totalTransferencias = (int) ($infoSRI['total_transferencias'] ?? 0);

        return [
            'total_traspasos' => $totalTransferencias,
            'mensaje' => $totalTransferencias > 0
                ? "Se registran {$totalTransferencias} transferencia(s) de dominio históricas en el SRI."
                : 'No se registran transferencias de dominio previas en el SRI.',
        ];
    }

    /**
     * Normaliza la respuesta de fuentes directas de multas (AMT, CTE, ATM) con manejo de errores/excepciones.
     *
     * @return array<string, mixed>
     */
    private function procesarFuenteMultaDirecta(mixed $paquete, string $clave, string $nombreFuente): array
    {
        $raw = is_array($paquete) && is_array($paquete[$clave] ?? null) ? $paquete[$clave] : null;

        if ($raw === null) {
            return [
                'ok' => false,
                'fuente' => strtoupper($clave),
                'nombre' => $nombreFuente,
                'tiene_multas' => false,
                'cantidad' => 0,
                'total' => null,
                'error' => "{$nombreFuente} no devolvió datos en esta consulta.",
                'mensaje' => "{$nombreFuente} no respondió en este intento. Verifique manualmente o reintente.",
                'listado' => [],
            ];
        }

        $ok = ($raw['ok'] ?? false) === true;
        $errorTexto = is_scalar($raw['error'] ?? null) ? trim((string) $raw['error']) : '';

        if (! $ok) {
            return [
                'ok' => false,
                'fuente' => strtoupper($clave),
                'nombre' => $nombreFuente,
                'tiene_multas' => false,
                'cantidad' => 0,
                'total' => null,
                'error' => $errorTexto !== '' ? $errorTexto : "{$nombreFuente} no disponible temporalmente.",
                'mensaje' => $errorTexto !== ''
                    ? "Error en {$nombreFuente}: {$errorTexto}"
                    : "{$nombreFuente} no respondió en este intento. Verifique manualmente o reintente.",
                'listado' => [],
            ];
        }

        $listadoRaw = is_array($raw['listado'] ?? null) ? $raw['listado'] : [];
        $listadoNormalizado = [];
        $totalCalculado = 0.0;

        foreach ($listadoRaw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $monto = round((float) ($item['total'] ?? $item['valor'] ?? 0), 2);
            $totalCalculado += $monto;

            $listadoNormalizado[] = [
                'citacion' => trim((string) ($item['boleta'] ?? $item['citacion'] ?? $item['etiqueta'] ?? $item['categoria'] ?? 'S/N')),
                'fecha' => trim((string) ($item['fecha'] ?? '')),
                'entidad' => trim((string) ($item['entidad'] ?? strtoupper($clave))),
                'descripcion' => trim((string) ($item['concepto'] ?? $item['descripcion'] ?? $item['etiqueta'] ?? 'Infracción registrada')),
                'total' => $monto,
            ];
        }

        $totalReportado = is_numeric($raw['total'] ?? null) ? round((float) $raw['total'], 2) : round($totalCalculado, 2);
        $cantidad = is_numeric($raw['cantidad'] ?? null) ? max(0, (int) $raw['cantidad']) : count($listadoNormalizado);
        $tieneMultas = ($raw['tiene_multas'] ?? false) === true || $cantidad > 0 || $totalReportado > 0;
        $requiereRevisionManual = $tieneMultas && $totalReportado <= 0.0;

        $resultado = [
            'ok' => true,
            'fuente' => strtoupper($clave),
            'nombre' => $nombreFuente,
            'tiene_multas' => $tieneMultas,
            'requiere_revision_manual' => $requiereRevisionManual,
            'cantidad' => $cantidad,
            'total' => $totalReportado,
            'error' => null,
            'mensaje' => $requiereRevisionManual
                ? "Fuente directa: {$nombreFuente} · Multa detectada con valor $0.00: requiere revisión manual."
                : "Fuente directa: {$nombreFuente}",
            'listado' => $listadoNormalizado,
        ];

        if ($clave === 'cte') {
            $resultado['servicio'] = is_scalar($raw['servicio'] ?? null) ? (string) $raw['servicio'] : null;
            $resultado['historico_pagadas'] = is_numeric($raw['historico_pagadas'] ?? null) ? (int) $raw['historico_pagadas'] : 0;
            $resultado['historico_anuladas'] = is_numeric($raw['historico_anuladas'] ?? null) ? (int) $raw['historico_anuladas'] : 0;
            $resultado['resumen_categorias'] = is_array($raw['resumen_categorias'] ?? null) ? $raw['resumen_categorias'] : [];
        }

        if ($clave === 'amt') {
            $resultado['propietario_detectado'] = is_scalar($raw['propietario_detectado'] ?? null) ? (string) $raw['propietario_detectado'] : null;
            $resultado['cedula_detectada'] = is_scalar($raw['cedula_detectada'] ?? null) ? (string) $raw['cedula_detectada'] : null;
        }

        return $resultado;
    }

    /**
     * Normaliza la respuesta de pensiones alimenticias (SUPA) generando alerta solo si existen 3 o más pagos pendientes.
     *
     * @return array<string, mixed>
     */
    private function procesarSupa(mixed $paquete, string $cedulaDoc): array
    {
        $raw = is_array($paquete) && is_array($paquete['supa'] ?? null) ? $paquete['supa'] : null;

        if ($raw === null) {
            return [
                'ok' => false,
                'consultado' => false,
                'cedula' => $cedulaDoc ?: 'No detectada',
                'tiene_pensiones' => false,
                'tiene_alerta' => false,
                'pagos_pendientes' => 0,
                'saldo_pendiente' => 0.0,
                'cantidad' => 0,
                'mensaje' => 'SUPA no devolvió datos en esta consulta.',
                'listado' => [],
            ];
        }

        $ok = ($raw['ok'] ?? false) === true;
        $consultado = (bool) ($raw['consultado'] ?? false);
        $errorTexto = is_scalar($raw['error'] ?? null) ? trim((string) $raw['error']) : '';
        $listadoRaw = is_array($raw['listado'] ?? null) ? $raw['listado'] : [];
        $listado = [];
        $totalPendientesCalculado = 0;
        $saldoPendienteTotal = 0.0;

        foreach ($listadoRaw as $tarjeta) {
            if (! is_array($tarjeta)) {
                continue;
            }

            $pendientesTarjeta = is_numeric($tarjeta['pagos_pendientes'] ?? null) ? max(0, (int) $tarjeta['pagos_pendientes']) : 0;
            $saldoTarjeta = is_numeric($tarjeta['saldo_pendiente'] ?? null) ? round((float) $tarjeta['saldo_pendiente'], 2) : 0.0;
            $totalPendientesCalculado += $pendientesTarjeta;
            $saldoPendienteTotal += $saldoTarjeta;

            $listado[] = [
                'codigo_tarjeta' => (string) ($tarjeta['codigo_tarjeta'] ?? ''),
                'proceso_judicial' => (string) ($tarjeta['proceso_judicial'] ?? ''),
                'dependencia' => (string) ($tarjeta['dependencia'] ?? ''),
                'pension_actual' => (string) ($tarjeta['pension_actual'] ?? ''),
                'pagos_acreditados' => (int) ($tarjeta['pagos_acreditados'] ?? 0),
                'pagos_pendientes' => $pendientesTarjeta,
                'saldo_pendiente' => $saldoTarjeta,
                'al_dia' => $pendientesTarjeta === 0,
                'tiene_alerta' => $pendientesTarjeta >= 3,
                'pendientes_detalle' => is_array($tarjeta['pendientes_detalle'] ?? null) ? $tarjeta['pendientes_detalle'] : [],
            ];
        }

        $pagosPendientes = is_numeric($raw['pagos_pendientes'] ?? null)
            ? max(0, (int) $raw['pagos_pendientes'])
            : $totalPendientesCalculado;
        $tieneAlerta = $ok && ($pagosPendientes >= 3 || collect($listado)->contains('tiene_alerta', true));

        return [
            'ok' => $ok,
            'consultado' => $consultado,
            'cedula' => is_scalar($raw['cedula'] ?? null) && trim((string) $raw['cedula']) !== ''
                ? trim((string) $raw['cedula'])
                : ($cedulaDoc ?: 'No detectada'),
            'tiene_pensiones' => ($raw['tiene_pensiones'] ?? false) === true || count($listado) > 0,
            'tiene_alerta' => $tieneAlerta,
            'pagos_pendientes' => $pagosPendientes,
            'saldo_pendiente' => round($saldoPendienteTotal, 2),
            'cantidad' => count($listado),
            'error' => $errorTexto !== '' ? $errorTexto : null,
            'mensaje' => is_scalar($raw['mensaje'] ?? null) && trim((string) $raw['mensaje']) !== ''
                ? trim((string) $raw['mensaje'])
                : ($ok
                    ? ($tieneAlerta
                        ? "¡Alerta! Se registran {$pagosPendientes} pago(s) pendiente(s) en SUPA."
                        : 'Pagos al día en SUPA (sin alertas).')
                    : ($errorTexto !== '' ? "Error en SUPA: {$errorTexto}" : 'SUPA no pudo completar la consulta.')),
            'listado' => $listado,
        ];
    }

    /**
     * Normaliza la respuesta de causas judiciales (SATJE - Función Judicial) por cédula.
     *
     * @return array<string, mixed>
     */
    private function procesarSatje(mixed $paquete, string $cedulaDoc): array
    {
        $raw = is_array($paquete) && is_array($paquete['satje'] ?? null) ? $paquete['satje'] : null;

        if ($raw === null) {
            return [
                'ok' => false,
                'consultado' => false,
                'cedula' => $cedulaDoc ?: 'No detectada',
                'tiene_causas' => false,
                'cantidad_demandado' => 0,
                'cantidad_actor' => 0,
                'cantidad' => 0,
                'mensaje' => 'SATJE no devolvió datos en esta consulta.',
                'listado' => [],
            ];
        }

        $ok = ($raw['ok'] ?? false) === true;
        $consultado = (bool) ($raw['consultado'] ?? false);
        $errorTexto = is_scalar($raw['error'] ?? null) ? trim((string) $raw['error']) : '';
        $listadoRaw = is_array($raw['listado'] ?? null) ? $raw['listado'] : [];
        $listado = [];
        $cantidadDemandado = 0;
        $cantidadActor = 0;

        foreach ($listadoRaw as $causa) {
            if (! is_array($causa)) {
                continue;
            }

            $rol = strtoupper(trim((string) ($causa['rol'] ?? 'DEMANDADO')));
            if ($rol === 'DEMANDADO') {
                $cantidadDemandado++;
            } else {
                $cantidadActor++;
            }

            $listado[] = [
                'id_juicio' => trim((string) ($causa['id_juicio'] ?? 'S/N')),
                'rol' => $rol,
                'delito_accion' => trim((string) ($causa['delito_accion'] ?? 'Proceso judicial')),
                'estado_actual' => trim((string) ($causa['estado_actual'] ?? '')),
                'fecha_ingreso' => trim((string) ($causa['fecha_ingreso'] ?? '')),
            ];
        }

        $cantidadDemandadoFinal = is_numeric($raw['cantidad_demandado'] ?? null)
            ? max(0, (int) $raw['cantidad_demandado'])
            : $cantidadDemandado;
        $cantidadActorFinal = is_numeric($raw['cantidad_actor'] ?? null)
            ? max(0, (int) $raw['cantidad_actor'])
            : $cantidadActor;
        $tieneCausas = $ok && ($cantidadDemandadoFinal > 0 || ($raw['tiene_causas'] ?? false) === true);

        return [
            'ok' => $ok,
            'consultado' => $consultado,
            'cedula' => is_scalar($raw['cedula'] ?? null) && trim((string) $raw['cedula']) !== ''
                ? trim((string) $raw['cedula'])
                : ($cedulaDoc ?: 'No detectada'),
            'tiene_causas' => $tieneCausas,
            'cantidad_demandado' => $cantidadDemandadoFinal,
            'cantidad_actor' => $cantidadActorFinal,
            'cantidad' => count($listado),
            'error' => $errorTexto !== '' ? $errorTexto : null,
            'mensaje' => is_scalar($raw['mensaje'] ?? null) && trim((string) $raw['mensaje']) !== ''
                ? trim((string) $raw['mensaje'])
                : ($ok
                    ? ($tieneCausas
                        ? "Se encontraron {$cantidadDemandadoFinal} causa(s) como demandado en Función Judicial."
                        : 'Sin causas como demandado en SATJE.')
                    : ($errorTexto !== '' ? "Error en SATJE: {$errorTexto}" : 'SATJE no pudo completar la consulta.')),
            'listado' => $listado,
        ];
    }
}
