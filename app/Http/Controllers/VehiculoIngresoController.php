<?php

namespace App\Http\Controllers;

use App\Models\clase_vehiculo;
use App\Models\color_vehiculo;
use App\Models\marca_vehiculo;
use App\Models\pais;
use App\Models\tipo_combustible;
use App\Models\transmision;
use App\Services\VehiculoIngreso\AuditoriaVehicularService;
use App\Services\VehiculoIngreso\AxisCloudService;
use App\Services\VehiculoIngreso\VehiculoIngresoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VehiculoIngresoController extends Controller
{
    public function __construct(
        private readonly VehiculoIngresoService $vehiculoIngresoService,
        private readonly AxisCloudService $axisCloudService,
        private readonly AuditoriaVehicularService $auditoriaVehicularService,
    ) {}

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
        return $this->vehiculoIngresoService->registrarIngreso($request);
    }

    public function consultarAxisCloud(Request $request): JsonResponse
    {
        set_time_limit(90);

        return $this->axisCloudService->consultarPorPlaca(
            (string) $request->input('placa', ''),
            fn (string $placa, string $archivoTemp): int => $this->ejecutarConsultaAxis($placa, $archivoTemp),
        );
    }

    public function escanearYCompararMatricula(Request $request): JsonResponse
    {
        set_time_limit(180);

        $request->validate([
            'documento' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        return $this->auditoriaVehicularService->escanearYComparar(
            $request,
            $request->file('documento'),
            fn (string $placa, string $archivoTemp, string $cedula): int => $this->ejecutarAuditoriaCompleta(
                $placa,
                $archivoTemp,
                $cedula,
            ),
        );
    }

    protected function ejecutarAuditoriaCompleta(string $placa, string $archivoTemp, string $cedula): int
    {
        $nodeBin = file_exists('C:\laragon\bin\nodejs\node-v22\node.exe')
            ? '"C:\laragon\bin\nodejs\node-v22\node.exe"'
            : 'node';

        // Usa auditoria_completa.cjs si existe, con fallback a consultar_axis.cjs
        $scriptPath = file_exists(base_path('auditoria_completa.cjs'))
            ? base_path('auditoria_completa.cjs')
            : base_path('consultar_axis.cjs');

        $salidaConsola = [];
        $cedulaArg = escapeshellarg($cedula);
        $codigoSalida = 0;

        exec("{$nodeBin} \"{$scriptPath}\" {$placa} \"{$archivoTemp}\" {$cedulaArg} 2>&1", $salidaConsola, $codigoSalida);

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
