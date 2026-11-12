<?php

namespace App\Http\Controllers;

use App\Models\Vehiculo;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class InventarioVehiculoController extends Controller
{
    public function index(): Response
    {
        $vehiculos = Vehiculo::with([
            'marca',
            'clase',
            'pais',
            'color',
            'tipo_combustible',
            'transmision',
            'tipoVehiculo',
            'servicio',
            'patio',
            'estado',
            'proveedor',
            'imagenPrincipal',
        ])->orderByDesc('created_at')->get();

        $vehiculos->each(function (Vehiculo $vehiculo): void {
            $vehiculo->setAttribute(
                'imagen_url',
                $vehiculo->imagenPrincipal
                    ? Storage::disk('public')->url($vehiculo->imagenPrincipal->ruta_imagen)
                    : null,
            );
        });

        return Inertia::render('Vehiculos/Inventario', [
            'vehiculos' => $vehiculos,
        ]);
    }
}
