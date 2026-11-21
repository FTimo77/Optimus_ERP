<?php

namespace App\Http\Controllers;

use App\Models\CustodiaDocumentoVehiculo;
use App\Models\MultaVehiculo;
use App\Models\PagoVehiculo;
use App\Models\Vehiculo;
use App\Services\Inventario\CustodiaDocumentosService;
use App\Services\Inventario\InventarioConsultaService;
use App\Services\Inventario\MultasPagosVehiculoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventarioVehiculoController extends Controller
{
    public function __construct(
        private readonly InventarioConsultaService $consultaService,
        private readonly MultasPagosVehiculoService $multasPagosService,
        private readonly CustodiaDocumentosService $custodiaDocumentosService,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Vehiculos/Inventario', [
            ...$this->consultaService->obtenerDatosDashboard($request->user()),
            'success' => session('success'),
        ]);
    }

    public function pagarMulta(Request $request, Vehiculo $vehiculo, MultaVehiculo $multa): RedirectResponse
    {
        $request->validate([
            'comprobante' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $this->multasPagosService->pagarMulta(
            $vehiculo,
            $multa,
            $request->file('comprobante'),
            $request->user(),
        );

        return back()->with('success', 'Comprobante registrado y multa marcada como saldada.');
    }

    public function comprobanteMulta(Vehiculo $vehiculo, MultaVehiculo $multa): StreamedResponse
    {
        return $this->multasPagosService->descargarComprobanteMulta($vehiculo, $multa);
    }

    public function solicitarEntregaCustodia(Request $request, Vehiculo $vehiculo): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'string', Rule::in(array_keys(CustodiaDocumentoVehiculo::ITEMS))],
            'usuario_recepcion_id' => ['nullable', 'integer', 'exists:users,id'],
            'patio_destino_id' => ['required', 'integer', 'exists:patios,id_patio'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ]);

        $this->custodiaDocumentosService->solicitarEntrega(
            $vehiculo,
            $validated['items'],
            (int) $validated['patio_destino_id'],
            isset($validated['usuario_recepcion_id']) ? (int) $validated['usuario_recepcion_id'] : null,
            $validated['observacion'] ?? null,
            $request->user(),
        );

        return back()->with('success', 'Solicitud de entrega-recepción enviada correctamente.');
    }

    public function confirmarRecepcionCustodia(
        Request $request,
        Vehiculo $vehiculo,
        CustodiaDocumentoVehiculo $custodia,
    ): RedirectResponse {
        $this->custodiaDocumentosService->confirmarRecepcion($vehiculo, $custodia, $request->user());

        return back()->with('success', 'Recepción confirmada y custodia asignada al patio destino de la solicitud.');
    }

    public function registrarAbonoPago(Request $request, Vehiculo $vehiculo): RedirectResponse
    {
        $validated = $request->validate([
            'comprobante' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'monto' => ['required', 'numeric', 'gt:0'],
            'fecha_pago' => ['nullable', 'date'],
        ]);

        $this->multasPagosService->registrarAbono(
            $vehiculo,
            $request->file('comprobante'),
            (float) $validated['monto'],
            $validated['fecha_pago'] ?? null,
            $request->user(),
        );

        return back()->with('success', 'Comprobante y abono registrados correctamente.');
    }

    public function comprobantePago(Vehiculo $vehiculo, PagoVehiculo $pago): StreamedResponse
    {
        return $this->multasPagosService->descargarComprobantePago($vehiculo, $pago);
    }

    public function subirContrato(Request $request, Vehiculo $vehiculo): RedirectResponse
    {
        $request->validate([
            'contrato' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        $this->custodiaDocumentosService->subirContratoPrivado($vehiculo, $request->file('contrato'));

        return back()->with('success', 'Contrato guardado correctamente en almacenamiento privado.');
    }

    public function descargarContrato(Vehiculo $vehiculo): StreamedResponse
    {
        return $this->custodiaDocumentosService->descargarContrato($vehiculo);
    }
}
