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
use Illuminate\Support\Facades\DB;
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
        abort_unless(
            $this->consultaService->puedeVerDatosFinancieros($request->user()),
            403,
            'No tienes permiso para gestionar multas.',
        );

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

    public function comprobanteMulta(Request $request, Vehiculo $vehiculo, MultaVehiculo $multa): StreamedResponse
    {
        abort_unless(
            $this->consultaService->puedeVerDatosFinancieros($request->user()),
            403,
            'No tienes permiso para acceder a comprobantes de multas.',
        );

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
        abort_unless(
            $this->consultaService->puedeVerDatosFinancieros($request->user()),
            403,
            'No tienes permiso para registrar pagos o abonos.',
        );

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

    public function comprobantePago(Request $request, Vehiculo $vehiculo, PagoVehiculo $pago): StreamedResponse
    {
        abort_unless(
            $this->consultaService->puedeVerDatosFinancieros($request->user()),
            403,
            'No tienes permiso para acceder a comprobantes de pago.',
        );

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

    public function actualizarPrecioVenta(Request $request, Vehiculo $vehiculo): RedirectResponse
    {
        abort_unless(
            $request->user()?->hasRole('Administrador'),
            403,
            'Solo el Administrador puede fijar o modificar el precio de venta.',
        );

        $validated = $request->validate([
            'precio_venta' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
        ]);

        DB::transaction(function () use ($vehiculo, $validated): void {
            $vehiculoBloqueado = Vehiculo::query()
                ->whereKey($vehiculo->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $vehiculoBloqueado->update([
                'precio_venta' => round((float) $validated['precio_venta'], 2),
            ]);
        });

        return back()->with('success', 'Precio de venta actualizado correctamente.');
    }
}
