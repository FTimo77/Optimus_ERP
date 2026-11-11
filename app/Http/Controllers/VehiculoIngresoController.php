<?php

namespace App\Http\Controllers;

use App\Models\clase_vehiculo;
use App\Models\color_vehiculo;
use App\Models\estado_vehiculo;
use App\Models\imagen_vehiculo;
use App\Models\marca_vehiculo;
use App\Models\pais;
use App\Models\patio;
use App\Models\proveedor;
use App\Models\tipo_combustible;
use App\Models\transmision;
use App\Models\vehiculo as Vehiculo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'patios' => patio::all(),
            'estados' => estado_vehiculo::all(),
            'proveedores' => proveedor::all(),
            'success' => session('success'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $plate = $request->input('id_vehiculo');

        if (is_string($plate)) {
            $request->merge(['id_vehiculo' => strtoupper(trim($plate))]);
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
            'id_patio' => ['required', 'exists:patios,id_patio'],
            'id_estado' => ['required', 'exists:estado_vehiculos,id_estado'],
            'id_proveedor' => ['required', 'exists:proveedores,id_proveedor'],
            'modelo' => ['required', 'string', 'max:100'],
            'anio' => ['required', 'integer', 'min:1980', 'max:'.(date('Y') + 1)],
            'motor' => ['required', 'string', 'max:100', 'unique:vehiculos,motor'],
            'chasis' => ['required', 'string', 'max:100', 'unique:vehiculos,chasis'],
            'cilindraje' => ['required', 'string', 'max:100'],
            'kilometraje' => ['required', 'integer', 'min:0'],
            'costo_adquisicion' => ['required', 'numeric', 'min:0'],
            'precio_venta' => ['required', 'numeric', 'min:0', 'gte:costo_adquisicion'],
        ]);

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

        $chasis = $extraerValor($identificacion, 'VIN');
        $motor = $extraerValor($identificacion, 'Motor');
        $nombreMarca = $extraerValor($modeloInfo, 'Marca');
        $nombreModelo = $extraerValor($modeloInfo, 'Modelo');
        $anio = $extraerValor($modeloInfo, 'Fabricaci');
        $nombrePais = $extraerValor($modeloInfo, 'Pa');
        $nombreClase = $extraerValor($otrasInfo, 'Clase') ?: $extraerValor($otrasInfo, 'Tipo');
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
}
