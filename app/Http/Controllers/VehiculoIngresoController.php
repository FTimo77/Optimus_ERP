<?php

namespace App\Http\Controllers;

use App\Models\vehiculo as Vehiculo;
use App\Models\clase_vehiculo;
use App\Models\color_vehiculo;
use App\Models\estado_vehiculo;
use App\Models\marca_vehiculo;
use App\Models\pais;
use App\Models\patio;
use App\Models\proveedor;
use App\Models\tipo_combustible;
use App\Models\transmision;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VehiculoIngresoController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Vehiculos/Ingreso', [
            'marcas' => marca_vehiculo::all(),
            'clases' => clase_vehiculo::all(),
            'paises' => pais::all(),
            'colores' => color_vehiculo::all(),
            'tipos_combustible' => tipo_combustible::all(),
            'transmisiones' => transmision::all(),
            'patios' => patio::all(),
            'estados' => estado_vehiculo::all(),
            'proveedores' => proveedor::all(),
            'success' => session('success'),
        ]);
    }


    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
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

        Vehiculo::create($validated);

        return redirect()
            ->route('vehiculos.create')
            ->with('success', 'Vehículo ingresado exitosamente al inventario.');
    }
}
