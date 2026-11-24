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

    public function transferirPatio(Request $request, Vehiculo $vehiculo): RedirectResponse
    {
        abort_unless(
            $this->consultaService->puedeTransferirPatio($request->user()),
            403,
            'Solo los roles Vendedor (Jefe de Patio) o Administrador pueden transferir vehículos de patio.',
        );

        $validated = $request->validate([
            'patio_destino_id' => ['required', 'integer', 'exists:patios,id_patio'],
            'usuario_recepcion_id' => ['required', 'integer', 'exists:users,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'string', Rule::in(array_keys(CustodiaDocumentoVehiculo::ITEMS))],
            'observacion' => ['nullable', 'string', 'max:500'],
        ]);

        $this->custodiaDocumentosService->solicitarTransferenciaPatio(
            $vehiculo,
            $validated['items'],
            (int) $validated['patio_destino_id'],
            (int) $validated['usuario_recepcion_id'],
            $validated['observacion'] ?? null,
            $request->user(),
        );

        return back()->with('success', 'Transferencia de patio registrada y solicitud de recepción enviada al usuario destino.');
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
        abort_unless(
            $this->consultaService->puedeSubirContrato($request->user()),
            403,
            'Solo el área Administrativa o el Administrador puede subir contratos.',
        );

        $vehiculo->loadMissing('tipoContrato');

        if ($vehiculo->tipoContrato !== null && ! $vehiculo->tipoContrato->requiere_documento) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'contrato' => "El tipo de contrato «{$vehiculo->tipoContrato->nombre}» no requiere ni habilita la carga de documentos.",
            ]);
        }

        $esDobleContrato = ($vehiculo->tipoContrato?->cantidadDocumentosRequeridos() ?? 1) >= 2;

        $reglas = [
            'contrato' => [$esDobleContrato ? 'nullable' : 'required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'contrato_2' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ];

        $request->validate($reglas);

        $archivo1 = $request->file('contrato');
        $archivo2 = $esDobleContrato ? $request->file('contrato_2') : null;

        if ($archivo1 === null && $archivo2 === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'contrato' => 'Debes seleccionar al menos un archivo de contrato para subir.',
            ]);
        }

        $this->custodiaDocumentosService->subirContratosPrivados($vehiculo, $archivo1, $archivo2);

        $mensaje = ($archivo1 !== null && $archivo2 !== null)
            ? 'Los 2 documentos del Doble Contrato fueron guardados correctamente en almacenamiento privado.'
            : 'Contrato guardado correctamente en almacenamiento privado.';

        return back()->with('success', $mensaje);
    }

    public function descargarContrato(Request $request, Vehiculo $vehiculo): StreamedResponse
    {
        $numero = (int) $request->query('numero', 1) === 2 ? 2 : 1;

        return $this->custodiaDocumentosService->descargarContrato($vehiculo, $numero);
    }

    public function descargarDocumento(Request $request, Vehiculo $vehiculo, string $tipo): StreamedResponse
    {
        $inline = $request->boolean('inline');
        $puedeVerFinancieros = $this->consultaService->puedeVerDatosFinancieros($request->user());

        return $this->custodiaDocumentosService->servirDocumentoExpediente(
            $vehiculo,
            $tipo,
            $inline,
            $puedeVerFinancieros,
        );
    }

    public function subirDocumentoVehiculoNuevo(Request $request, Vehiculo $vehiculo): RedirectResponse
    {
        $validated = $request->validate([
            'tipo' => ['required', 'string', 'in:matricula,revision,factura'],
            'archivo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        $this->custodiaDocumentosService->subirDocumentoVehiculoNuevo(
            $vehiculo,
            $validated['tipo'],
            $request->file('archivo'),
        );

        $etiquetas = [
            'matricula' => 'Matrícula',
            'revision' => 'Revisión (RTV / Inspección)',
            'factura' => 'Factura',
        ];
        $etiqueta = $etiquetas[$validated['tipo']] ?? 'Documento';

        return back()->with('success', "{$etiqueta} del vehículo nuevo guardada correctamente en almacenamiento privado.");
    }

    public function actualizarPrecioVenta(Request $request, Vehiculo $vehiculo): RedirectResponse
    {
        abort_unless(
            $request->user()?->hasRole('Administrador'),
            403,
            'Solo el Administrador puede fijar o modificar los precios de venta.',
        );

        $validated = $request->validate([
            'precio_venta' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'precio_final' => ['nullable', 'numeric', 'min:0', 'lte:precio_venta', 'max:999999999999.99'],
        ], [
            'precio_final.lte' => 'El Precio Final (mínimo) no puede ser mayor al Precio de Lista.',
        ]);

        DB::transaction(function () use ($vehiculo, $validated): void {
            $vehiculoBloqueado = Vehiculo::query()
                ->whereKey($vehiculo->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $precioFinal = isset($validated['precio_final']) && $validated['precio_final'] !== ''
                ? round((float) $validated['precio_final'], 2)
                : null;

            $vehiculoBloqueado->update([
                'precio_venta' => round((float) $validated['precio_venta'], 2),
                'precio_final' => $precioFinal,
            ]);
        });

        return back()->with('success', 'Precios de venta actualizados correctamente.');
    }
}
