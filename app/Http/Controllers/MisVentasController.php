<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Credito;
use App\Models\CuotaCredito;
use App\Models\Documentos_vehiculo;
use App\Models\estado_vehiculo as EstadoVehiculo;
use App\Models\Metodo_pago as MetodoPago;
use App\Models\Movimientos_vehiculo;
use App\Models\PagoCredito;
use App\Models\PagoVenta;
use App\Models\TasaInteres;
use App\Models\Tipo_movimiento;
use App\Models\Tipo_transaccion as TipoTransaccion;
use App\Models\Transacciones_caja as TransaccionCaja;
use App\Models\User;
use App\Models\vehiculo as Vehiculo;
use App\Models\Venta;
use App\Services\ConstanciaVentaGeneratorService;
use App\Services\PagareGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MisVentasController extends Controller
{
    public function __construct(
        private readonly PagareGeneratorService $pagareGeneratorService,
        private readonly ConstanciaVentaGeneratorService $constanciaVentaGeneratorService,
    ) {}
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

    private const TASA_MENSUAL_BASE_PCT = 1.7;

    private const TASA_MENSUAL_MES_13_PCT = 1.5;

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

        $esquemaCreditoNormalizado = Schema::hasTable('tasas_interes')
            && Schema::hasTable('cuotas_credito')
            && Schema::hasTable('pagos_credito')
            && Schema::hasColumn('creditos', 'id_venta');

        $relaciones = [
            'vehiculo.marca',
            'vehiculo.color',
            'vehiculo.clase',
            'vehiculo.transmision',
            'vehiculo.patio',
            'vehiculo.estado',
            'vehiculo.tipoContrato',
            'vehiculo.documentos.tipoContrato',
            'vehiculo.clienteVendedor.tipoCliente',
            'vehiculo.imagenPrincipal',
            'cliente',
            'user:id,name',
            'aprobadorDescuento:id,name',
            'aprobadorCredito:id,name',
            'pagos' => fn ($query) => $query->with('registrador:id,name')->orderBy('id_pago_venta'),
        ];

        if ($esquemaCreditoNormalizado) {
            $relaciones[] = 'credito.tasaInteres';
            $relaciones[] = 'credito.cuotas.pagos';
            $relaciones[] = 'credito.pagos.usuario:id,name';
            $relaciones[] = 'credito.pagos.cuota:id_cuota_credito,numero_cuota';
            $relaciones[] = 'credito.pagos.transaccionCaja:id_transaccion,monto,fecha_transaccion';
        }

        $venta->load($relaciones);

        $vehiculo = $venta->vehiculo;
        [$precioLista, $precioFinal, $precioMinimo] = $this->resolverUmbralPrecioMinimo($venta);
        $precioAcordado = (float) ($venta->precio_acordado ?? $venta->precio_venta ?? $precioLista);

        $creditoPayload = null;
        if ($esquemaCreditoNormalizado && $venta->credito !== null) {
            $this->sincronizarEstadosMoraCredito($venta->credito);
            $venta->credito->refresh()->load([
                'tasaInteres',
                'cuotas.pagos',
                'pagos.usuario:id,name',
                'pagos.cuota:id_cuota_credito,numero_cuota',
                'pagos.transaccionCaja:id_transaccion,monto,fecha_transaccion',
            ]);
            $creditoModel = $venta->credito;
            $modalidadPlan = (string) ($creditoModel->modalidad ?? ($venta->forma_pago === 'credito' ? 'credito' : ($venta->forma_pago ?? 'credito')));
            $esModalidadCredito = $modalidadPlan === 'credito' && $venta->forma_pago === 'credito';
            $tienePagare = $esModalidadCredito
                && is_string($creditoModel->ruta_pagare)
                && $creditoModel->ruta_pagare !== ''
                && Storage::disk('local')->exists($creditoModel->ruta_pagare);

            $creditoPayload = [
                'id_credito' => $creditoModel->id_credito,
                'id_cliente' => $creditoModel->id_cliente,
                'modalidad' => $modalidadPlan,
                'id_tasa_interes' => $creditoModel->id_tasa_interes,
                'tasa_interes_nombre' => $creditoModel->tasaInteres?->nombre,
                'tasa_anual' => $creditoModel->tasaInteres !== null ? (float) $creditoModel->tasaInteres->tasa_anual : null,
                'tasa_nominal_anual' => $creditoModel->tasaInteres !== null
                    ? (float) ($creditoModel->tasaInteres->tasa_nominal_anual ?? $creditoModel->tasaInteres->tasa_anual)
                    : null,
                'tasa_efectiva_anual' => $creditoModel->tasaInteres !== null
                    ? (float) ($creditoModel->tasaInteres->tasa_efectiva_anual ?? 0)
                    : null,
                'tasa_mensual' => $creditoModel->tasaInteres !== null ? (float) $creditoModel->tasaInteres->tasa_mensual : self::TASA_MENSUAL_BASE_PCT,
                'tasa_mensual_desde_mes_13' => $creditoModel->tasaInteres !== null
                    ? (float) ($creditoModel->tasaInteres->tasa_mensual_desde_mes_13 ?? self::TASA_MENSUAL_MES_13_PCT)
                    : self::TASA_MENSUAL_MES_13_PCT,
                'tasa_mora' => $creditoModel->tasaInteres !== null ? (float) $creditoModel->tasaInteres->tasa_mora : null,
                'monto_total' => (float) $creditoModel->monto_total,
                'monto_inicial' => (float) $creditoModel->monto_inicial,
                'monto_financiado' => (float) $creditoModel->monto_financiado,
                'saldo_pendiente' => (float) $creditoModel->saldo_pendiente,
                'numero_cuotas' => (int) $creditoModel->numero_cuotas,
                'fecha_inicio' => $creditoModel->fecha_inicio?->format('Y-m-d'),
                'fecha_vencimiento_final' => $creditoModel->fecha_vencimiento_final?->format('Y-m-d'),
                'estado' => $creditoModel->estado,
                'observaciones' => $creditoModel->observaciones,
                'pagare_url' => $tienePagare ? route('mis-ventas.credito.pagare', $venta->id_venta) : null,
                'cuotas' => $creditoModel->cuotas->map(static function (CuotaCredito $c) use ($venta): array {
                    $primerPago = $c->pagos->first();

                    return [
                        'id_cuota_credito' => $c->id_cuota_credito,
                        'numero_cuota' => $c->numero_cuota,
                        'fecha_vencimiento' => $c->fecha_vencimiento?->format('Y-m-d'),
                        'monto_capital' => (float) $c->monto_capital,
                        'monto_interes' => (float) $c->monto_interes,
                        'monto_mora' => (float) $c->monto_mora,
                        'valor_cuota' => (float) $c->valor_cuota,
                        'monto_pagado' => (float) $c->monto_pagado,
                        'saldo_cuota' => max(0.0, round(((float) $c->valor_cuota + (float) $c->monto_mora) - (float) $c->monto_pagado, 2)),
                        'fecha_pago_real' => $c->fecha_pago_real?->format('Y-m-d'),
                        'estado' => $c->estado,
                        'id_transaccion_caja' => $primerPago?->id_transaccion_caja,
                        'comprobante_url' => $primerPago?->ruta_comprobante
                            ? route('mis-ventas.credito.pagos.comprobante', [$venta->id_venta, $primerPago->id_pago_credito])
                            : null,
                    ];
                })->values(),
                'pagos' => $creditoModel->pagos->map(static fn (PagoCredito $p): array => [
                    'id_pago_credito' => $p->id_pago_credito,
                    'id_cuota_credito' => $p->id_cuota_credito,
                    'numero_cuota' => $p->cuota?->numero_cuota,
                    'id_transaccion_caja' => $p->id_transaccion_caja,
                    'monto_pagado' => (float) $p->monto_pagado,
                    'metodo_pago' => $p->metodo_pago,
                    'numero_referencia' => $p->numero_referencia,
                    'fecha_pago' => $p->fecha_pago?->format('Y-m-d'),
                    'registrado_por' => $p->usuario?->name,
                    'observaciones' => $p->observaciones,
                    'comprobante_url' => $p->ruta_comprobante
                        ? route('mis-ventas.credito.pagos.comprobante', [$venta->id_venta, $p->id_pago_credito])
                        : null,
                ])->values(),
            ];
        }

        $tasasInteres = $esquemaCreditoNormalizado
            ? TasaInteres::query()
                ->where('activo', true)
                ->orderBy('id_tasa_interes')
                ->get()
                ->map(static fn (TasaInteres $t): array => [
                    'id_tasa_interes' => $t->id_tasa_interes,
                    'nombre' => $t->nombre,
                    'descripcion' => $t->descripcion,
                    'tasa_anual' => (float) $t->tasa_anual,
                    'tasa_nominal_anual' => (float) ($t->tasa_nominal_anual ?? $t->tasa_anual),
                    'tasa_efectiva_anual' => (float) ($t->tasa_efectiva_anual ?? 0),
                    'tasa_mensual' => (float) $t->tasa_mensual,
                    'tasa_mensual_desde_mes_13' => (float) ($t->tasa_mensual_desde_mes_13 ?? self::TASA_MENSUAL_MES_13_PCT),
                    'tasa_mora' => (float) $t->tasa_mora,
                    'plazo_minimo_meses' => $t->plazo_minimo_meses,
                    'plazo_maximo_meses' => $t->plazo_maximo_meses,
                ])
            : collect();

        $estrategiaConstancia = $this->constanciaVentaGeneratorService->resolverEstrategia($venta);
        $vendedorAnterior = $this->constanciaVentaGeneratorService->resolverDatosClienteVendedor($venta);

        return Inertia::render('MisVentas/Show', [
            'tasasInteres' => $tasasInteres,
            'venta' => [
                'id_venta' => $venta->id_venta,
                'id_cliente' => $venta->id_cliente,
                'cliente_nombre' => $venta->cliente
                    ? $venta->cliente->nombre_completo
                    : ($venta->datos_cedula_comprador['nombre_completo'] ?? null),
                'cliente_cedula' => $venta->cliente?->cedula ?? ($venta->datos_cedula_comprador['cedula'] ?? null),
                'cliente_correo' => $venta->cliente?->correo ?? ($venta->datos_cedula_comprador['correo'] ?? null),
                'cliente_direccion' => $venta->cliente?->direccion ?? ($venta->datos_cedula_comprador['direccion'] ?? null),
                'cliente_telefono' => $venta->cliente?->telefono ?? ($venta->datos_cedula_comprador['telefono'] ?? null),
                'vendedor_anterior' => $vendedorAnterior,
                'constancia_estrategia' => $estrategiaConstancia,
                'placa' => $venta->id_vehiculo,
                'tipo_operacion' => $venta->tipo_operacion ?? 'venta',
                'estado' => $venta->estado,
                'precio_base' => $precioLista,
                'precio_lista' => $precioLista,
                'precio_final' => $precioFinal,
                'precio_minimo' => $precioMinimo,
                'precio_acordado' => $precioAcordado,
                'credito' => $creditoPayload,
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
                    'id_tipo_contrato' => $vehiculo?->id_tipo_contrato ?? $vehiculo?->documentos?->id_tipo_contrato,
                    'tipo_contrato_nombre' => $estrategiaConstancia['tipo_contrato_nombre'],
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
            $tieneCedulaGuardada = is_string($venta->ruta_cedula_comprador)
                && trim($venta->ruta_cedula_comprador) !== ''
                && Storage::disk('local')->exists($venta->ruta_cedula_comprador);

            if (! $tieneCedulaGuardada) {
                throw ValidationException::withMessages([
                    'forma_pago' => 'Debes subir primero la cédula del comprador antes de generar un crédito.',
                ]);
            }

            $reglas['modalidad_credito'] = ['required', Rule::in(['abierto', 'mayor_6_meses'])];
            $reglas['id_tasa_interes'] = ['nullable', 'integer', 'exists:tasas_interes,id_tasa_interes'];
            $reglas['monto_inicial'] = ['nullable', 'numeric', 'min:0', 'max:999999999999.99'];
            $reglas['metodo_pago_inicial'] = ['nullable', Rule::in(['efectivo', 'transferencia', 'cheque', 'deposito'])];
            $reglas['comprobante_inicial'] = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'];
            $reglas['observaciones'] = ['nullable', 'string', 'max:1000'];

            if ($modalidadCredito === 'mayor_6_meses') {
                $reglas['cuota_mensual'] = ['nullable', 'numeric', 'gt:0', 'max:999999999999.99'];
                $reglas['meses_credito'] = ['required', 'integer', 'min:4', 'max:120'];
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
            if ($request->has('pagos_contado')) {
                $reglas['pagos_contado'] = ['required', 'array', 'min:1', 'max:20'];
                $reglas['pagos_contado.*.comprobante'] = ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'];
                $reglas['pagos_contado.*.monto'] = ['required', 'numeric', 'gt:0'];
                $reglas['pagos_contado.*.fecha_pago'] = ['nullable', 'date'];
                $reglas['pagos_contado.*.metodo_pago'] = ['nullable', Rule::in(['efectivo', 'transferencia', 'cheque', 'deposito'])];
                $reglas['pagos_contado.*.numero_referencia'] = ['nullable', 'string', 'max:100'];
            } else {
                $reglas['documento_comprobante'] = ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'];
                $reglas['fecha_pago'] = ['nullable', 'date'];
            }
        }

        $validated = $request->validate($reglas, [
            'meses_credito.min' => 'El plazo para «Crédito > 3 meses» debe ser mayor a 3 meses (mínimo 4 meses).',
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

        $montoInicialCredito = 0.0;
        $montoFinanciadoCredito = 0.0;
        $idTasaInteresCredito = null;

        if ($formaPago === 'credito') {
            $montoInicialCredito = round((float) ($validated['monto_inicial'] ?? 0), 2);
            if ($precioObjetivo > 0 && $montoInicialCredito >= $precioObjetivo - 0.009) {
                throw ValidationException::withMessages([
                    'monto_inicial' => 'La entrada / monto inicial debe ser menor al valor total de la venta.',
                ]);
            }

            $montoFinanciadoCredito = max(0.0, round($precioObjetivo - $montoInicialCredito, 2));
            $idTasaInteresCredito = isset($validated['id_tasa_interes']) && $validated['id_tasa_interes'] !== ''
                ? (int) $validated['id_tasa_interes']
                : null;

            $tasaSeleccionada = null;
            if (Schema::hasTable('tasas_interes')) {
                $tasaSeleccionada = $idTasaInteresCredito !== null
                    ? TasaInteres::query()->find($idTasaInteresCredito)
                    : TasaInteres::query()->where('activo', true)->orderBy('id_tasa_interes')->first();

                if ($tasaSeleccionada !== null && $idTasaInteresCredito === null) {
                    $idTasaInteresCredito = $tasaSeleccionada->id_tasa_interes;
                }
            }

            $tasaBasePct = $tasaSeleccionada !== null && (float) $tasaSeleccionada->tasa_mensual > 0
                ? (float) $tasaSeleccionada->tasa_mensual
                : self::TASA_MENSUAL_BASE_PCT;
            $tasaMes13Pct = $tasaSeleccionada !== null && $tasaSeleccionada->tasa_mensual_desde_mes_13 !== null
                ? (float) $tasaSeleccionada->tasa_mensual_desde_mes_13
                : self::TASA_MENSUAL_MES_13_PCT;

            if ($montoInicialCredito > 0 && $request->hasFile('comprobante_inicial')) {
                $rutaInicial = $request->file('comprobante_inicial')->store("ventas/{$venta->id_venta}/pagos", 'local');
                if (! is_string($rutaInicial)) {
                    throw ValidationException::withMessages([
                        'comprobante_inicial' => 'No se pudo almacenar el comprobante de entrada en el disco privado.',
                    ]);
                }
                $rutasSubidas[] = $rutaInicial;
                $pagosPreparados[] = [
                    'ruta_comprobante' => $rutaInicial,
                    'monto' => $montoInicialCredito,
                    'tipo_pago' => 'entrada',
                    'metodo_caja' => $validated['metodo_pago_inicial'] ?? 'transferencia',
                    'fecha_pago' => now()->toDateString(),
                ];
            }

            $datosCredito['modalidad_credito'] = $validated['modalidad_credito'];
            $datosCredito['observaciones_credito'] = trim((string) ($validated['observaciones'] ?? '')) ?: null;

            if ($validated['modalidad_credito'] === 'mayor_6_meses') {
                $meses = (int) $validated['meses_credito'];
                $fechaBase = Carbon::parse($validated['fecha_siguiente_pago']);
                $capitalBase = round($montoFinanciadoCredito / $meses, 2);
                $plan = [];

                for ($i = 0; $i < $meses; $i++) {
                    $numeroCuota = $i + 1;
                    $fechaCuota = $fechaBase->copy()->addMonthsNoOverflow($i)->toDateString();
                    $capitalCuota = ($i === $meses - 1)
                        ? max(0.0, round($montoFinanciadoCredito - ($capitalBase * ($meses - 1)), 2))
                        : $capitalBase;
                    $tasaAplicada = $numeroCuota <= 12 ? $tasaBasePct : $tasaMes13Pct;
                    $interesCuota = round($montoFinanciadoCredito * ($tasaAplicada / 100), 2);
                    $valorCuota = round($capitalCuota + $interesCuota, 2);

                    $plan[] = [
                        'numero' => $numeroCuota,
                        'monto' => $valorCuota,
                        'monto_capital' => $capitalCuota,
                        'monto_interes' => $interesCuota,
                        'tasa_aplicada' => $tasaAplicada,
                        'fecha_vencimiento' => $fechaCuota,
                    ];
                }

                $datosCredito['cuota_mensual_credito'] = $plan[0]['monto'] ?? 0.0;
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

                if ($montoFinanciadoCredito > 0 && abs($sumaCredito - $montoFinanciadoCredito) > 0.009) {
                    throw ValidationException::withMessages([
                        'cuotas_credito' => sprintf(
                            'La suma de las cuotas del crédito ($%s) debe coincidir exactamente con el saldo a financiar ($%s).',
                            number_format($sumaCredito, 2, '.', ','),
                            number_format($montoFinanciadoCredito, 2, '.', ','),
                        ),
                    ]);
                }

                $fechasOrdenadas = array_column($plan, 'fecha_vencimiento');
                sort($fechasOrdenadas);

                $datosCredito['meses_credito'] = count($plan);
                $datosCredito['fecha_siguiente_pago_credito'] = $fechasOrdenadas[0] ?? null;
                $datosCredito['fecha_vencimiento_credito'] = $fechasOrdenadas[count($fechasOrdenadas) - 1] ?? null;
                $datosCredito['plan_cuotas_credito'] = $plan;
                $datosCredito['requiere_aprobacion_credito'] = true;
                $datosCredito['estado_aprobacion_credito'] = 'pendiente';
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
            if (isset($validated['pagos_contado']) && is_array($validated['pagos_contado'])) {
                $sumaContado = 0.0;
                foreach ($validated['pagos_contado'] as $item) {
                    $sumaContado = round($sumaContado + round((float) $item['monto'], 2), 2);
                }

                if ($precioObjetivo > 0 && $sumaContado > $precioObjetivo + 0.009) {
                    throw ValidationException::withMessages([
                        'pagos_contado' => sprintf(
                            'La suma de los comprobantes de contado ($%s) no puede superar el precio acordado ($%s).',
                            number_format($sumaContado, 2, '.', ','),
                            number_format($precioObjetivo, 2, '.', ','),
                        ),
                    ]);
                }

                foreach ($request->file('pagos_contado', []) as $idx => $archivosItem) {
                    $archivoContado = $archivosItem['comprobante'] ?? null;
                    $ruta = $archivoContado?->store("ventas/{$venta->id_venta}/pagos", 'local');
                    if (! is_string($ruta)) {
                        Storage::disk('local')->delete($rutasSubidas);
                        throw ValidationException::withMessages([
                            'pagos_contado' => 'No se pudo almacenar uno de los comprobantes de contado en el disco privado.',
                        ]);
                    }
                    $rutasSubidas[] = $ruta;
                    $pagosPreparados[] = [
                        'ruta_comprobante' => $ruta,
                        'monto' => round((float) $validated['pagos_contado'][$idx]['monto'], 2),
                        'tipo_pago' => 'contado',
                        'metodo_caja' => $validated['pagos_contado'][$idx]['metodo_pago'] ?? 'transferencia',
                        'numero_referencia' => trim((string) ($validated['pagos_contado'][$idx]['numero_referencia'] ?? '')) ?: null,
                        'fecha_pago' => $validated['pagos_contado'][$idx]['fecha_pago'] ?? now()->toDateString(),
                    ];
                }
            } else {
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
                    'metodo_caja' => 'contado',
                    'fecha_pago' => $validated['fecha_pago'] ?? now()->toDateString(),
                ];
            }
        }

        try {
            DB::transaction(function () use (
                $request,
                $venta,
                $formaPago,
                $pagosPreparados,
                $precioObjetivo,
                $datosCredito,
                $montoInicialCredito,
                $montoFinanciadoCredito,
                $idTasaInteresCredito,
                &$rutasSubidas,
            ): void {
                $ventaBloqueada = Venta::query()
                    ->whereKey($venta->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $metodoPago = MetodoPago::firstOrCreate([
                    'nombre' => ucfirst($formaPago),
                ]);

                $tipoTransaccionIngreso = TipoTransaccion::firstOrCreate(['nombre' => 'Ingreso por Venta de Vehículo']);

                $pagosConCaja = [];

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

                    $transaccionCaja = null;
                    if ($ventaBloqueada->id_cliente !== null || Schema::hasTable('transacciones_cajas')) {
                        $metodoCaja = MetodoPago::firstOrCreate([
                            'nombre' => ucfirst((string) ($pagoData['metodo_caja'] ?? $formaPago)),
                        ]);
                        $transaccionCaja = $this->registrarMovimientoCajaSeguro(
                            $ventaBloqueada,
                            $tipoTransaccionIngreso->id_tipo_transaccion,
                            $metodoCaja->id_metodo_pago,
                            (float) $pagoData['monto'],
                            (string) $pagoData['fecha_pago'],
                            "Pago ({$pagoData['tipo_pago']}) de venta #{$ventaBloqueada->id_venta} · Placa {$ventaBloqueada->id_vehiculo}",
                            $pagoData['ruta_comprobante'],
                            $request->user()->id,
                        );
                    }

                    $pagosConCaja[] = [
                        ...$pagoData,
                        'id_transaccion_caja' => $transaccionCaja?->id_transaccion,
                    ];
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

                if ($formaPago === 'credito' && Schema::hasTable('cuotas_credito') && Schema::hasColumn('creditos', 'id_venta')) {
                    $rutaPagareGenerado = $this->persistirCreditoYTablaAmortizacion(
                        $ventaBloqueada,
                        $datosCredito['plan_cuotas_credito'] ?? [],
                        $precioObjetivo,
                        $montoInicialCredito,
                        $montoFinanciadoCredito,
                        $idTasaInteresCredito,
                        $datosCredito['observaciones_credito'],
                        $request->user()->id,
                    );
                    if ($rutaPagareGenerado !== null) {
                        $rutasSubidas[] = $rutaPagareGenerado;
                    }
                } elseif (
                    in_array($formaPago, ['contado', 'cuotas'], true)
                    && $pagosConCaja !== []
                    && Schema::hasTable('cuotas_credito')
                    && Schema::hasTable('pagos_credito')
                    && Schema::hasColumn('creditos', 'id_venta')
                ) {
                    $this->persistirPagosContadoEnCuotasCredito(
                        $ventaBloqueada,
                        $formaPago,
                        $pagosConCaja,
                        $precioObjetivo,
                        $request->user()->id,
                    );
                }

                $etiquetaModalidadCredito = $datosCredito['modalidad_credito'] === 'mayor_6_meses'
                    ? 'Crédito > 3 meses'
                    : 'Crédito abierto / personalizado';

                $tipoMovimiento = Tipo_movimiento::firstOrCreate([
                    'nombre' => $requiereAprobacionCredito
                        ? "Solicitud de {$etiquetaModalidadCredito}"
                        : ($esReserva ? 'Reserva de Vehículo' : 'Venta de Vehículo'),
                ]);

                if ($requiereAprobacionCredito) {
                    $descripcionMovimiento = $datosCredito['modalidad_credito'] === 'mayor_6_meses'
                        ? sprintf(
                            'Solicitud de Crédito > 3 meses (%d cuotas mensuales · Primera cuota: $%s · Inicio: %s). Pendiente de aprobación.',
                            (int) $datosCredito['meses_credito'],
                            number_format((float) $datosCredito['cuota_mensual_credito'], 2, '.', ','),
                            (string) $datosCredito['fecha_siguiente_pago_credito'],
                        )
                        : sprintf(
                            'Solicitud de Crédito abierto / personalizado (%d cuotas manuales · Total: $%s · Primer vencimiento: %s). Pendiente de aprobación.',
                            (int) $datosCredito['meses_credito'],
                            number_format($precioObjetivo, 2, '.', ','),
                            (string) $datosCredito['fecha_siguiente_pago_credito'],
                        );
                } else {
                    $descripcionMovimiento = sprintf(
                        '%s registrada · Forma de pago: %s · Valor acordado: $%s.',
                        $esReserva ? 'Reserva' : 'Venta',
                        ucfirst($formaPago),
                        number_format($precioObjetivo, 2, '.', ','),
                    );
                }

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
            $etiqueta = $datosCredito['modalidad_credito'] === 'mayor_6_meses'
                ? 'Crédito > 3 meses'
                : 'Crédito abierto / personalizado';

            return back()->with('success', "Plan de {$etiqueta} registrado. Queda en estado «Pendiente de aprobación de crédito» hasta ser autorizado por Administración o Administrador.");
        }

        return back()->with('success', 'Forma de pago registrada correctamente. Ahora puedes generar la constancia.');
    }

    public function decidirCredito(Request $request, Venta $venta): RedirectResponse
    {
        abort_unless(
            $this->puedeAprobarCredito($request->user()),
            403,
            'Solo el área Administrativa o el Administrador puede aprobar o rechazar solicitudes de crédito.',
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

            if (Schema::hasColumn('creditos', 'id_venta')) {
                $creditoVenta = Credito::query()
                    ->where('id_venta', $ventaBloqueada->id_venta)
                    ->latest('id_credito')
                    ->lockForUpdate()
                    ->first();

                if ($creditoVenta !== null) {
                    $creditoVenta->update([
                        'estado' => $esAprobado ? 'activo' : 'cancelado',
                        'user_id' => $request->user()->id,
                    ]);

                    if ($esAprobado) {
                        $this->pagareGeneratorService->regenerarYGuardar($creditoVenta->fresh());
                    }
                }
            }

            $etiquetaModalidad = $ventaBloqueada->modalidad_credito === 'mayor_6_meses'
                ? 'Crédito > 3 meses'
                : 'Crédito abierto / personalizado';

            $tipoMovimiento = Tipo_movimiento::firstOrCreate([
                'nombre' => $esAprobado ? "Aprobación de {$etiquetaModalidad}" : "Rechazo de {$etiquetaModalidad}",
            ]);
            Movimientos_vehiculo::create([
                'id_vehiculo' => $ventaBloqueada->id_vehiculo,
                'id_tipo_movimiento' => $tipoMovimiento->id_tipo_movimiento,
                'id_usuario' => $request->user()->id,
                'descripcion' => sprintf(
                    '%s %s por %s.%s',
                    $etiquetaModalidad,
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
            ? 'Crédito aprobado correctamente. Ya se puede continuar con la constancia.'
            : 'Crédito rechazado. El vendedor podrá configurar una nueva modalidad de pago.';

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

                $fechaPago = $validated['fecha_pago'] ?? now()->toDateString();

                PagoVenta::create([
                    'id_venta' => $ventaBloqueada->id_venta,
                    'id_vehiculo' => $ventaBloqueada->id_vehiculo,
                    'ruta_comprobante' => $ruta,
                    'monto' => $montoAbono,
                    'tipo_pago' => $ventaBloqueada->forma_pago === 'contado' ? 'contado' : 'cuota',
                    'fecha_pago' => $fechaPago,
                    'registrado_por' => $request->user()->id,
                ]);

                $transaccionCaja = null;
                if (Schema::hasTable('transacciones_cajas')) {
                    $tipoTransaccionIngreso = TipoTransaccion::firstOrCreate(['nombre' => 'Ingreso por Venta de Vehículo']);
                    $metodoCaja = MetodoPago::firstOrCreate([
                        'nombre' => ucfirst((string) ($ventaBloqueada->forma_pago ?? 'Contado')),
                    ]);
                    $transaccionCaja = $this->registrarMovimientoCajaSeguro(
                        $ventaBloqueada,
                        $tipoTransaccionIngreso->id_tipo_transaccion,
                        $metodoCaja->id_metodo_pago,
                        $montoAbono,
                        $fechaPago,
                        "Abono adicional de venta #{$ventaBloqueada->id_venta} · Placa {$ventaBloqueada->id_vehiculo}",
                        $ruta,
                        $request->user()->id,
                    );
                }

                if (
                    Schema::hasTable('cuotas_credito')
                    && Schema::hasTable('pagos_credito')
                    && Schema::hasColumn('creditos', 'id_venta')
                ) {
                    $this->agregarAbonoContadoEnCuotasCredito(
                        $ventaBloqueada,
                        $montoAbono,
                        $fechaPago,
                        $ruta,
                        $transaccionCaja?->id_transaccion,
                        $precioObjetivo,
                        $request->user()->id,
                    );
                }
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

        $rutaAnterior = $venta->ruta_cedula_comprador;
        $rutaCedula = $archivo->store('documentos/cedulas', 'local');

        if ($rutaCedula === false) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo almacenar la cédula en el disco privado.',
            ], 500);
        }

        // Guardamos siempre el archivo privado en la venta, incluso si Gemini no responde
        $venta->update([
            'ruta_cedula_comprador' => $rutaCedula,
        ]);

        if ($rutaAnterior !== null && $rutaAnterior !== $rutaCedula && Storage::disk('local')->exists($rutaAnterior)) {
            Storage::disk('local')->delete($rutaAnterior);
        }

        $cedulaUrl = route('mis-ventas.cedula', $venta->id_venta);
        $respuestaSinOcr = static fn (string $motivo): JsonResponse => response()->json([
            'success' => true,
            'cedula_guardada' => true,
            'ocr_fallo' => true,
            'warning' => "{$motivo} El archivo de la cédula sí se almacenó en el disco privado; puedes completar los datos manualmente.",
            'ruta_cedula_comprador' => $rutaCedula,
            'cedula_url' => $cedulaUrl,
        ]);

        $apiKey = config('services.gemini.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            return $respuestaSinOcr('Gemini no está configurado (GEMINI_API_KEY ausente).');
        }

        $base64Data = base64_encode($archivoBytes);
        $prompt = 'Analiza esta cédula de identidad de Ecuador (anverso y/o reverso). '
            .'Extrae únicamente los datos visibles del titular; no inventes ni deduzcas información que no aparezca. '
            .'Si aparece el domicilio o dirección en el reverso (domicilio / parroquia / cantón / provincia), extráelo en "direccion". '
            .'Devuelve únicamente un objeto JSON válido con estas claves exactas: '
            .'{"nombre_completo": "", "cedula": "", "direccion": "", "estado_civil": "", "es_casado": false, "nombre_conyuge": ""}.';

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
            Log::warning('Error al escanear cédula de comprador en MisVentas (archivo conservado en disco privado).', [
                'id_venta' => $venta->id_venta,
                'exception' => $exception->getMessage(),
            ]);

            return $respuestaSinOcr('No se pudo completar el escaneo con Gemini ('.$exception->getMessage().').');
        }

        if (isset($respuestaGemini['error']['message'])) {
            return $respuestaSinOcr('Gemini devolvió un error ('.$respuestaGemini['error']['message'].').');
        }

        $textoJson = $respuestaGemini['candidates'][0]['content']['parts'][0]['text'] ?? null;
        $textoLimpio = is_string($textoJson)
            ? (preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($textoJson)) ?? trim($textoJson))
            : '';
        $datosExtraidos = json_decode($textoLimpio, true);

        if (! is_array($datosExtraidos)) {
            return $respuestaSinOcr('Gemini no devolvió datos legibles de la cédula.');
        }

        $estadoCivil = strtoupper(trim((string) ($datosExtraidos['estado_civil'] ?? '')));
        $esCasado = filter_var($datosExtraidos['es_casado'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || str_contains($estadoCivil, 'CASAD')
            || str_contains($estadoCivil, 'UNION');

        $datosPrevios = is_array($venta->datos_cedula_comprador) ? $venta->datos_cedula_comprador : [];
        $direccionExtraida = trim((string) ($datosExtraidos['direccion'] ?? ''));

        $datosCedula = [
            'nombre_completo' => strtoupper(trim((string) ($datosExtraidos['nombre_completo'] ?? ''))),
            'cedula' => preg_replace('/[^0-9]/', '', (string) ($datosExtraidos['cedula'] ?? '')) ?? '',
            'direccion' => $direccionExtraida !== '' ? $direccionExtraida : (string) ($datosPrevios['direccion'] ?? ''),
            'correo' => (string) ($datosPrevios['correo'] ?? $venta->cliente?->correo ?? ''),
            'telefono' => (string) ($datosPrevios['telefono'] ?? $venta->cliente?->telefono ?? ''),
            'estado_civil' => $estadoCivil,
            'es_casado' => $esCasado,
            'nombre_conyuge' => $esCasado ? strtoupper(trim((string) ($datosExtraidos['nombre_conyuge'] ?? ''))) : '',
            'cedula_conyuge' => (string) ($datosPrevios['cedula_conyuge'] ?? ''),
        ];

        if ($datosCedula['nombre_completo'] === '' && $datosCedula['cedula'] === '') {
            return $respuestaSinOcr('Gemini no pudo extraer el nombre ni el número de cédula.');
        }

        // Sincronización inmediata: clientes -> ventas -> creditos -> regeneración automática de pagaré .docx
        $cliente = $this->sincronizarClienteComprador($venta, $datosCedula);

        $venta->update([
            'id_cliente' => $cliente?->id_cliente ?? $venta->id_cliente,
            'datos_cedula_comprador' => $datosCedula,
        ]);

        $pagareUrl = null;
        if (Schema::hasColumn('creditos', 'id_venta')) {
            $creditoAsociado = Credito::query()
                ->where('id_venta', $venta->id_venta)
                ->latest('id_credito')
                ->first();

            if ($creditoAsociado !== null) {
                if ($cliente !== null && $creditoAsociado->id_cliente !== $cliente->id_cliente) {
                    $creditoAsociado->update(['id_cliente' => $cliente->id_cliente]);
                }

                $esCreditoFinanciado = $venta->forma_pago === 'credito'
                    && ($creditoAsociado->modalidad ?? 'credito') === 'credito'
                    && in_array($creditoAsociado->estado, ['pendiente_aprobacion_credito', 'activo', 'en_mora'], true);

                if ($esCreditoFinanciado) {
                    $rutaPagare = $this->pagareGeneratorService->regenerarYGuardar($creditoAsociado->fresh());
                    if ($rutaPagare !== null) {
                        $pagareUrl = route('mis-ventas.credito.pagare', $venta->id_venta);
                    }
                }
            }
        }

        return response()->json([
            'success' => true,
            'cedula_guardada' => true,
            'ocr_fallo' => false,
            'cliente_sincronizado' => $cliente !== null,
            'ruta_cedula_comprador' => $rutaCedula,
            'datos_cedula_comprador' => $datosCedula,
            'cedula_url' => $cedulaUrl,
            'pagare_url' => $pagareUrl,
        ]);
    }

    public function actualizarDatosComprador(Request $request, Venta $venta): JsonResponse
    {
        abort_unless($this->puedeAccederVenta($request->user(), $venta), 403);

        $validated = $request->validate([
            'nombre_completo' => ['required', 'string', 'max:200'],
            'cedula' => ['required', 'string', 'max:30'],
            'direccion' => ['nullable', 'string', 'max:200'],
            'correo' => ['nullable', 'email', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'estado_civil' => ['nullable', 'string', 'max:60'],
            'es_casado' => ['nullable', 'boolean'],
            'nombre_conyuge' => ['nullable', 'string', 'max:200'],
            'cedula_conyuge' => ['nullable', 'string', 'max:30'],
        ]);

        $previos = is_array($venta->datos_cedula_comprador) ? $venta->datos_cedula_comprador : [];
        $esCasado = array_key_exists('es_casado', $validated)
            ? (bool) $validated['es_casado']
            : (bool) ($previos['es_casado'] ?? false);

        $datosActualizados = [
            'nombre_completo' => mb_strtoupper(trim($validated['nombre_completo']), 'UTF-8'),
            'cedula' => trim($validated['cedula']),
            'direccion' => trim((string) ($validated['direccion'] ?? ($previos['direccion'] ?? ''))),
            'correo' => trim((string) ($validated['correo'] ?? ($previos['correo'] ?? ''))),
            'telefono' => trim((string) ($validated['telefono'] ?? ($previos['telefono'] ?? ''))),
            'estado_civil' => mb_strtoupper(trim((string) ($validated['estado_civil'] ?? ($previos['estado_civil'] ?? ''))), 'UTF-8'),
            'es_casado' => $esCasado,
            'nombre_conyuge' => $esCasado
                ? mb_strtoupper(trim((string) ($validated['nombre_conyuge'] ?? ($previos['nombre_conyuge'] ?? ''))), 'UTF-8')
                : '',
            'cedula_conyuge' => $esCasado
                ? trim((string) ($validated['cedula_conyuge'] ?? ($previos['cedula_conyuge'] ?? '')))
                : '',
        ];

        $cliente = $this->sincronizarClienteComprador($venta, $datosActualizados);
        $venta->update([
            'id_cliente' => $cliente?->id_cliente ?? $venta->id_cliente,
            'datos_cedula_comprador' => $datosActualizados,
        ]);

        $pagareUrl = null;
        if (Schema::hasColumn('creditos', 'id_venta')) {
            $creditoAsociado = Credito::query()
                ->where('id_venta', $venta->id_venta)
                ->latest('id_credito')
                ->first();

            if ($creditoAsociado !== null) {
                if ($cliente !== null && $creditoAsociado->id_cliente !== $cliente->id_cliente) {
                    $creditoAsociado->update(['id_cliente' => $cliente->id_cliente]);
                }

                $esCreditoFinanciado = $venta->forma_pago === 'credito'
                    && ($creditoAsociado->modalidad ?? 'credito') === 'credito'
                    && in_array($creditoAsociado->estado, ['pendiente_aprobacion_credito', 'activo', 'en_mora'], true);

                if ($esCreditoFinanciado) {
                    $rutaPagare = $this->pagareGeneratorService->regenerarYGuardar($creditoAsociado->fresh());
                    if ($rutaPagare !== null) {
                        $pagareUrl = route('mis-ventas.credito.pagare', $venta->id_venta);
                    }
                }
            }
        }

        return response()->json([
            'success' => true,
            'datos_cedula_comprador' => $datosActualizados,
            'pagare_url' => $pagareUrl,
        ]);
    }

    public function generarConstancia(Request $request, Venta $venta): JsonResponse
    {
        abort_unless($this->puedeAccederVenta($request->user(), $venta), 403);
        $this->verificarPrecioAutorizadoParaCierre($venta);

        if ($venta->requiere_aprobacion_credito && $venta->estado_aprobacion_credito !== 'aprobado') {
            throw ValidationException::withMessages([
                'forma_pago' => 'El plan de crédito aún está pendiente de aprobación por Administración o Administrador.',
            ]);
        }

        $estrategia = $this->constanciaVentaGeneratorService->resolverEstrategia($venta);
        $datosVendedorActuales = $this->constanciaVentaGeneratorService->resolverDatosClienteVendedor($venta, $request->all());

        // Prellena valores ya almacenados en cliente / cédula escaneada si el frontend no los envió explícitamente
        $datosPrevios = is_array($venta->datos_cedula_comprador) ? $venta->datos_cedula_comprador : [];
        $venta->loadMissing('cliente');
        $request->merge([
            'nombre_completo' => $request->input('nombre_completo') ?: ($venta->cliente?->nombre_completo ?? $datosPrevios['nombre_completo'] ?? ''),
            'cedula' => $request->input('cedula') ?: ($venta->cliente?->cedula ?? $datosPrevios['cedula'] ?? ''),
            'direccion' => $request->input('direccion') ?: ($venta->cliente?->direccion ?? $datosPrevios['direccion'] ?? ''),
            'telefono' => $request->input('telefono') ?: ($venta->cliente?->telefono ?? $datosPrevios['telefono'] ?? ''),
            'correo' => $request->input('correo') ?: ($venta->cliente?->correo ?? $datosPrevios['correo'] ?? ''),
            'es_casado' => $request->has('es_casado') ? $request->boolean('es_casado') : (bool) ($datosPrevios['es_casado'] ?? false),
            'nombre_cliente_vendedor' => $datosVendedorActuales['nombre_completo'],
            'cedula_cliente_vendedor' => $datosVendedorActuales['cedula'],
        ]);

        $reglas = [
            'nombre_completo' => ['required', 'string', 'max:200'],
            'cedula' => ['required', 'string', 'max:30'],
            'direccion' => ['required', 'string', 'max:200'],
            'telefono' => ['required', 'string', 'max:30'],
            'correo' => ['required', 'email', 'max:120'],
            'estado_civil' => ['nullable', 'string', 'max:60'],
            'es_casado' => ['required', 'boolean'],
            'nombre_conyuge' => ['nullable', 'string', 'max:200'],
            'cedula_conyuge' => ['nullable', 'string', 'max:30'],
            'nombre_cliente_vendedor' => $estrategia['requiere_cliente_vendedor']
                ? ['required', 'string', 'max:200']
                : ['nullable', 'string', 'max:200'],
            'cedula_cliente_vendedor' => $estrategia['requiere_cliente_vendedor']
                ? ['required', 'string', 'max:30']
                : ['nullable', 'string', 'max:30'],
        ];

        $validated = $request->validate($reglas, [
            'direccion.required' => 'Ingresa la dirección domiciliaria del comprador para emitir la constancia.',
            'telefono.required' => 'Ingresa el teléfono del comprador para emitir la constancia.',
            'correo.required' => 'Ingresa el correo electrónico del comprador para emitir la constancia.',
            'nombre_cliente_vendedor.required' => 'Este modelo PODER requiere el nombre completo del propietario anterior que entregó el vehículo.',
            'cedula_cliente_vendedor.required' => 'Este modelo PODER requiere la cédula/RUC del propietario anterior que entregó el vehículo.',
        ]);

        $datosCedulaActualizados = [
            'nombre_completo' => mb_strtoupper(trim($validated['nombre_completo']), 'UTF-8'),
            'cedula' => trim($validated['cedula']),
            'direccion' => trim((string) $validated['direccion']),
            'correo' => trim((string) $validated['correo']),
            'telefono' => trim((string) $validated['telefono']),
            'estado_civil' => mb_strtoupper(trim((string) ($validated['estado_civil'] ?? ($datosPrevios['estado_civil'] ?? ''))), 'UTF-8'),
            'es_casado' => (bool) $validated['es_casado'],
            'nombre_conyuge' => $validated['es_casado']
                ? mb_strtoupper(trim((string) ($validated['nombre_conyuge'] ?? ($datosPrevios['nombre_conyuge'] ?? ''))), 'UTF-8')
                : '',
            'cedula_conyuge' => $validated['es_casado']
                ? trim((string) ($validated['cedula_conyuge'] ?? ($datosPrevios['cedula_conyuge'] ?? '')))
                : '',
        ];

        // Sincroniza comprador en clientes y enlaza a ventas
        $cliente = $this->sincronizarClienteComprador($venta, $datosCedulaActualizados);

        // Si es modelo PODER (o se enviaron datos del vendedor anterior), lo persiste en clientes y vincula al vehículo
        $nombreVendedor = mb_strtoupper(trim((string) ($validated['nombre_cliente_vendedor'] ?? '')), 'UTF-8');
        $cedulaVendedor = preg_replace('/[^0-9A-Za-z]/', '', trim((string) ($validated['cedula_cliente_vendedor'] ?? ''))) ?? '';
        if ($nombreVendedor !== '' && $cedulaVendedor !== '' && $venta->vehiculo !== null) {
            $clienteVendedor = Cliente::sincronizarConRol(
                $cedulaVendedor,
                ['nombre_completo' => $nombreVendedor],
                \App\Models\TipoCliente::VENDEDOR,
            );
            if ($venta->vehiculo->id_cliente_vendedor !== $clienteVendedor->id_cliente) {
                $venta->vehiculo->update(['id_cliente_vendedor' => $clienteVendedor->id_cliente]);
            }
        }

        $payloadGenerador = [
            ...$datosCedulaActualizados,
            'nombre_cliente_vendedor' => $nombreVendedor,
            'cedula_cliente_vendedor' => $cedulaVendedor,
        ];

        try {
            $rutaRelativa = $this->constanciaVentaGeneratorService->generar($venta->fresh(), $payloadGenerador);
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
            'id_cliente' => $cliente?->id_cliente ?? $venta->id_cliente,
            'datos_cedula_comprador' => $datosCedulaActualizados,
            'ruta_constancia_generada' => $rutaRelativa,
            'estado' => 'aprobada',
        ]);

        if ($cliente !== null && Schema::hasColumn('creditos', 'id_venta')) {
            $creditoAsociado = Credito::query()
                ->where('id_venta', $venta->id_venta)
                ->latest('id_credito')
                ->first();

            if ($creditoAsociado !== null) {
                $creditoAsociado->update(['id_cliente' => $cliente->id_cliente]);

                $esCreditoFinanciado = $venta->forma_pago === 'credito'
                    && ($creditoAsociado->modalidad ?? 'credito') === 'credito'
                    && in_array($creditoAsociado->estado, ['pendiente_aprobacion_credito', 'activo', 'en_mora'], true);

                if ($esCreditoFinanciado) {
                    $this->pagareGeneratorService->regenerarYGuardar($creditoAsociado->fresh());
                }
            }
        }

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

    public function registrarPagoCredito(Request $request, Venta $venta): RedirectResponse
    {
        abort_unless($this->puedeAccederVenta($request->user(), $venta), 403);

        $validated = $request->validate([
            'id_cuota_credito' => ['nullable', 'integer', 'exists:cuotas_credito,id_cuota_credito'],
            'monto_pagado' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'metodo_pago' => ['required', Rule::in(['efectivo', 'transferencia', 'cheque', 'deposito'])],
            'numero_referencia' => ['nullable', 'string', 'max:100'],
            'fecha_pago' => ['required', 'date'],
            'comprobante' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ]);

        $credito = Credito::query()
            ->where('id_venta', $venta->id_venta)
            ->latest('id_credito')
            ->firstOrFail();

        if (! in_array($credito->estado, ['activo', 'en_mora'], true)) {
            throw ValidationException::withMessages([
                'monto_pagado' => 'El crédito debe estar aprobado y activo antes de registrar abonos o cobranzas.',
            ]);
        }

        // Almacenamiento privado obligatorio en disco 'local'
        $rutaComprobante = $request->file('comprobante')->store(
            "ventas/{$venta->id_venta}/creditos/{$credito->id_credito}/pagos",
            'local',
        );

        if (! is_string($rutaComprobante)) {
            throw ValidationException::withMessages([
                'comprobante' => 'No se pudo almacenar el comprobante de pago en el disco privado.',
            ]);
        }

        try {
            DB::transaction(function () use ($request, $venta, $credito, $validated, $rutaComprobante): void {
                $creditoBloqueado = Credito::query()
                    ->whereKey($credito->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $montoAbono = round((float) $validated['monto_pagado'], 2);
                $saldoActual = round((float) $creditoBloqueado->saldo_pendiente, 2);

                if ($montoAbono > $saldoActual + 0.01) {
                    throw ValidationException::withMessages([
                        'monto_pagado' => sprintf(
                            'El monto ingresado ($%s) supera el saldo pendiente del crédito ($%s).',
                            number_format($montoAbono, 2, '.', ','),
                            number_format($saldoActual, 2, '.', ','),
                        ),
                    ]);
                }

                $cuotasPendientes = CuotaCredito::query()
                    ->where('id_credito', $creditoBloqueado->id_credito)
                    ->where('estado', '!=', 'pagada')
                    ->orderBy('numero_cuota')
                    ->lockForUpdate()
                    ->get();

                $idCuotaObjetivo = isset($validated['id_cuota_credito']) && $validated['id_cuota_credito'] !== ''
                    ? (int) $validated['id_cuota_credito']
                    : ($cuotasPendientes->first()?->id_cuota_credito);

                if ($idCuotaObjetivo !== null && ! $cuotasPendientes->contains('id_cuota_credito', $idCuotaObjetivo)) {
                    throw ValidationException::withMessages([
                        'id_cuota_credito' => 'La cuota seleccionada no pertenece a este crédito o ya se encuentra pagada.',
                    ]);
                }

                // 1. Asiento contable en transacciones_cajas (ingreso real en Caja)
                $tipoTransaccion = TipoTransaccion::firstOrCreate(['nombre' => 'Cobro de Cuota de Crédito']);
                $metodoPagoCat = MetodoPago::firstOrCreate(['nombre' => ucfirst($validated['metodo_pago'])]);

                $transaccionCaja = $this->registrarMovimientoCajaSeguro(
                    $venta,
                    $tipoTransaccion->id_tipo_transaccion,
                    $metodoPagoCat->id_metodo_pago,
                    $montoAbono,
                    $validated['fecha_pago'],
                    sprintf(
                        'Cobro de crédito #%d · Venta #%d · Placa %s%s',
                        $creditoBloqueado->id_credito,
                        $venta->id_venta,
                        $venta->id_vehiculo,
                        ! empty($validated['numero_referencia']) ? " · Ref: {$validated['numero_referencia']}" : '',
                    ),
                    $rutaComprobante,
                    $request->user()->id,
                );

                // 2. Registro del abono en pagos_credito vinculado a Caja
                PagoCredito::create([
                    'id_credito' => $creditoBloqueado->id_credito,
                    'id_cuota_credito' => $idCuotaObjetivo,
                    'id_transaccion_caja' => $transaccionCaja?->id_transaccion,
                    'user_id' => $request->user()->id,
                    'monto_pagado' => $montoAbono,
                    'metodo_pago' => $validated['metodo_pago'],
                    'numero_referencia' => trim((string) ($validated['numero_referencia'] ?? '')) ?: null,
                    'fecha_pago' => $validated['fecha_pago'],
                    'ruta_comprobante' => $rutaComprobante,
                    'observaciones' => trim((string) ($validated['observaciones'] ?? '')) ?: null,
                ]);

                // 3. Aplicación del abono sobre la cuota seleccionada y cascada sobre siguientes pendientes
                $restanteAplicar = $montoAbono;
                $ordenadas = $cuotasPendientes->sortBy(static fn (CuotaCredito $c): int => (
                    $c->id_cuota_credito === $idCuotaObjetivo ? -1 : $c->numero_cuota
                ));

                foreach ($ordenadas as $cuota) {
                    if ($restanteAplicar <= 0.009) {
                        break;
                    }

                    $totalExigibleCuota = round((float) $cuota->valor_cuota + (float) $cuota->monto_mora, 2);
                    $faltanteCuota = max(0.0, round($totalExigibleCuota - (float) $cuota->monto_pagado, 2));

                    if ($faltanteCuota <= 0.009) {
                        continue;
                    }

                    $aplicado = min($restanteAplicar, $faltanteCuota);
                    $nuevoPagadoCuota = round((float) $cuota->monto_pagado + $aplicado, 2);
                    $quedaSaldada = $nuevoPagadoCuota >= $totalExigibleCuota - 0.009;

                    $cuota->update([
                        'monto_pagado' => $nuevoPagadoCuota,
                        'fecha_pago_real' => $validated['fecha_pago'],
                        'estado' => $quedaSaldada ? 'pagada' : 'parcial',
                    ]);

                    $restanteAplicar = round($restanteAplicar - $aplicado, 2);
                }

                // 4. Recalcular saldo pendiente y estado de la cabecera del crédito
                $todasCuotas = CuotaCredito::query()
                    ->where('id_credito', $creditoBloqueado->id_credito)
                    ->get();

                $nuevoSaldo = max(
                    0.0,
                    round(
                        (float) $todasCuotas->sum(static fn (CuotaCredito $c): float => max(
                            0.0,
                            ((float) $c->valor_cuota + (float) $c->monto_mora) - (float) $c->monto_pagado,
                        )),
                        2,
                    ),
                );

                $hoy = now()->startOfDay();
                $tieneMoraActiva = $nuevoSaldo > 0.009 && $todasCuotas->contains(
                    static fn (CuotaCredito $c): bool => $c->estado !== 'pagada'
                        && $c->fecha_vencimiento !== null
                        && $c->fecha_vencimiento->lt($hoy),
                );

                $creditoBloqueado->update([
                    'saldo_pendiente' => $nuevoSaldo,
                    'estado' => $nuevoSaldo <= 0.009
                        ? 'cancelado'
                        : ($tieneMoraActiva ? 'en_mora' : 'activo'),
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($rutaComprobante);
            throw $exception;
        }

        return back()->with('success', 'Pago de cuota registrado en Cobranzas y asentado automáticamente en Caja.');
    }

    public function descargarPagareCredito(Request $request, Venta $venta): StreamedResponse
    {
        abort_unless($this->puedeAccederVenta($request->user(), $venta), 403);

        $credito = Credito::query()
            ->where('id_venta', $venta->id_venta)
            ->latest('id_credito')
            ->firstOrFail();

        abort_unless(
            is_string($credito->ruta_pagare)
                && $credito->ruta_pagare !== ''
                && Storage::disk('local')->exists($credito->ruta_pagare),
            404,
        );

        if ($request->boolean('inline')) {
            return Storage::disk('local')->response($credito->ruta_pagare, null, [
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $extension = pathinfo($credito->ruta_pagare, PATHINFO_EXTENSION) ?: 'docx';

        return Storage::disk('local')->download(
            $credito->ruta_pagare,
            "pagare-amortizacion-{$venta->id_vehiculo}-{$credito->id_credito}.{$extension}",
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function descargarComprobantePagoCredito(Request $request, Venta $venta, PagoCredito $pagoCredito): StreamedResponse
    {
        abort_unless($this->puedeAccederVenta($request->user(), $venta), 403);

        $credito = Credito::query()
            ->where('id_venta', $venta->id_venta)
            ->whereKey($pagoCredito->id_credito)
            ->firstOrFail();

        abort_unless(
            is_string($pagoCredito->ruta_comprobante)
                && $pagoCredito->ruta_comprobante !== ''
                && Storage::disk('local')->exists($pagoCredito->ruta_comprobante),
            404,
        );

        if ($request->boolean('inline')) {
            return Storage::disk('local')->response($pagoCredito->ruta_comprobante, null, [
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $extension = pathinfo($pagoCredito->ruta_comprobante, PATHINFO_EXTENSION);
        $nombre = $extension !== ''
            ? "comprobante-credito-{$venta->id_vehiculo}-{$credito->id_credito}-{$pagoCredito->id_pago_credito}.{$extension}"
            : "comprobante-credito-{$venta->id_vehiculo}-{$credito->id_credito}-{$pagoCredito->id_pago_credito}";

        return Storage::disk('local')->download(
            $pagoCredito->ruta_comprobante,
            $nombre,
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
        return $usuario->hasAnyRole(['Administrador', 'Administracion', 'Administración', 'Administrativo']);
    }

    private function puedeAprobarCredito(User $usuario): bool
    {
        return $usuario->hasAnyRole(['Administrador', 'Administracion', 'Administración', 'Administrativo']);
    }

    private function puedeAccederVenta(User $usuario, Venta $venta): bool
    {
        if (! $this->puedeUsarModuloVentas($usuario)) {
            return false;
        }

        return $venta->id_user === $usuario->id || $this->puedeAprobarDescuento($usuario);
    }

    /**
     * Crea o reemplaza la cabecera en `creditos`, genera las filas de `cuotas_credito`
     * y almacena el pagaré con la tabla de amortización en el disco privado `local`.
     *
     * @param  list<array{numero: int, monto: float, fecha_vencimiento: string}>  $planCuotas
     */
    private function persistirCreditoYTablaAmortizacion(
        Venta $venta,
        array $planCuotas,
        float $montoTotalVenta,
        float $montoInicial,
        float $montoFinanciado,
        ?int $idTasaInteres,
        ?string $observaciones,
        int $userId,
    ): ?string {
        // Si existía un crédito previo (ej. rechazado y reconfigurado), limpiamos sus cuotas previas
        $previos = Credito::query()->where('id_venta', $venta->id_venta)->get();
        foreach ($previos as $previo) {
            if (is_string($previo->ruta_pagare) && $previo->ruta_pagare !== '' && Storage::disk('local')->exists($previo->ruta_pagare)) {
                Storage::disk('local')->delete($previo->ruta_pagare);
            }
            $previo->delete();
        }

        $numeroCuotas = max(1, count($planCuotas));
        $totalPlan = round(array_reduce(
            $planCuotas,
            static fn (float $acc, array $item): float => $acc + (float) $item['monto'],
            0.0,
        ), 2);

        $fechas = array_column($planCuotas, 'fecha_vencimiento');
        sort($fechas);
        $fechaInicio = now()->toDateString();
        $fechaFinal = $fechas !== [] ? $fechas[count($fechas) - 1] : now()->toDateString();

        $clienteId = $venta->id_cliente;
        if ($clienteId === null && is_array($venta->datos_cedula_comprador)) {
            $clienteSincronizado = $this->sincronizarClienteComprador($venta, $venta->datos_cedula_comprador);
            if ($clienteSincronizado !== null) {
                $clienteId = $clienteSincronizado->id_cliente;
                $venta->update(['id_cliente' => $clienteId]);
            }
        }

        $datosCabeceraCredito = [
            'id_cliente' => $clienteId,
            'id_venta' => $venta->id_venta,
            'id_vehiculo' => $venta->id_vehiculo,
            'id_tasa_interes' => $idTasaInteres,
            'user_id' => $userId,
            'registrado_por' => $userId,
            'monto_total' => $montoTotalVenta,
            'monto_inicial' => $montoInicial,
            'monto_financiado' => $montoFinanciado,
            'saldo_pendiente' => $totalPlan,
            'numero_cuotas' => $numeroCuotas,
            'fecha_inicio' => $fechaInicio,
            'fecha_vencimiento' => $fechaFinal,
            'fecha_vencimiento_final' => $fechaFinal,
            'estado' => 'pendiente_aprobacion_credito',
            'observaciones' => $observaciones,
        ];
        if (Schema::hasColumn('creditos', 'modalidad')) {
            $datosCabeceraCredito['modalidad'] = 'credito';
        }

        $credito = Credito::create($datosCabeceraCredito);

        // Desglose normalizado de capital e interés para cuotas_credito
        $interesTotal = max(0.0, round($totalPlan - $montoFinanciado, 2));
        $capitalRestante = min($montoFinanciado, $totalPlan);
        $interesRestante = $interesTotal;
        $tieneIdVentaEnCuotas = Schema::hasColumn('cuotas_credito', 'id_venta');

        foreach (array_values($planCuotas) as $idx => $item) {
            $esUltima = ($idx === $numeroCuotas - 1);
            $valorCuota = round((float) $item['monto'], 2);

            if (isset($item['monto_capital'], $item['monto_interes'])) {
                $montoCapital = round((float) $item['monto_capital'], 2);
                $montoInteres = round((float) $item['monto_interes'], 2);
            } elseif ($interesTotal <= 0.009) {
                $montoInteres = 0.0;
                $montoCapital = $valorCuota;
            } elseif ($esUltima) {
                $montoInteres = max(0.0, round($interesRestante, 2));
                $montoCapital = max(0.0, round($valorCuota - $montoInteres, 2));
            } else {
                $proporcion = $totalPlan > 0 ? ($valorCuota / $totalPlan) : (1 / $numeroCuotas);
                $montoInteres = min($valorCuota, round($interesTotal * $proporcion, 2));
                $montoCapital = max(0.0, round($valorCuota - $montoInteres, 2));
                $interesRestante = round($interesRestante - $montoInteres, 2);
                $capitalRestante = round($capitalRestante - $montoCapital, 2);
            }

            $datosCuota = [
                'id_credito' => $credito->id_credito,
                'numero_cuota' => (int) ($item['numero'] ?? ($idx + 1)),
                'fecha_vencimiento' => $item['fecha_vencimiento'],
                'monto_capital' => $montoCapital,
                'monto_interes' => $montoInteres,
                'monto_mora' => 0.0,
                'valor_cuota' => $valorCuota,
                'monto_pagado' => 0.0,
                'fecha_pago_real' => null,
                'estado' => 'pendiente',
            ];
            if ($tieneIdVentaEnCuotas) {
                $datosCuota['id_venta'] = $venta->id_venta;
            }

            CuotaCredito::create($datosCuota);
        }

        return $this->pagareGeneratorService->regenerarYGuardar($credito->fresh(['cuotas', 'tasaInteres', 'cliente', 'venta']));
    }

    /**
     * Unifica los pagos de ventas de contado o cuotas/abonos dentro de `creditos`, `cuotas_credito` y `pagos_credito`
     * sin disparar aprobación de crédito ni generación de Pagaré.
     *
     * @param  list<array{ruta_comprobante: string, monto: float, tipo_pago: string, fecha_pago: string, metodo_caja?: string, id_transaccion_caja?: int|null}>  $pagosConCaja
     */
    private function persistirPagosContadoEnCuotasCredito(
        Venta $venta,
        string $modalidad,
        array $pagosConCaja,
        float $montoTotalVenta,
        int $userId,
    ): void {
        $previos = Credito::query()->where('id_venta', $venta->id_venta)->get();
        foreach ($previos as $previo) {
            if (is_string($previo->ruta_pagare) && $previo->ruta_pagare !== '' && Storage::disk('local')->exists($previo->ruta_pagare)) {
                Storage::disk('local')->delete($previo->ruta_pagare);
            }
            $previo->delete();
        }

        $totalPagado = round(array_reduce(
            $pagosConCaja,
            static fn (float $acc, array $item): float => $acc + (float) $item['monto'],
            0.0,
        ), 2);
        $saldoPendiente = max(0.0, round($montoTotalVenta - $totalPagado, 2));

        $fechas = array_column($pagosConCaja, 'fecha_pago');
        sort($fechas);
        $fechaInicio = $fechas[0] ?? now()->toDateString();
        $fechaFinal = $fechas !== [] ? $fechas[count($fechas) - 1] : now()->toDateString();

        $clienteId = $venta->id_cliente;
        if ($clienteId === null && is_array($venta->datos_cedula_comprador)) {
            $clienteSincronizado = $this->sincronizarClienteComprador($venta, $venta->datos_cedula_comprador);
            if ($clienteSincronizado !== null) {
                $clienteId = $clienteSincronizado->id_cliente;
                $venta->update(['id_cliente' => $clienteId]);
            }
        }

        $datosCabecera = [
            'id_cliente' => $clienteId,
            'id_venta' => $venta->id_venta,
            'id_vehiculo' => $venta->id_vehiculo,
            'id_tasa_interes' => null,
            'user_id' => $userId,
            'registrado_por' => $userId,
            'monto_total' => $montoTotalVenta,
            'monto_inicial' => $totalPagado,
            'monto_financiado' => 0.0,
            'saldo_pendiente' => $saldoPendiente,
            'numero_cuotas' => count($pagosConCaja),
            'fecha_inicio' => $fechaInicio,
            'fecha_vencimiento' => $fechaFinal,
            'fecha_vencimiento_final' => $fechaFinal,
            'ruta_pagare' => null,
            'estado' => $saldoPendiente <= 0.009 ? 'pagado' : 'activo',
            'observaciones' => $modalidad === 'contado'
                ? 'Registro unificado de pago de contado en cuotas_credito.'
                : 'Registro unificado de pagos/abonos múltiples en cuotas_credito.',
        ];
        if (Schema::hasColumn('creditos', 'modalidad')) {
            $datosCabecera['modalidad'] = $modalidad;
        }

        $cabecera = Credito::create($datosCabecera);
        $tieneIdVentaEnCuotas = Schema::hasColumn('cuotas_credito', 'id_venta');

        foreach (array_values($pagosConCaja) as $idx => $item) {
            $monto = round((float) $item['monto'], 2);
            $fechaPago = (string) ($item['fecha_pago'] ?? now()->toDateString());

            $datosCuota = [
                'id_credito' => $cabecera->id_credito,
                'numero_cuota' => $idx + 1,
                'fecha_vencimiento' => $fechaPago,
                'monto_capital' => $monto,
                'monto_interes' => 0.0,
                'monto_mora' => 0.0,
                'valor_cuota' => $monto,
                'monto_pagado' => $monto,
                'fecha_pago_real' => $fechaPago,
                'estado' => 'pagada',
            ];
            if ($tieneIdVentaEnCuotas) {
                $datosCuota['id_venta'] = $venta->id_venta;
            }

            $cuota = CuotaCredito::create($datosCuota);

            PagoCredito::create([
                'id_credito' => $cabecera->id_credito,
                'id_cuota_credito' => $cuota->id_cuota_credito,
                'id_transaccion_caja' => $item['id_transaccion_caja'] ?? null,
                'user_id' => $userId,
                'monto_pagado' => $monto,
                'metodo_pago' => (string) ($item['metodo_caja'] ?? $modalidad),
                'numero_referencia' => $item['numero_referencia'] ?? null,
                'fecha_pago' => $fechaPago,
                'ruta_comprobante' => $item['ruta_comprobante'],
                'observaciones' => sprintf('Pago (%s) de venta #%d · Placa %s', $item['tipo_pago'], $venta->id_venta, $venta->id_vehiculo),
            ]);
        }
    }

    /**
     * Registra un abono adicional de venta no financiada (contado/cuotas) dentro de `cuotas_credito` y `pagos_credito`.
     */
    private function agregarAbonoContadoEnCuotasCredito(
        Venta $venta,
        float $montoAbono,
        string $fechaPago,
        string $rutaComprobante,
        ?int $idTransaccionCaja,
        float $precioObjetivo,
        int $userId,
    ): void {
        $cabecera = Credito::query()
            ->where('id_venta', $venta->id_venta)
            ->latest('id_credito')
            ->lockForUpdate()
            ->first();

        $modalidad = in_array($venta->forma_pago, ['contado', 'cuotas'], true)
            ? (string) $venta->forma_pago
            : 'contado';

        if ($cabecera === null) {
            $datosCabecera = [
                'id_cliente' => $venta->id_cliente,
                'id_venta' => $venta->id_venta,
                'id_vehiculo' => $venta->id_vehiculo,
                'id_tasa_interes' => null,
                'user_id' => $userId,
                'registrado_por' => $userId,
                'monto_total' => $precioObjetivo,
                'monto_inicial' => 0.0,
                'monto_financiado' => 0.0,
                'saldo_pendiente' => $precioObjetivo,
                'numero_cuotas' => 0,
                'fecha_inicio' => $fechaPago,
                'fecha_vencimiento' => $fechaPago,
                'fecha_vencimiento_final' => $fechaPago,
                'ruta_pagare' => null,
                'estado' => 'activo',
                'observaciones' => 'Registro unificado de pagos de contado/abonos.',
            ];
            if (Schema::hasColumn('creditos', 'modalidad')) {
                $datosCabecera['modalidad'] = $modalidad;
            }
            $cabecera = Credito::create($datosCabecera);
        }

        $siguienteNumero = (int) CuotaCredito::query()
            ->where('id_credito', $cabecera->id_credito)
            ->max('numero_cuota') + 1;

        $datosCuota = [
            'id_credito' => $cabecera->id_credito,
            'numero_cuota' => $siguienteNumero,
            'fecha_vencimiento' => $fechaPago,
            'monto_capital' => $montoAbono,
            'monto_interes' => 0.0,
            'monto_mora' => 0.0,
            'valor_cuota' => $montoAbono,
            'monto_pagado' => $montoAbono,
            'fecha_pago_real' => $fechaPago,
            'estado' => 'pagada',
        ];
        if (Schema::hasColumn('cuotas_credito', 'id_venta')) {
            $datosCuota['id_venta'] = $venta->id_venta;
        }

        $cuota = CuotaCredito::create($datosCuota);

        PagoCredito::create([
            'id_credito' => $cabecera->id_credito,
            'id_cuota_credito' => $cuota->id_cuota_credito,
            'id_transaccion_caja' => $idTransaccionCaja,
            'user_id' => $userId,
            'monto_pagado' => $montoAbono,
            'metodo_pago' => $modalidad,
            'numero_referencia' => null,
            'fecha_pago' => $fechaPago,
            'ruta_comprobante' => $rutaComprobante,
            'observaciones' => sprintf('Abono adicional venta #%d · Placa %s', $venta->id_venta, $venta->id_vehiculo),
        ]);

        $totalPagado = round((float) CuotaCredito::query()
            ->where('id_credito', $cabecera->id_credito)
            ->sum('monto_pagado'), 2);
        $nuevoSaldo = max(0.0, round($precioObjetivo - $totalPagado, 2));

        $cabecera->update([
            'monto_inicial' => $totalPagado,
            'saldo_pendiente' => $nuevoSaldo,
            'numero_cuotas' => $siguienteNumero,
            'fecha_vencimiento' => $fechaPago,
            'fecha_vencimiento_final' => $fechaPago,
            'estado' => $nuevoSaldo <= 0.009 ? 'pagado' : 'activo',
        ]);
    }

    private function sincronizarEstadosMoraCredito(Credito $credito): void
    {
        if (($credito->modalidad ?? 'credito') !== 'credito') {
            return;
        }

        if (! in_array($credito->estado, ['activo', 'en_mora'], true)) {
            return;
        }

        $hoy = now()->startOfDay();
        $huboCambios = false;

        foreach ($credito->cuotas as $cuota) {
            if ($cuota->estado === 'pendiente' && $cuota->fecha_vencimiento !== null && $cuota->fecha_vencimiento->lt($hoy)) {
                $cuota->update(['estado' => 'vencida']);
                $huboCambios = true;
            }
        }

        $tieneVencidas = $credito->cuotas->contains(
            static fn (CuotaCredito $c): bool => in_array($c->estado, ['vencida', 'parcial'], true)
                && $c->fecha_vencimiento !== null
                && $c->fecha_vencimiento->lt($hoy),
        );

        $estadoEsperado = $tieneVencidas ? 'en_mora' : 'activo';
        if ($credito->estado !== $estadoEsperado) {
            $credito->update(['estado' => $estadoEsperado]);
            $huboCambios = true;
        }

        if ($huboCambios) {
            $credito->unsetRelation('cuotas');
        }
    }

    /**
     * @param  array<string, mixed>  $datosComprador
     */
    private function sincronizarClienteComprador(Venta $venta, array $datosComprador): ?Cliente
    {
        $cedula = preg_replace('/[^0-9A-Za-z]/', '', (string) ($datosComprador['cedula'] ?? '')) ?? '';
        $nombreCompleto = trim((string) ($datosComprador['nombre_completo'] ?? ''));

        if ($cedula === '' || $nombreCompleto === '') {
            return null;
        }

        $partes = preg_split('/\s+/', $nombreCompleto) ?: [];
        $nombre = count($partes) > 2
            ? implode(' ', array_slice($partes, 0, 2))
            : ($partes[0] ?? $nombreCompleto);
        $apellido = count($partes) > 2
            ? implode(' ', array_slice($partes, 2))
            : (count($partes) === 2 ? $partes[1] : '-');

        $atributos = [
            'nombre' => mb_substr($nombre, 0, 100),
            'apellido' => mb_substr($apellido, 0, 100),
        ];

        if (is_string($venta->ruta_cedula_comprador) && $venta->ruta_cedula_comprador !== '') {
            $atributos['ruta_cedula'] = $venta->ruta_cedula_comprador;
        }

        $direccion = trim((string) ($datosComprador['direccion'] ?? ''));
        if ($direccion !== '') {
            $atributos['direccion'] = mb_substr($direccion, 0, 200);
        }

        $correo = trim((string) ($datosComprador['correo'] ?? ''));
        if ($correo !== '') {
            $atributos['correo'] = mb_substr($correo, 0, 120);
        }

        $telefono = trim((string) ($datosComprador['telefono'] ?? ''));
        if ($telefono !== '') {
            $atributos['telefono'] = mb_substr($telefono, 0, 30);
        }

        try {
            return Cliente::sincronizarConRol(
                $cedula,
                [
                    'nombre' => $nombre,
                    'apellido' => $apellido,
                    'direccion' => $direccion,
                    'correo' => $correo,
                    'telefono' => $telefono,
                    'ruta_cedula' => is_string($venta->ruta_cedula_comprador) ? $venta->ruta_cedula_comprador : '',
                ],
                \App\Models\TipoCliente::COMPRADOR,
            );
        } catch (Throwable $exception) {
            Log::warning('No se pudo sincronizar automáticamente el registro en clientes.', [
                'id_venta' => $venta->id_venta,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function registrarMovimientoCajaSeguro(
        Venta $venta,
        int $idTipoTransaccion,
        int $idMetodoPago,
        float $monto,
        string $fechaTransaccion,
        string $descripcion,
        ?string $rutaComprobante,
        int $userId,
    ): ?TransaccionCaja {
        try {
            return TransaccionCaja::create([
                'id_vehiculo' => $venta->id_vehiculo,
                'id_user' => $userId,
                'id_cliente' => $venta->id_cliente,
                'id_tipo_transaccion' => $idTipoTransaccion,
                'id_metodo_pago' => $idMetodoPago,
                'fecha_transaccion' => $fechaTransaccion,
                'monto' => $monto,
                'descripcion' => mb_substr($descripcion, 0, 255),
                'ruta_comprobante' => $rutaComprobante,
            ]);
        } catch (Throwable $exception) {
            Log::warning('No se pudo asentar movimiento en transacciones_cajas (verifique migración de id_cliente nullable).', [
                'id_venta' => $venta->id_venta,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
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
