<?php

namespace App\Services\VehiculoIngreso;

use App\Models\clase_vehiculo;
use App\Models\color_vehiculo;
use App\Models\marca_vehiculo;
use App\Models\pais;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AxisCloudService
{
    /**
     * Consulta los datos de un vehículo por placa en AxisCloud y resuelve sus catálogos.
     *
     * @param  Closure(string, string): int  $ejecutarScriptAxis
     */
    public function consultarPorPlaca(string $placaRaw, Closure $ejecutarScriptAxis): JsonResponse
    {
        $placa = strtoupper(str_replace(['-', ' '], '', trim($placaRaw)));

        if (! $placa) {
            return response()->json(['success' => false, 'message' => 'Ingrese un número de placa.'], 422);
        }

        $archivoTemp = storage_path("app/axis_{$placa}.json");

        if (file_exists($archivoTemp)) {
            @unlink($archivoTemp);
        }

        $ejecutarScriptAxis($placa, $archivoTemp);

        if (! file_exists($archivoTemp)) {
            return response()->json([
                'success' => false,
                'message' => 'Error al ejecutar Node al consultar AxisCloud.',
            ], 500);
        }

        $jsonTexto = file_get_contents($archivoTemp);
        @unlink($archivoTemp);

        $respuestaExterna = is_string($jsonTexto) ? json_decode($jsonTexto, true) : null;

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

        $extraerValor = static function (array $lista, string $etiquetaBuscada): string {
            foreach ($lista as $item) {
                if (isset($item['etiqueta']) && mb_stripos($item['etiqueta'], $etiquetaBuscada, 0, 'UTF-8') !== false) {
                    return trim((string) ($item['valor'] ?? ''));
                }
            }

            return '';
        };

        $identificacion = $datos['lsDatosIdentificacion'] ?? [];
        $modeloInfo = $datos['lsDatosModelo'] ?? [];
        $otrasInfo = $datos['lsOtrasCaracteristicas'] ?? [];
        $revisionInfo = is_array($datos['lsRevision'] ?? null) ? $datos['lsRevision'] : [];

        $servicio = trim((string) ($datos['lsServicio'] ?? ''));

        $chasis = $extraerValor($identificacion, 'VIN');
        $motor = $extraerValor($identificacion, 'Motor');
        $nombreMarca = $extraerValor($modeloInfo, 'Marca');
        $nombreModelo = $extraerValor($modeloInfo, 'Modelo');
        $anio = $extraerValor($modeloInfo, 'Fabricaci');
        $nombrePais = $extraerValor($modeloInfo, 'Pa');
        $nombreClase = $extraerValor($otrasInfo, 'Clase Veh');
        $tipoVehiculo = $extraerValor($otrasInfo, 'Tipo Veh');
        $nombreColor = $extraerValor($otrasInfo, 'Color 1');
        $fechaRevisionHastaRaw = $extraerValor($revisionInfo, 'Última Revisión Hasta');
        $fechaCaducidadRevision = self::normalizarFechaMysql($fechaRevisionHastaRaw);

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
                'fecha_caducidad_revision' => $fechaCaducidadRevision,
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

    public static function normalizarFechaMysql(?string $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $limpio = trim($valor);
        if ($limpio === '') {
            return null;
        }

        foreach (['d-m-Y H:i:s', 'd-m-Y', 'd/m/Y H:i:s', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d'] as $formato) {
            try {
                $fecha = Carbon::createFromFormat($formato, $limpio);
                if ($fecha !== false) {
                    return $fecha->format('Y-m-d');
                }
            } catch (\Throwable) {
                // Intentar siguiente formato
            }
        }

        return null;
    }
}
