<?php

namespace App\Http\Controllers;

use App\Models\Vehiculo;
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
            'patio',
            'estado',
            'proveedor',
        ])->orderByDesc('id_vehiculo')->get();

        return Inertia::render('Vehiculos/Inventario', [
            'vehiculos' => $vehiculos,
        ]);
    }
}