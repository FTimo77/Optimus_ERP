<?php

namespace App\Http\Controllers;

use App\Models\Documentos_vehiculo;
use App\Models\estado_vehiculo as EstadoVehiculo;
use App\Models\Metodo_pago as MetodoPago;
use App\Models\Movimientos_vehiculo;
use App\Models\PagoVenta;
use App\Models\Tipo_movimiento;
use App\Models\User;
use App\Models\vehiculo as Vehiculo;
use App\Models\Venta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MisVentasController extends Controller
{
    /**
     * Por ahora habilitado cuando el estado actual es 'En Inventario'.
     * Preparado para migrar o añadir 'Disponible' en una futura actualización.
     *
     * @var list<string>
     */
    public const ESTADOS_HABILITADOS_VENTA = [
        'en inventario',
    ];

    /**
     * Ruta de la plantilla .docx para la generación de constancia comercial.
     * Preparada para reemplazarse por una plantilla específica de venta/reserva más adelante.
     */
    private const PLANTILLA_CONSTANCIA_VENTA = 'app/plantillas/Contrato_compra_venta_vehiculo_editable.docx';

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($this->puedeUsarModuloVentas($usuario), 403);

        $tabActiva = $request->query('tab') === 'historial' ? 'historial' : 'activas';
        $esSupervisor = $this->puedeAprobarDescuento($usuario);

        $consultaBase = Venta::query();
        if (! $esSupervisor) {
            $consultaBase->where('id_user', $usuario->id);
        }

        $filtroHistorial = static function ($query): void {
            $query->where('estado', 'aprobada')
                ->whereNotNull('ruta_constancia_generada');
        };

        $filtroActivas = static function ($query): void {
            $query->where(function ($sub): void {
                $sub->where('estado', '!=', 'aprobada')
                    ->orWhereNull('ruta_constancia_generada');
            });
        };

        $totalActivas = (clone $consultaBase)->where($filtroActivas)->count();
        $totalHistorial = (clone $consultaBase)->where($filtroHistorial)->count();
        $pendientesAprobacion = (clone $consultaBase)
            ->where('estado_aprobacion_descuento', 'pendiente')
            ->count();

        $ventas = (clone $consultaBase)
            ->where($tabActiva === 'historial' ? $filtroHistorial : $filtroActivas)
            ->with([
                'vehiculo.marca',
                'vehiculo.estado',
                'user:id,name',
                'aprobadorDescuento:id,name',
            ])
            ->latest('updated_at')
            ->paginate(15)
            ->withQueryString()
            ->through(function (Venta $venta): array {
                [$precioLista, $precioFinal] = $this->resolverUmbralPrecioMinimo($venta);
                $precioAcordado = (float) ($venta->precio_acordado ?? $venta->precio_venta ?? $precioLista);

                return [
                    'id_venta' => $venta->id_venta,
                    'placa' => $venta->id_vehiculo,
                    'marca' => $venta->vehiculo?->marca?->nombre ?? 'No registrada',
                    'modelo' => $venta->vehiculo?->modelo ?? 'No registrado',
                    'anio' => $venta->vehiculo?->anio,
                    'estado_vehiculo' => $venta->vehiculo?->estado?->nombre ?? 'En proceso',
                    'tipo_operacion' => $venta->tipo_operacion ?? 'venta',
                    'precio_base' => $precioLista,
                    'precio_lista' => $precioLista,
                    'precio_final' => $precioFinal,
                    'precio_acordado' => $precioAcordado,
                    'estado' => $venta->estado,
                    'requiere_aprobacion_descuento' => (bool) $venta->requiere_aprobacion_descuento,
                    'estado_aprobacion_descuento' => $venta->estado_aprobacion_descuento,
                    'tiene_constancia' => $venta->ruta_constancia_generada !== null,
                    'vendedor' => $venta->user?->name ?? 'Vendedor',
                    'aprobado_por' => $venta->aprobadorDescuento?->name,
                    'created_at' => $venta->created_at?->toIso8601String(),
                    'updated_at' => $venta->updated_at?->toIso8601String(),
                ];
            });

        return Inertia::render('MisVentas/Index', [
            'ventas' => $ventas,
            'tabActiva' => $tabActiva,
            'totalActivas' => $totalActivas,
            'totalHistorial' => $totalHistorial,
            'pendientesAprobacion' => $pendientesAprobacion,
            'success' => session('success'),
        ]);
    }

    public function iniciar(Request $request, Vehiculo $vehiculo): RedirectResponse
    {
        $usuario = $request->user();
        abort_unless($this->puedeUsarModuloVentas($usuario), 403);

        $ventaExistente = Venta::query()
            ->where('id_vehiculo', $vehiculo->id_vehiculo)
            ->where('estado', '!=', 'cancelada')
            ->latest('id_venta')
            ->first();

        if ($ventaExistente !== null && ($ventaExistente->id_user === $usuario->id || $this->puedeAprobarDescuento($usuario))) {
            return to_route('mis-ventas.index')
                ->with('success', "El vehículo {$vehiculo->id_vehiculo} ya cuenta con un expediente comercial activo en Mis Ventas.");
        }

        DB::transaction(function () use ($vehiculo, $usuario): Venta {
            $vehiculoBloqueado = Vehiculo::query()
                ->with('estado')
                ->whereKey($vehiculo->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $nombreEstadoActual = mb_strtolower(trim((string) ($vehiculoBloqueado->estado?->nombre ?? '')), 'UTF-8');

            if (! in_array($nombreEstadoActual, self::ESTADOS_HABILITADOS_VENTA, true)) {
                throw ValidationException::withMessages([
                    'vehiculo' => 'Este vehículo no se encuentra en estado «En Inventario» para iniciar la venta.',
                ]);
            }

            $precioLista = $vehiculoBloqueado->precio_venta !== null
                ? round((float) $vehiculoBloqueado->precio_venta, 2)
                : 0.0;
            $precioFinal = $vehiculoBloqueado->precio_final !== null
                ? round((float) $vehiculoBloqueado->precio_final, 2)
                : 0.0;

            if ($precioLista <= 0.0 || $precioFinal <= 0.0) {
                throw ValidationException::withMessages([
                    'vehiculo' => 'No se puede iniciar la venta: el Administrador debe configurar primero el Precio de Lista y el Precio Final (mínimo) del vehículo.',
                ]);
            }

            $estadoProceso = EstadoVehiculo::firstOrCreate(['nombre' => 'En proceso de venta']);
            $vehiculoBloqueado->update([
                'id_estado' => $estadoProceso->id_estado,
            ]);

            $precioBase = $precioLista;

            $venta = Venta::create([
                'id_vehiculo' => $vehiculoBloqueado->id_vehiculo,
                'id_user' => $usuario->id,
                'tipo_operacion' => 'venta',
                'precio_base' => $precioBase,
                'precio_acordado' => $precioBase,
                'precio_venta' => $precioBase,
                'fecha_venta' => now()->toDateString(),
                'estado' => 'en_proceso',
                'requiere_aprobacion_descuento' => false,
            ]);

            $tipoMovimiento = Tipo_movimiento::firstOrCreate(['nombre' => 'Inicio Proceso Comercial']);
            Movimientos_vehiculo::create([
                'id_vehiculo' => $vehiculoBloqueado->id_vehiculo,
                'id_tipo_movimiento' => $tipoMovimiento->id_tipo_movimiento,
                'id_usuario' => $usuario->id,
                'descripcion' => "Inicio de proceso comercial en Mis Ventas por {$usuario->name}.",
                'fecha_movimiento' => now(),
            ]);

            return $venta;
        });

        return to_route('mis-ventas.index')
            ->with('success', "Vehículo {$vehiculo->id_vehiculo} pasado a proceso comercial y agregado a Mis Ventas.");
    }

    public function show(Request $request, Venta $venta): Response
    {
        $usuario = $request->user();
        abort_unless($this->puedeAccederVenta($usuario, $venta), 403);

        $venta->load([
            'vehiculo.marca',
            'vehiculo.color',
            'vehiculo.clase',
            'vehiculo.transmision',
            'vehiculo.patio',
            'vehiculo.estado',
            'vehiculo.imagenPrincipal',
            'user:id,name',
            'aprobadorDescuento:id,name',
            'aprobadorCredito:id,name',
            'pagos' => fn ($query) => $query->with('registrador:id,name')->orderBy('id_pago_venta'),
        ]);

        $vehiculo = $venta->vehiculo;
        [$precioLista, $precioFinal, $precioMinimo] = $this->resolverUmbralPrecioMinimo($venta);
        $precioAcordado = (float) ($venta->precio_acordado ?? $venta->precio_venta ?? $precioLista);

        return Inertia::render('MisVentas/Show', [
            'venta' => [
                'id_venta' => $venta->id_venta,
                'placa' => $venta->id_vehiculo,
                'tipo_operacion' => $venta->tipo_operacion ?? 'venta',
                'estado' => $venta->estado,
                'precio_base' => $precioLista,
                'precio_lista' => $precioLista,
                'precio_final' => $precioFinal,
                'precio_minimo' => $precioMinimo,
                'precio_acordado' => $precioAcordado,
                'requiere_aprobacion_descuento' => (bool) $venta->requiere_aprobacion_descuento,
                'estado_aprobacion_descuento' => $venta->estado_aprobacion_descuento,
                'motivo_descuento' => $venta->motivo_descuento,
                'comentario_aprobacion_descuento' => $venta->comentario_aprobacion_descuento,
                'aprobado_por' => $venta->aprobadorDescuento?->name,
                'aprobado_en' => $venta->aprobado_descuento_en?->toIso8601String(),
                'forma_pago' => $venta->forma_pago,
                'modalidad_credito' => $venta->modalidad_credito,
                'cuota_mensual_credito' => $venta->cuota_mensual_credito !== null ? (float) $venta->cuota_mensual_credito : null,
                'meses_credito' => $venta->meses_credito,
                'fecha_siguiente_pago_credito' => $venta->fecha_siguiente_pago_credito?->format('Y-m-d'),
                'plan_cuotas_credito' => $venta->plan_cuotas_credito ?? [],
                'requiere_aprobacion_credito' => (bool) $venta->requiere_aprobacion_credito,
                'estado_aprobacion_credito' => $venta->estado_aprobacion_credito,
                'comentario_aprobacion_credito' => $venta->comentario_aprobacion_credito,
                'aprobado_credito_por' => $venta->aprobadorCredito?->name,
                'aprobado_credito_en' => $venta->aprobado_credito_en?->toIso8601String(),
                'puede_aprobar_credito' => $this->puedeAprobarCredito($usuario),
                'fecha_vencimiento_credito' => $venta->fecha_vencimiento_credito?->format('Y-m-d'),
                'observaciones_credito' => $venta->observaciones_credito,
                'vendedor' => $venta->user?->name ?? 'Vendedor',
                'puede_aprobar_descuento' => $this->puedeAprobarDescuento($usuario),
                'created_at' => $venta->created_at?->toIso8601String(),
                'vehiculo' => [
                    'id_vehiculo' => $vehiculo?->id_vehiculo ?? $venta->id_vehiculo,
                    'marca' => $vehiculo?->marca?->nombre ?? 'No registrada',
                    'modelo' => $vehiculo?->modelo ?? 'No registrado',
                    'anio' => $vehiculo?->anio,
                    'color' => $vehiculo?->color?->nombre ?? 'No registrado',
                    'kilometraje' => $vehiculo?->kilometraje,
                    'clase' => $vehiculo?->clase?->nombre,
                    'transmision' => $vehiculo?->transmision?->nombre,
                    'patio' => $vehiculo?->patio?->nombre ?? 'Sin asignar',
                    'estado' => $vehiculo?->estado?->nombre ?? 'En proceso',
                    'chasis' => $vehiculo?->chasis ?? '',
                    'motor' => $vehiculo?->motor ?? '',
                    'imagen_url' => $vehiculo?->imagenPrincipal
                        ? Storage::disk('public')->url($vehiculo->imagenPrincipal->ruta_imagen)
                        : null,
                ],
                'pagos' => $venta->pagos->map(static fn (PagoVenta $pago): array => [
                    'id_pago' => $pago->id_pago_venta,
                    'monto' => (float) $pago->monto,
                    'tipo_pago' => $pago->tipo_pago,
                    'fecha_pago' => $pago->fecha_pago?->format('Y-m-d'),
                    'registrado_por' => $pago->registrador?->name,
                    'comprobante_url' => route('mis-ventas.pago.comprobante', [$venta->id_venta, $pago->id_pago_venta]),
                ])->values(),
                'datos_cedula_comprador' => $venta->datos_cedula_comprador,
                'cedula_url' => $venta->ruta_cedula_comprador === null
                    ? null
                    : route('mis-ventas.cedula', $venta->id_venta),
                'constancia_url' => $venta->ruta_constancia_generada === null
                    ? null
                    : route('mis-ventas.constancia', $venta->id_venta),
            ],
            'success' => session('success'),
        ]);
    }

    public function actualizarOperacion(Request $request, Venta $venta): RedirectResponse
    {
        abort_unless($this->puedeAccederVenta($request->user(), $venta), 403);

        $validated = $request->validate([
            'tipo_operacion' => ['required', Rule::in(['venta', 'reserva'])],
            'precio_acordado' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
        ]);

        $venta->load('vehiculo');
        [$precioLista, $precioFinal, $precioMinimo] = $this->resolverUmbralPrecioMinimo($venta);
        $precioAcordado = round((float) $validated['precio_acordado'], 2);

        $esMenorAlMinimo = $precioMinimo > 0 && $precioAcordado < $precioMinimo - 0.009;
        $descuentoYaAprobadoParaMonto = $venta->estado_aprobacion_descuento === 'aprobado'
            && abs(((float) $venta->precio_acordado) - $precioAcordado) < 0.01;

        if ($esMenorAlMinimo && ! $descuentoYaAprobadoParaMonto) {
            $etiquetaUmbral = ($precioFinal !== null && $precioFinal > 0)
                ? 'Precio Final mínimo establecido'
                : 'Precio de Lista fijado por el Administrador';

            throw ValidationException::withMessages([
                'precio_acordado' => sprintf(
                    'El precio ingresado ($%s) es menor al %s ($%s). Debes pulsar «Enviar a aprobación» para solicitar autorización del descuento.',
                    number_format($precioAcordado, 2, '.', ','),
                    $etiquetaUmbral,
                    number_format($precioMinimo, 2, '.', ','),
                ),
            ]);
        }

        DB::transaction(function () use ($venta, $validated, $precioLista, $precioAcordado, $esMenorAlMinimo): void {
            $ventaBloqueada = Venta::query()
                ->whereKey($venta->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $ventaBloqueada->update([
                'tipo_operacion' => $validated['tipo_operacion'],
                'precio_base' => $precioLista,
                'precio_acordado' => $precioAcordado,
                'precio_venta' => $precioAcordado,
                'requiere_aprobacion_descuento' => $esMenorAlMinimo,
                'estado_aprobacion_descuento' => $esMenorAlMinimo ? 'aprobado' : null,
                'estado' => in_array($ventaBloqueada->estado, ['pagada', 'aprobada'], true)
                    ? $ventaBloqueada->estado
                    : 'en_proceso',
            ]);

            if (! in_array($ventaBloqueada->estado, ['pagada', 'aprobada'], true)) {
                $nombreEstadoVehiculo = $validated['tipo_operacion'] === 'reserva'
                    ? 'En proceso de reserva'
                    : 'En proceso de venta';
                $estadoVehiculo = EstadoVehiculo::firstOrCreate(['nombre' => $nombreEstadoVehiculo]);
                Vehiculo::query()
                    ->where('id_vehiculo', $ventaBloqueada->id_vehiculo)
                    ->update(['id_estado' => $estadoVehiculo->id_estado]);
            }
        });

        return back()->with('success', 'Tipo de operación y precio de venta actualizados correctamente.');
    }

    public function solicitarAprobacionDescuento(Request $request, Venta $venta): RedirectResponse
    {
        abort_unless($this->puedeAccederVenta($request->user(), $venta), 403);

        $validated = $request->validate([
            'tipo_operacion' => ['required', Rule::in(['venta', 'reserva'])],
            'precio_acordado' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'motivo_descuento' => ['nullable', 'string', 'max:1000'],
        ]);

        $venta->load('vehiculo');
        [$precioLista, $precioFinal, $precioMinimo] = $this->resolverUmbralPrecioMinimo($venta);
        $precioAcordado = round((float) $validated['precio_acordado'], 2);

        if ($precioMinimo <= 0 || $precioAcordado >= $precioMinimo - 0.009) {
            $etiquetaUmbral = ($precioFinal !== null && $precioFinal > 0)
                ? 'Precio Final mínimo establecido'
                : 'Precio de Lista';

            throw ValidationException::withMessages([
                'precio_acordado' => "El precio ingresado no es inferior al {$etiquetaUmbral}; puedes guardarlo directamente sin solicitar aprobación.",
            ]);
        }

        DB::transaction(function () use ($venta, $validated, $precioLista, $precioAcordado): void {
            $ventaBloqueada = Venta::query()
                ->whereKey($venta->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $ventaBloqueada->update([
                'tipo_operacion' => $validated['tipo_operacion'],
                'precio_base' => $precioLista,
                'precio_acordado' => $precioAcordado,
                'precio_venta' => $precioAcordado,
                'requiere_aprobacion_descuento' => true,
                'estado_aprobacion_descuento' => 'pendiente',
                'motivo_descuento' => trim((string) ($validated['motivo_descuento'] ?? '')),
                'comentario_aprobacion_descuento' => null,
                'aprobado_descuento_por' => null,
                'aprobado_descuento_en' => null,
                'estado' => 'pendiente_aprobacion',
            ]);

            $nombreEstadoVehiculo = $validated['tipo_operacion'] === 'reserva'
                ? 'En proceso de reserva'
                : 'En proceso de venta';
            $estadoVehiculo = EstadoVehiculo::firstOrCreate(['nombre' => $nombreEstadoVehiculo]);
            Vehiculo::query()
                ->where('id_vehiculo', $ventaBloqueada->id_vehiculo)
                ->update(['id_estado' => $estadoVehiculo->id_estado]);
        });

        return back()->with('success', 'Solicitud de descuento enviada a aprobación de Administración/Gerencia.');
    }

    public function decidirDescuento(Request $request, Venta $venta): RedirectResponse
    {
        abort_unless(
            $this->puedeAprobarDescuento($request->user()),
            403,
            'Solo el Administrador o Administración puede aprobar o rechazar descuentos de venta.',
        );

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['aprobado', 'rechazado'])],
            'comentario_aprobacion_descuento' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $venta, $validated): void {
            $ventaBloqueada = Venta::query()
                ->whereKey($venta->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $esAprobado = $validated['decision'] === 'aprobado';

            $ventaBloqueada->update([
                'estado_aprobacion_descuento' => $validated['decision'],
                'comentario_aprobacion_descuento' => trim((string) ($validated['comentario_aprobacion_descuento'] ?? '')),
                'aprobado_descuento_por' => $request->user()->id,
                'aprobado_descuento_en' => now(),
                'estado' => $esAprobado ? 'en_proceso' : 'descuento_rechazado',
            ]);
        });

        $mensaje = $validated['decision'] === 'aprobado'
            ? 'Descuento aprobado. El vendedor ya puede registrar los comprobantes de pago.'
            : 'Descuento rechazado. El vendedor deberá ajustar el precio de venta.';

        return back()->with('success', $mensaje);
    }

    public function registrarFormaPago(Request $request, Venta $venta): RedirectResponse
    {
        abort_unless($this->puedeAccederVenta($request->user(), $venta), 403);
        $this->verificarPrecioAutorizadoParaCierre($venta);

        $formaPago = (string) $request->input('forma_pago', '');
        $modalidadCredito = (string) $request->input('modalidad_credito', 'abierto');
        $reglas = [
            'forma_pago' => ['required', Rule::in(['contado', 'cuotas', 'credito'])],
        ];

        if ($formaPago === 'credito') {
            $reglas['modalidad_credito'] = ['required', Rule::in(['abierto', 'mayor_6_meses'])];
            $reglas['observaciones'] = ['nullable', 'string', 'max:1000'];

            if ($modalidadCredito === 'mayor_6_meses') {
                $reglas['cuota_mensual'] = ['required', 'numeric', 'gt:0', 'max:999999999999.99'];
                $reglas['meses_credito'] = ['required', 'integer', 'min:7', 'max:120'];
                $reglas['fecha_siguiente_pago'] = ['required', 'date', 'after_or_equal:today'];
            } else {
                $reglas['cuotas_credito'] = ['required', 'array', 'min:1', 'max:120'];
                $reglas['cuotas_credito.*.monto'] = ['required', 'numeric', 'gt:0', 'max:999999999999.99'];
                $reglas['cuotas_credito.*.fecha_vencimiento'] = ['required', 'date'];
            }
        } elseif ($formaPago === 'cuotas') {
            $reglas['cuotas'] = ['required', 'array', 'min:1', 'max:20'];
            $reglas['cuotas.*.comprobante'] = ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'];
            $reglas['cuotas.*.monto'] = ['required', 'numeric', 'gt:0'];
            $reglas['cuotas.*.fecha_pago'] = ['nullable', 'date'];
        } else {
            $reglas['documento_comprobante'] = ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'];
            $reglas['fecha_pago'] = ['nullable', 'date'];
        }

        $validated = $request->validate($reglas, [
            'meses_credito.min' => 'El plazo para «Crédito > 6 meses» debe ser mayor a 6 meses (mínimo 7 meses).',
            'fecha_siguiente_pago.after_or_equal' => 'La fecha del siguiente pago debe ser hoy o una fecha posterior.',
        ]);

        $precioObjetivo = round((float) ($venta->precio_acordado ?? $venta->precio_venta ?? $venta->precio_base ?? 0), 2);
        $rutasSubidas = [];
        $pagosPreparados = [];
        $datosCredito = [
            'modalidad_credito' => null,
            'cuota_mensual_credito' => null,
            'meses_credito' => null,
            'fecha_siguiente_pago_credito' => null,
            'plan_cuotas_credito' => null,
            'requiere_aprobacion_credito' => false,
            'estado_aprobacion_credito' => null,
            'comentario_aprobacion_credito' => null,
            'aprobado_credito_por' => null,
            'aprobado_credito_en' => null,
            'fecha_vencimiento_credito' => null,
            'observaciones_credito' => null,
        ];

        if ($formaPago === 'credito') {
            $datosCredito['modalidad_credito'] = $validated['modalidad_credito'];
            $datosCredito['observaciones_credito'] = trim((string) ($validated['observaciones'] ?? '')) ?: null;

            if ($validated['modalidad_credito'] === 'mayor_6_meses') {
                $cuotaMensual = round((float) $validated['cuota_mensual'], 2);
                $meses = (int) $validated['meses_credito'];
                $fechaBase = Carbon::parse($validated['fecha_siguiente_pago']);
                $plan = [];

                for ($i = 0; $i < $meses; $i++) {
                    $fechaCuota = $fechaBase->copy()->addMonthsNoOverflow($i)->toDateString();
                    $plan[] = [
                        'numero' => $i + 1,
                        'monto' => $cuotaMensual,
                        'fecha_vencimiento' => $fechaCuota,
                    ];
                }

                $datosCredito['cuota_mensual_credito'] = $cuotaMensual;
                $datosCredito['meses_credito'] = $meses;
                $datosCredito['fecha_siguiente_pago_credito'] = $fechaBase->toDateString();
                $datosCredito['plan_cuotas_credito'] = $plan;
                $datosCredito['fecha_vencimiento_credito'] = $plan[$meses - 1]['fecha_vencimiento'];
                $datosCredito['requiere_aprobacion_credito'] = true;
                $datosCredito['estado_aprobacion_credito'] = 'pendiente';
            } else {
                $sumaCredito = 0.0;
                $plan = [];

                foreach (array_values($validated['cuotas_credito']) as $idx => $item) {
                    $montoCuota = round((float) $item['monto'], 2);
                    $sumaCredito = round($sumaCredito + $montoCuota, 2);
                    $plan[] = [
                        'numero' => $idx + 1,
                        'monto' => $montoCuota,
                        'fecha_vencimiento' => Carbon::parse($item['fecha_vencimiento'])->toDateString(),
                    ];
                }

                if ($precioObjetivo > 0 && abs($sumaCredito - $precioObjetivo) > 0.009) {
                    throw ValidationException::withMessages([
                        'cuotas_credito' => sprintf(
                            'La suma de las cuotas del crédito ($%s) debe coincidir exactamente con el valor total de la venta ($%s).',
                            number_format($sumaCredito, 2, '.', ','),
                            number_format($precioObjetivo, 2, '.', ','),
                        ),
                    ]);
                }

                $fechasOrdenadas = array_column($plan, 'fecha_vencimiento');
                sort($fechasOrdenadas);

                $datosCredito['meses_credito'] = count($plan);
                $datosCredito['fecha_siguiente_pago_credito'] = $fechasOrdenadas[0] ?? null;
                $datosCredito['fecha_vencimiento_credito'] = $fechasOrdenadas[count($fechasOrdenadas) - 1] ?? null;
                $datosCredito['plan_cuotas_credito'] = $plan;
            }
        } elseif ($formaPago === 'cuotas') {
            $sumaCuotas = 0.0;
            foreach ($validated['cuotas'] as $item) {
                $montoCuota = round((float) $item['monto'], 2);
                $sumaCuotas = round($sumaCuotas + $montoCuota, 2);
            }

            if ($precioObjetivo > 0 && $sumaCuotas > $precioObjetivo + 0.009) {
                throw ValidationException::withMessages([
                    'cuotas' => sprintf(
                        'La suma de los comprobantes ($%s) no puede superar el precio acordado ($%s).',
                        number_format($sumaCuotas, 2, '.', ','),
                        number_format($precioObjetivo, 2, '.', ','),
                    ),
                ]);
            }

            foreach ($request->file('cuotas', []) as $idx => $archivosItem) {
                $archivoCuota = $archivosItem['comprobante'] ?? null;
                $ruta = $archivoCuota?->store("ventas/{$venta->id_venta}/pagos", 'local');
                if (! is_string($ruta)) {
                    Storage::disk('local')->delete($rutasSubidas);
                    throw ValidationException::withMessages([
                        'cuotas' => 'No se pudo almacenar uno de los comprobantes en el disco privado.',
                    ]);
                }
                $rutasSubidas[] = $ruta;
                $pagosPreparados[] = [
                    'ruta_comprobante' => $ruta,
                    'monto' => round((float) $validated['cuotas'][$idx]['monto'], 2),
                    'tipo_pago' => 'cuota',
                    'fecha_pago' => $validated['cuotas'][$idx]['fecha_pago'] ?? now()->toDateString(),
                ];
            }
        } elseif ($formaPago === 'contado') {
            $archivoContado = $request->file('documento_comprobante');
            $ruta = $archivoContado?->store("ventas/{$venta->id_venta}/pagos", 'local');
            if (! is_string($ruta)) {
                throw ValidationException::withMessages([
                    'documento_comprobante' => 'No se pudo almacenar el comprobante de pago en el disco privado.',
                ]);
            }
            $rutasSubidas[] = $ruta;
            $pagosPreparados[] = [
                'ruta_comprobante' => $ruta,
                'monto' => $precioObjetivo,
                'tipo_pago' => 'contado',
                'fecha_pago' => $validated['fecha_pago'] ?? now()->toDateString(),
            ];
        }

        try {
            DB::transaction(function () use ($request, $venta, $formaPago, $pagosPreparados, $precioObjetivo, $datosCredito): void {
                $ventaBloqueada = Venta::query()
                    ->whereKey($venta->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $metodoPago = MetodoPago::firstOrCreate([
                    'nombre' => ucfirst($formaPago),
                ]);

                foreach ($pagosPreparados as $pagoData) {
                    PagoVenta::create([
                        'id_venta' => $ventaBloqueada->id_venta,
                        'id_vehiculo' => $ventaBloqueada->id_vehiculo,
                        'ruta_comprobante' => $pagoData['ruta_comprobante'],
                        'monto' => $pagoData['monto'],
                        'tipo_pago' => $pagoData['tipo_pago'],
                        'fecha_pago' => $pagoData['fecha_pago'],
                        'registrado_por' => $request->user()->id,
                    ]);
                }

                $requiereAprobacionCredito = (bool) $datosCredito['requiere_aprobacion_credito'];
                $esReserva = ($ventaBloqueada->tipo_operacion ?? 'venta') === 'reserva';

                if (! $requiereAprobacionCredito) {
                    $nombreEstadoFinalVehiculo = $esReserva ? 'Reservado' : 'Vendido';
                    $estadoFinalVehiculo = EstadoVehiculo::firstOrCreate(['nombre' => $nombreEstadoFinalVehiculo]);

                    Vehiculo::query()
                        ->where('id_vehiculo', $ventaBloqueada->id_vehiculo)
                        ->update(['id_estado' => $estadoFinalVehiculo->id_estado]);
                }

                $nuevoEstadoVenta = $requiereAprobacionCredito
                    ? 'pendiente_aprobacion_credito'
                    : ($ventaBloqueada->ruta_constancia_generada !== null ? 'aprobada' : 'pagada');

                $ventaBloqueada->update([
                    'id_metodo_pago' => $metodoPago->id_metodo_pago,
                    'forma_pago' => $formaPago,
                    'precio_venta' => $precioObjetivo,
                    ...$datosCredito,
                    'estado' => $nuevoEstadoVenta,
                ]);

                $tipoMovimiento = Tipo_movimiento::firstOrCreate([
                    'nombre' => $requiereAprobacionCredito
                        ? 'Solicitud de Crédito > 6 meses'
                        : ($esReserva ? 'Reserva de Vehículo' : 'Venta de Vehículo'),
                ]);

                $descripcionMovimiento = $requiereAprobacionCredito
                    ? sprintf(
                        'Solicitud de Crédito > 6 meses (%d cuotas mensuales de $%s · Total proyectado: $%s · Inicio: %s). Pendiente de aprobación.',
                        (int) $datosCredito['meses_credito'],
                        number_format((float) $datosCredito['cuota_mensual_credito'], 2, '.', ','),
                        number_format(((float) $datosCredito['cuota_mensual_credito']) * ((int) $datosCredito['meses_credito']), 2, '.', ','),
                        (string) $datosCredito['fecha_siguiente_pago_credito'],
                    )
                    : sprintf(
                        '%s registrada · Forma de pago: %s · Valor acordado: $%s.',
                        $esReserva ? 'Reserva' : 'Venta',
                        ucfirst($formaPago),
                        number_format($precioObjetivo, 2, '.', ','),
                    );

                Movimientos_vehiculo::create([
                    'id_vehiculo' => $ventaBloqueada->id_vehiculo,
                    'id_tipo_movimiento' => $tipoMovimiento->id_tipo_movimiento,
                    'id_usuario' => $request->user()->id,
                    'descripcion' => $descripcionMovimiento,
                    'fecha_movimiento' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            if ($rutasSubidas !== []) {
                Storage::disk('local')->delete($rutasSubidas);
            }

            throw $exception;
        }

        if ($datosCredito['requiere_aprobacion_credito']) {
            return back()->with('success', 'Plan de Crédito > 6 meses registrado. Queda en estado «Pendiente de aprobación de crédito» hasta ser autorizado por Administración o Administrador.');
        }

        return back()->with('success', 'Forma de pago registrada correctamente. Ahora puedes generar la constancia.');
    }

    public function decidirCredito(Request $request, Venta $venta): RedirectResponse
    {
        abort_unless(
            $this->puedeAprobarCredito($request->user()),
            403,
            'Solo el área Administrativa o el Administrador puede aprobar o rechazar créditos mayores a 6 meses.',
        );

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['aprobado', 'rechazado'])],
            'comentario_aprobacion_credito' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $venta, $validated): void {
            $ventaBloqueada = Venta::query()
                ->whereKey($venta->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $esAprobado = $validated['decision'] === 'aprobado';
            $esReserva = ($ventaBloqueada->tipo_operacion ?? 'venta') === 'reserva';

            $ventaBloqueada->update([
                'estado_aprobacion_credito' => $validated['decision'],
                'comentario_aprobacion_credito' => trim((string) ($validated['comentario_aprobacion_credito'] ?? '')) ?: null,
                'aprobado_credito_por' => $request->user()->id,
                'aprobado_credito_en' => now(),
                'estado' => $esAprobado
                    ? ($ventaBloqueada->ruta_constancia_generada !== null ? 'aprobada' : 'pagada')
                    : 'credito_rechazado',
            ]);

            if ($esAprobado) {
                $nombreEstadoFinalVehiculo = $esReserva ? 'Reservado' : 'Vendido';
                $estadoFinalVehiculo = EstadoVehiculo::firstOrCreate(['nombre' => $nombreEstadoFinalVehiculo]);
                Vehiculo::query()
                    ->where('id_vehiculo', $ventaBloqueada->id_vehiculo)
                    ->update(['id_estado' => $estadoFinalVehiculo->id_estado]);
            }

            $tipoMovimiento = Tipo_movimiento::firstOrCreate([
                'nombre' => $esAprobado ? 'Aprobación de Crédito > 6 meses' : 'Rechazo de Crédito > 6 meses',
            ]);
            Movimientos_vehiculo::create([
                'id_vehiculo' => $ventaBloqueada->id_vehiculo,
                'id_tipo_movimiento' => $tipoMovimiento->id_tipo_movimiento,
                'id_usuario' => $request->user()->id,
                'descripcion' => sprintf(
                    'Crédito > 6 meses %s por %s.%s',
                    $esAprobado ? 'aprobado' : 'rechazado',
                    $request->user()->name,
                    ! empty($validated['comentario_aprobacion_credito'])
                        ? ' Comentario: '.trim((string) $validated['comentario_aprobacion_credito'])
                        : '',
                ),
                'fecha_movimiento' => now(),
            ]);
        });

        $mensaje = $validated['decision'] === 'aprobado'
            ? 'Crédito > 6 meses aprobado correctamente. Ya se puede continuar con la constancia.'
            : 'Crédito > 6 meses rechazado. El vendedor podrá configurar una nueva modalidad de pago.';

        return back()->with('success', $mensaje);
    }

    public function registrarAbonoCuota(Request $request, Venta $venta): RedirectResponse
    {
        abort_unless($this->puedeAccederVenta($request->user(), $venta), 403);
        $this->verificarPrecioAutorizadoParaCierre($venta);

        $validated = $request->validate([
            'comprobante' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'monto' => ['required', 'numeric', 'gt:0'],
            'fecha_pago' => ['nullable', 'date'],
        ]);

        $ruta = $request->file('comprobante')->store("ventas/{$venta->id_venta}/pagos", 'local');
        if (! is_string($ruta)) {
            throw ValidationException::withMessages([
                'comprobante' => 'No se pudo almacenar el comprobante en el disco privado.',
            ]);
        }

        try {
            DB::transaction(function () use ($request, $venta, $validated, $ruta): void {
                $ventaBloqueada = Venta::query()
                    ->whereKey($venta->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $precioObjetivo = round((float) ($ventaBloqueada->precio_acordado ?? $ventaBloqueada->precio_venta ?? 0), 2);
                $totalPagado = round((float) PagoVenta::query()->where('id_venta', $ventaBloqueada->id_venta)->sum('monto'), 2);
                $saldoRestante = max(0.0, round($precioObjetivo - $totalPagado, 2));
                $montoAbono = round((float) $validated['monto'], 2);

                if ($precioObjetivo > 0 && $montoAbono > $saldoRestante + 0.009) {
                    throw ValidationException::withMessages([
                        'monto' => sprintf(
                            'El abono ($%s) supera el saldo pendiente ($%s).',
                            number_format($montoAbono, 2, '.', ','),
                            number_format($saldoRestante, 2, '.', ','),
                        ),
                    ]);
                }

                PagoVenta::create([
                    'id_venta' => $ventaBloqueada->id_venta,
                    'id_vehiculo' => $ventaBloqueada->id_vehiculo,
                    'ruta_comprobante' => $ruta,
                    'monto' => $montoAbono,
                    'tipo_pago' => 'cuota',
                    'fecha_pago' => $validated['fecha_pago'] ?? now()->toDateString(),
                    'registrado_por' => $request->user()->id,
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($ruta);
            throw $exception;
        }

        return back()->with('success', 'Comprobante y abono adicional registrados correctamente.');
    }

    public function descargarComprobantePago(Request $request, Venta $venta, PagoVenta $pago): StreamedResponse
    {
        abort_unless($this->puedeAccederVenta($request->user(), $venta), 403);
        abort_unless(
            $pago->id_venta === $venta->id_venta
                && is_string($pago->ruta_comprobante)
                && Storage::disk('local')->exists($pago->ruta_comprobante),
            404,
        );

        $extension = pathinfo($pago->ruta_comprobante, PATHINFO_EXTENSION);
        $nombreDescarga = $extension !== ''
            ? "comprobante-venta-{$venta->id_vehiculo}-{$pago->id_pago_venta}.{$extension}"
            : "comprobante-venta-{$venta->id_vehiculo}-{$pago->id_pago_venta}";

        return Storage::disk('local')->download(
            $pago->ruta_comprobante,
            $nombreDescarga,
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function escanearCedula(Request $request, Venta $venta): JsonResponse
    {
        set_time_limit(120);
        abort_unless($this->puedeAccederVenta($request->user(), $venta), 403);

        $request->validate([
            'documento_cedula' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $archivo = $request->file('documento_cedula');
        $mimeType = $archivo->getMimeType() ?: 'image/jpeg';
        $archivoBytes = file_get_contents($archivo->getRealPath());

        if ($archivoBytes === false) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo leer el archivo de la cédula cargado.',
            ], 422);
        }

        $apiKey = config('services.gemini.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            return response()->json([
                'success' => false,
                'message' => 'Gemini no está configurado. Define GEMINI_API_KEY en .env.',
            ], 503);
        }

        $rutaAnterior = $venta->ruta_cedula_comprador;
        $rutaCedula = $archivo->store('documentos/cedulas', 'local');

        if ($rutaCedula === false) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo almacenar la cédula en el disco privado.',
            ], 500);
        }

        $base64Data = base64_encode($archivoBytes);
        $prompt = 'Analiza esta cédula de identidad de Ecuador (anverso y/o reverso). '
            .'Extrae únicamente los datos visibles del titular; no inventes ni deduzcas información que no aparezca. '
            .'Devuelve únicamente un objeto JSON válido con estas claves exactas: '
            .'{"nombre_completo": "", "cedula": "", "estado_civil": "", "es_casado": false, "nombre_conyuge": ""}.';

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => $base64Data,
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.0,
            ],
        ];

        try {
            $respuestaGemini = $this->ejecutarEscaneoGemini($apiKey, $payload);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($rutaCedula);
            Log::warning('Error al escanear cédula de comprador en MisVentas.', [
                'id_venta' => $venta->id_venta,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo completar el escaneo de la cédula con Gemini: '.$exception->getMessage(),
            ], 502);
        }

        $textoJson = $respuestaGemini['candidates'][0]['content']['parts'][0]['text'] ?? null;
        $textoLimpio = is_string($textoJson)
            ? (preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($textoJson)) ?? trim($textoJson))
            : '';
        $datosExtraidos = json_decode($textoLimpio, true);

        if (! is_array($datosExtraidos)) {
            Storage::disk('local')->delete($rutaCedula);

            return response()->json([
                'success' => false,
                'message' => 'Gemini no devolvió datos legibles de la cédula. Intenta con una imagen más nítida.',
            ], 422);
        }

        $estadoCivil = strtoupper(trim((string) ($datosExtraidos['estado_civil'] ?? '')));
        $esCasado = filter_var($datosExtraidos['es_casado'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || str_contains($estadoCivil, 'CASAD')
            || str_contains($estadoCivil, 'UNION');

        $datosCedula = [
            'nombre_completo' => strtoupper(trim((string) ($datosExtraidos['nombre_completo'] ?? ''))),
            'cedula' => preg_replace('/[^0-9]/', '', (string) ($datosExtraidos['cedula'] ?? '')) ?? '',
            'estado_civil' => $estadoCivil,
            'es_casado' => $esCasado,
            'nombre_conyuge' => $esCasado ? strtoupper(trim((string) ($datosExtraidos['nombre_conyuge'] ?? ''))) : '',
            'cedula_conyuge' => '',
        ];

        $venta->update([
            'ruta_cedula_comprador' => $rutaCedula,
            'datos_cedula_comprador' => $datosCedula,
        ]);

        if ($rutaAnterior !== null && $rutaAnterior !== $rutaCedula) {
            Storage::disk('local')->delete($rutaAnterior);
        }

        return response()->json([
            'success' => true,
            'ruta_cedula_comprador' => $rutaCedula,
            'datos_cedula_comprador' => $datosCedula,
            'cedula_url' => route('mis-ventas.cedula', $venta->id_venta),
        ]);
    }

    public function generarConstancia(Request $request, Venta $venta): JsonResponse
    {
        abort_unless($this->puedeAccederVenta($request->user(), $venta), 403);
        $this->verificarPrecioAutorizadoParaCierre($venta);

        if ($venta->requiere_aprobacion_credito && $venta->estado_aprobacion_credito !== 'aprobado') {
            throw ValidationException::withMessages([
                'forma_pago' => 'El plan de Crédito > 6 meses aún está pendiente de aprobación por Administración o Administrador.',
            ]);
        }

        $validated = $request->validate([
            'nombre_completo' => ['required', 'string', 'max:200'],
            'cedula' => ['required', 'string', 'max:30'],
            'estado_civil' => ['nullable', 'string', 'max:60'],
            'es_casado' => ['required', 'boolean'],
            'nombre_conyuge' => ['nullable', 'string', 'max:200', 'required_if:es_casado,true'],
            'cedula_conyuge' => ['nullable', 'string', 'max:30'],
        ]);

        $plantilla = storage_path(self::PLANTILLA_CONSTANCIA_VENTA);
        if (! is_file($plantilla)) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró la plantilla Word en storage/'.self::PLANTILLA_CONSTANCIA_VENTA,
            ], 500);
        }

        $venta->load(['vehiculo.marca', 'vehiculo.color']);
        $datosCedulaActualizados = [
            'nombre_completo' => mb_strtoupper(trim($validated['nombre_completo']), 'UTF-8'),
            'cedula' => trim($validated['cedula']),
            'estado_civil' => mb_strtoupper(trim((string) ($validated['estado_civil'] ?? '')), 'UTF-8'),
            'es_casado' => (bool) $validated['es_casado'],
            'nombre_conyuge' => $validated['es_casado']
                ? mb_strtoupper(trim((string) ($validated['nombre_conyuge'] ?? '')), 'UTF-8')
                : '',
            'cedula_conyuge' => $validated['es_casado']
                ? trim((string) ($validated['cedula_conyuge'] ?? ''))
                : '',
        ];

        try {
            $rutaRelativa = $this->generarDocumentoWordVenta($venta, $plantilla, $datosCedulaActualizados);
        } catch (Throwable $exception) {
            Log::error('Error al generar constancia Word en MisVentas.', [
                'id_venta' => $venta->id_venta,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo generar el documento Word: '.$exception->getMessage(),
            ], 500);
        }

        $rutaAnterior = $venta->ruta_constancia_generada;
        $venta->update([
            'datos_cedula_comprador' => $datosCedulaActualizados,
            'ruta_constancia_generada' => $rutaRelativa,
            'estado' => 'aprobada',
        ]);

        Documentos_vehiculo::query()
            ->where('id_vehiculo', $venta->id_vehiculo)
            ->update([
                'ruta_acta_entrega' => $rutaRelativa,
            ]);

        if ($rutaAnterior !== null && $rutaAnterior !== $rutaRelativa) {
            Storage::disk('local')->delete($rutaAnterior);
        }

        return response()->json([
            'success' => true,
            'ruta_constancia_generada' => $rutaRelativa,
            'constancia_url' => route('mis-ventas.constancia', $venta->id_venta),
            'datos_cedula_comprador' => $datosCedulaActualizados,
        ]);
    }

    public function descargarConstancia(Request $request, Venta $venta): StreamedResponse
    {
        abort_unless($this->puedeAccederVenta($request->user(), $venta), 403);
        abort_unless(
            $venta->ruta_constancia_generada !== null
                && Storage::disk('local')->exists($venta->ruta_constancia_generada),
            404,
        );

        $prefijo = ($venta->tipo_operacion ?? 'venta') === 'reserva' ? 'constancia-reserva' : 'constancia-venta';

        return Storage::disk('local')->download(
            $venta->ruta_constancia_generada,
            "{$prefijo}-{$venta->id_vehiculo}.docx",
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function descargarCedula(Request $request, Venta $venta): StreamedResponse
    {
        abort_unless($this->puedeAccederVenta($request->user(), $venta), 403);
        abort_unless(
            $venta->ruta_cedula_comprador !== null
                && Storage::disk('local')->exists($venta->ruta_cedula_comprador),
            404,
        );

        $extension = pathinfo($venta->ruta_cedula_comprador, PATHINFO_EXTENSION);
        $nombreDescarga = $extension !== ''
            ? "cedula-comprador-{$venta->id_vehiculo}.{$extension}"
            : "cedula-comprador-{$venta->id_vehiculo}";

        return Storage::disk('local')->download(
            $venta->ruta_cedula_comprador,
            $nombreDescarga,
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    /**
     * Servicio encapsulado de generación de plantilla Word (.docx) en disco privado 'local'.
     * Preparado para reemplazar la plantilla .docx cuando se defina el formato definitivo de venta/reserva.
     *
     * @param  array<string, mixed>  $datosComprador
     */
    protected function generarDocumentoWordVenta(
        Venta $venta,
        string $rutaPlantilla,
        array $datosComprador,
    ): string {
        $ahora = Carbon::now();
        $meses = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
            5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ];
        $diaNum = (int) $ahora->format('j');
        $anioNum = (int) $ahora->format('Y');
        $mesNombre = $meses[(int) $ahora->format('n')] ?? $ahora->format('F');

        $vehiculo = $venta->vehiculo;
        $precioOperacion = (float) ($venta->precio_acordado ?? $venta->precio_venta ?? $venta->precio_base ?? 0);
        $precioFormateado = number_format($precioOperacion, 2, '.', ',');
        $precioLetras = $this->montoALetras($precioOperacion);

        $rutaRelativa = sprintf(
            'documentos/contratos/ventas/constancia_%s_%s_%s.docx',
            $venta->tipo_operacion ?? 'venta',
            preg_replace('/[^A-Z0-9]/i', '', $venta->id_vehiculo) ?: $venta->id_venta,
            $ahora->format('Ymd_His'),
        );
        $rutaAbsoluta = Storage::disk('local')->path($rutaRelativa);
        $directorio = dirname($rutaAbsoluta);

        if (! is_dir($directorio) && ! mkdir($directorio, 0775, true) && ! is_dir($directorio)) {
            throw new RuntimeException('No se pudo crear el directorio privado para la constancia.');
        }

        $esCasado = (bool) ($datosComprador['es_casado'] ?? false);
        $nombreConyuge = (string) ($datosComprador['nombre_conyuge'] ?? '');
        $cedulaConyuge = (string) ($datosComprador['cedula_conyuge'] ?? '');

        $template = new TemplateProcessor($rutaPlantilla);
        $template->setValue('dia_letras', $this->numeroALetras($diaNum));
        $template->setValue('dia', (string) $diaNum);
        $template->setValue('mes', $mesNombre);
        $template->setValue('anio_letra', $this->numeroALetras($anioNum));
        $template->setValue('anio', (string) $anioNum);

        $template->setValue('cliente', (string) ($datosComprador['nombre_completo'] ?? ''));
        $template->setValue('ci_cliente', (string) ($datosComprador['cedula'] ?? ''));

        $textoConyugeClausula = $esCasado && $nombreConyuge !== '' ? "y su cónyuge {$nombreConyuge}" : '';
        $textoConyugeFirma = $esCasado ? $nombreConyuge : '';
        $template->setValue('esta_casado', $textoConyugeClausula, 1);
        $template->setValue('esta_casado', $textoConyugeFirma, 1);
        $template->setValue('ci_esta_casado', $esCasado ? $cedulaConyuge : '');

        $template->setValue('marca_vehiculo', (string) ($vehiculo?->marca?->nombre ?? ''));
        $template->setValue('modelo_vehiculo ', (string) ($vehiculo?->modelo ?? ''));
        $template->setValue('modelo_vehiculo', (string) ($vehiculo?->modelo ?? ''));
        $template->setValue('anio_vehiculo ', (string) ($vehiculo?->anio ?? ''));
        $template->setValue('anio_vehiculo', (string) ($vehiculo?->anio ?? ''));
        $template->setValue('color_vehiculo ', (string) ($vehiculo?->color?->nombre ?? ''));
        $template->setValue('color_vehiculo', (string) ($vehiculo?->color?->nombre ?? ''));
        $template->setValue('chasis_vehiculo ', (string) ($vehiculo?->chasis ?? ''));
        $template->setValue('chasis_vehiculo', (string) ($vehiculo?->chasis ?? ''));
        $template->setValue('motor_vehiculo ', (string) ($vehiculo?->motor ?? ''));
        $template->setValue('motor_vehiculo', (string) ($vehiculo?->motor ?? ''));
        $template->setValue('id_vehiculo', (string) $venta->id_vehiculo);

        $template->setValue('costo_adquisicion', $precioFormateado);
        $template->setValue('costo_letras', $precioLetras);

        $template->saveAs($rutaAbsoluta);

        return $rutaRelativa;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function ejecutarEscaneoGemini(string $apiKey, array $payload): array
    {
        $scriptPath = base_path('escanear_gemini.cjs');
        if (! is_file($scriptPath)) {
            throw new RuntimeException('No se encontró el script escanear_gemini.cjs en la raíz del proyecto.');
        }

        $token = bin2hex(random_bytes(12));
        $archivoEntrada = storage_path("app/gemini_in_{$token}.json");
        $archivoSalida = storage_path("app/gemini_out_{$token}.json");

        try {
            $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if ($jsonPayload === false || file_put_contents($archivoEntrada, $jsonPayload) === false) {
                throw new RuntimeException('No se pudo crear el archivo temporal de entrada para Gemini.');
            }

            $comando = sprintf(
                'node %s %s %s %s 2>&1',
                escapeshellarg($scriptPath),
                escapeshellarg($apiKey),
                escapeshellarg($archivoEntrada),
                escapeshellarg($archivoSalida),
            );

            $salida = [];
            $codigo = 0;
            exec($comando, $salida, $codigo);

            if (! is_file($archivoSalida)) {
                throw new RuntimeException('escanear_gemini.cjs no generó archivo de salida (código '.$codigo.').');
            }

            $contenido = file_get_contents($archivoSalida);
            $decodificado = is_string($contenido) ? json_decode($contenido, true) : null;

            if (! is_array($decodificado)) {
                throw new RuntimeException('La salida de escanear_gemini.cjs no es un JSON válido.');
            }

            return $decodificado;
        } finally {
            @unlink($archivoEntrada);
            @unlink($archivoSalida);
        }
    }

    /**
     * Resuelve el precio de lista, el precio final (mínimo) y el umbral efectivo para autorización de descuento.
     *
     * @return array{0: float, 1: float|null, 2: float}
     */
    private function resolverUmbralPrecioMinimo(Venta $venta): array
    {
        $venta->loadMissing('vehiculo');
        $precioLista = round((float) ($venta->vehiculo?->precio_venta ?? $venta->precio_base ?? 0), 2);
        $precioFinal = $venta->vehiculo?->precio_final !== null
            ? round((float) $venta->vehiculo->precio_final, 2)
            : null;
        $precioMinimo = ($precioFinal !== null && $precioFinal > 0) ? $precioFinal : $precioLista;

        return [$precioLista, $precioFinal, $precioMinimo];
    }

    private function verificarPrecioAutorizadoParaCierre(Venta $venta): void
    {
        [, $precioFinal, $precioMinimo] = $this->resolverUmbralPrecioMinimo($venta);
        $precioAcordado = round((float) ($venta->precio_acordado ?? $venta->precio_venta ?? 0), 2);

        if ($precioMinimo > 0 && $precioAcordado < $precioMinimo - 0.009 && $venta->estado_aprobacion_descuento !== 'aprobado') {
            $etiquetaUmbral = ($precioFinal !== null && $precioFinal > 0)
                ? 'Precio Final mínimo establecido'
                : 'Precio de Lista fijado por el Administrador';

            throw ValidationException::withMessages([
                'precio_acordado' => "El precio acordado es inferior al {$etiquetaUmbral} y requiere aprobación antes de continuar.",
            ]);
        }
    }

    private function puedeUsarModuloVentas(User $usuario): bool
    {
        return $usuario->hasAnyRole(['Vendedor', 'Jefe de Patio', 'Administracion', 'Administrador'])
            || $usuario->can('vender vehiculo')
            || $usuario->can('reservar vehiculo');
    }

    private function puedeAprobarDescuento(User $usuario): bool
    {
        return $usuario->hasAnyRole(['Administrador', 'Administracion']);
    }

    private function puedeAccederVenta(User $usuario, Venta $venta): bool
    {
        if (! $this->puedeUsarModuloVentas($usuario)) {
            return false;
        }

        return $venta->id_user === $usuario->id || $this->puedeAprobarDescuento($usuario);
    }

    private function montoALetras(float $monto): string
    {
        $entero = (int) floor($monto);
        $centavos = (int) round(($monto - $entero) * 100);
        if ($centavos >= 100) {
            $entero++;
            $centavos = 0;
        }

        $textoEntero = mb_strtoupper($this->numeroALetras($entero), 'UTF-8');

        return $centavos > 0
            ? sprintf('%s CON %02d/100', $textoEntero, $centavos)
            : $textoEntero;
    }

    private function numeroALetras(int $numero): string
    {
        if ($numero === 0) {
            return 'cero';
        }

        $unidades = [
            '', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
            'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve',
            'veinte', 'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve',
        ];
        $decenas = ['', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
        $centenas = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

        if ($numero < 30) {
            return $unidades[$numero];
        }

        if ($numero < 100) {
            $d = intdiv($numero, 10);
            $u = $numero % 10;

            return $u === 0 ? $decenas[$d] : "{$decenas[$d]} y {$unidades[$u]}";
        }

        if ($numero === 100) {
            return 'cien';
        }

        if ($numero < 1000) {
            $c = intdiv($numero, 100);
            $r = $numero % 100;

            return $r === 0 ? $centenas[$c] : "{$centenas[$c]} {$this->numeroALetras($r)}";
        }

        if ($numero < 1000000) {
            $miles = intdiv($numero, 1000);
            $r = $numero % 1000;
            $prefijo = $miles === 1 ? 'mil' : $this->numeroALetras($miles).' mil';
            $prefijo = preg_replace('/uno mil$/', 'un mil', $prefijo) ?? $prefijo;

            return $r === 0 ? $prefijo : "{$prefijo} {$this->numeroALetras($r)}";
        }

        $millones = intdiv($numero, 1000000);
        $r = $numero % 1000000;
        $prefijo = $millones === 1 ? 'un millón' : $this->numeroALetras($millones).' millones';

        return $r === 0 ? $prefijo : "{$prefijo} {$this->numeroALetras($r)}";
    }
}
