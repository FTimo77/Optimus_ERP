<?php

namespace App\Http\Controllers;

use App\Models\Credito;
use App\Models\CustodiaDocumentoVehiculo;
use App\Models\Documentos_vehiculo;
use App\Models\Movimientos_vehiculo;
use App\Models\MultaVehiculo;
use App\Models\PagoVehiculo;
use App\Models\Patio;
use App\Models\Tipo_movimiento;
use App\Models\User;
use App\Models\ValidacionDocumento;
use App\Models\Vehiculo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class InventarioVehiculoController extends Controller
{
    public function index(Request $request): Response
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
            'tipoContrato',
            'documentos',
            'patio',
            'estado',
            'proveedor',
            'imagenPrincipal',
        ])->orderByDesc('created_at')->get();

        $placas = $vehiculos->pluck('id_vehiculo')->all();

        $validacionesPorPlaca = ValidacionDocumento::query()
            ->with(['subidor:id,name', 'tipoContrato:id_tipo_contrato,nombre,requiere_documento'])
            ->whereIn('placa', $placas)
            ->orderByDesc('id')
            ->get()
            ->unique('placa')
            ->keyBy('placa');

        $this->sincronizarMultasDesdeAuditoria($vehiculos, $validacionesPorPlaca);

        $vehiculos->load([
            'multas' => fn ($query) => $query->with('pagador:id,name')->orderByRaw("CASE WHEN estado = 'pendiente' THEN 0 ELSE 1 END")->orderByDesc('id_multa'),
            'custodias' => fn ($query) => $query->with([
                'usuarioEntrega:id,name',
                'usuarioRecepcion:id,name',
                'patioDestino:id_patio,nombre',
                'patioActual:id_patio,nombre',
            ])->orderByDesc('id_custodia'),
            'credito.registrador:id,name',
            'pagos' => fn ($query) => $query->with('registrador:id,name')->orderBy('id_pago'),
        ]);

        $vehiculos->each(function (Vehiculo $vehiculo) use ($validacionesPorPlaca): void {
            $validacion = $validacionesPorPlaca->get($vehiculo->id_vehiculo);

            $vehiculo->setAttribute(
                'imagen_url',
                $vehiculo->imagenPrincipal
                    ? Storage::disk('public')->url($vehiculo->imagenPrincipal->ruta_imagen)
                    : null,
            );

            $vehiculo->setAttribute(
                'proveedor_nombre',
                $vehiculo->proveedor?->nombre
                    ?? $validacion?->subidor?->name
                    ?? 'No registrado',
            );

            $multasDetalle = $vehiculo->multas->map(static fn (MultaVehiculo $multa): array => [
                'id_multa' => $multa->id_multa,
                'fuente' => $multa->fuente ?? 'Auditoría',
                'concepto' => $multa->concepto,
                'valor' => (float) $multa->valor,
                'requiere_revision_manual' => (float) $multa->valor <= 0.0,
                'fecha_infraccion' => $multa->fecha_infraccion,
                'estado' => $multa->estado,
                'pagado_por' => $multa->pagador?->name,
                'pagado_en' => $multa->pagado_en?->toIso8601String(),
                'comprobante_url' => $multa->ruta_comprobante_pago === null
                    ? null
                    : route('vehiculos.multas.comprobante', [$vehiculo->id_vehiculo, $multa->id_multa]),
            ])->values();

            $multasPendientes = $multasDetalle->where('estado', 'pendiente');

            $vehiculo->setAttribute('multas_detalle', $multasDetalle);
            $vehiculo->setAttribute('multas_pendientes_count', $multasPendientes->count());
            $vehiculo->setAttribute('multas_pendientes_total', round((float) $multasPendientes->sum('valor'), 2));
            $vehiculo->setAttribute(
                'multas_requieren_revision_manual',
                $multasPendientes->contains(static fn (array $m): bool => $m['requiere_revision_manual']),
            );

            $custodiaActual = [];
            foreach (CustodiaDocumentoVehiculo::ITEMS as $clave => $etiqueta) {
                /** @var CustodiaDocumentoVehiculo|null $ultimo */
                $ultimo = $vehiculo->custodias->firstWhere('item', $clave);
                $custodiaActual[] = [
                    'item' => $clave,
                    'etiqueta' => $etiqueta,
                    'id_custodia' => $ultimo?->id_custodia,
                    'estado' => $ultimo?->estado ?? 'sin_asignar',
                    'usuario_entrega' => $ultimo?->usuarioEntrega?->name,
                    'usuario_recepcion' => $ultimo?->usuarioRecepcion?->name,
                    'usuario_recepcion_id' => $ultimo?->usuario_recepcion_id,
                    'patio_destino' => $ultimo?->patioDestino?->nombre,
                    'patio_actual' => $ultimo?->patioActual?->nombre ?? $vehiculo->patio?->nombre ?? 'Sin asignar',
                    'solicitado_en' => $ultimo?->solicitado_en?->toIso8601String(),
                    'recibido_en' => $ultimo?->recibido_en?->toIso8601String(),
                    'observacion' => $ultimo?->observacion,
                ];
            }

            $vehiculo->setAttribute('custodia_actual', $custodiaActual);

            $pagosDetalle = $vehiculo->pagos->map(static fn (PagoVehiculo $pago): array => [
                'id_pago' => $pago->id_pago,
                'monto' => (float) $pago->monto,
                'tipo_pago' => $pago->tipo_pago,
                'fecha_pago' => $pago->fecha_pago?->format('Y-m-d'),
                'registrado_por' => $pago->registrador?->name,
                'comprobante_url' => route('vehiculos.pagos.comprobante', [$vehiculo->id_vehiculo, $pago->id_pago]),
            ])->values();

            $costoAdq = round((float) ($vehiculo->costo_adquisicion ?? 0), 2);
            $totalPagado = round((float) $pagosDetalle->sum('monto'), 2);
            $saldoPendientePago = max(0.0, round($costoAdq - $totalPagado, 2));

            $vehiculo->setAttribute('forma_pago', $validacion?->forma_pago ?? ($vehiculo->credito ? 'credito' : ($pagosDetalle->isNotEmpty() ? $pagosDetalle->first()['tipo_pago'] : null)));
            $vehiculo->setAttribute('pagos_detalle', $pagosDetalle);
            $vehiculo->setAttribute('total_pagado', $totalPagado);
            $vehiculo->setAttribute('saldo_pendiente_pago', $saldoPendientePago);
            $vehiculo->setAttribute('credito_detalle', $vehiculo->credito === null ? null : [
                'id_credito' => $vehiculo->credito->id_credito,
                'monto_total' => (float) $vehiculo->credito->monto_total,
                'fecha_vencimiento' => $vehiculo->credito->fecha_vencimiento?->format('Y-m-d'),
                'estado' => $vehiculo->credito->estado,
                'observaciones' => $vehiculo->credito->observaciones,
                'registrado_por' => $vehiculo->credito->registrador?->name,
            ]);

            $tipoContrato = $vehiculo->tipoContrato ?? $validacion?->tipoContrato;
            $tipoContratoNombre = $tipoContrato?->nombre;
            $requiereDocContrato = $tipoContrato !== null
                ? (bool) $tipoContrato->requiere_documento
                : false;
            $rutaContratoDoc = $vehiculo->documentos?->ruta_contrato;
            $tieneContratoCargado = is_string($rutaContratoDoc)
                && trim($rutaContratoDoc) !== ''
                && (Storage::disk('local')->exists($rutaContratoDoc) || Storage::disk('public')->exists($rutaContratoDoc));

            $vehiculo->setAttribute('tipo_contrato_nombre', $tipoContratoNombre);
            $vehiculo->setAttribute('requiere_documento_contrato', $requiereDocContrato);
            $vehiculo->setAttribute('tiene_contrato_cargado', $tieneContratoCargado);
            $vehiculo->setAttribute('contrato_pendiente', $requiereDocContrato && ! $tieneContratoCargado);
            $vehiculo->setAttribute(
                'contrato_archivo_url',
                $tieneContratoCargado ? route('vehiculos.contrato.descargar', $vehiculo->id_vehiculo) : null,
            );
        });

        $usuario = $request->user();
        $usuario->load('patio:id_patio,nombre');

        return Inertia::render('Vehiculos/Inventario', [
            'vehiculos' => $vehiculos,
            'patios' => Patio::query()->orderBy('nombre')->get(['id_patio', 'nombre']),
            'usuarios' => User::query()
                ->with('patio:id_patio,nombre')
                ->orderBy('name')
                ->get(['id', 'name', 'id_patio']),
            'usuarioActual' => [
                'id' => $usuario->id,
                'name' => $usuario->name,
                'id_patio' => $usuario->id_patio,
                'patio_nombre' => $usuario->patio?->nombre,
                'es_admin' => $usuario->hasRole('Administrador'),
            ],
            'success' => session('success'),
        ]);
    }

    public function pagarMulta(Request $request, Vehiculo $vehiculo, MultaVehiculo $multa): RedirectResponse
    {
        abort_unless($multa->id_vehiculo === $vehiculo->id_vehiculo, 404);

        $request->validate([
            'comprobante' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $rutaComprobante = $request->file('comprobante')->store(
            "vehiculos/{$vehiculo->id_vehiculo}/multas/{$multa->id_multa}",
            'local',
        );

        if ($rutaComprobante === false) {
            throw new RuntimeException('No se pudo almacenar el comprobante de pago de la multa.');
        }

        try {
            DB::transaction(function () use ($request, $multa, $rutaComprobante): void {
                $multaBloqueada = MultaVehiculo::query()
                    ->whereKey($multa->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless(
                    $multaBloqueada->estado === 'pendiente',
                    409,
                    'Esta multa ya fue marcada como pagada.',
                );

                $multaBloqueada->update([
                    'estado' => 'pagada',
                    'ruta_comprobante_pago' => $rutaComprobante,
                    'pagado_por' => $request->user()->id,
                    'pagado_en' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            if (! Storage::disk('local')->delete($rutaComprobante)) {
                Log::warning('Could not remove uploaded fine receipt after failed transaction.', [
                    'multa_id' => $multa->id_multa,
                    'file_path' => $rutaComprobante,
                ]);
            }

            throw $exception;
        }

        return back()->with('success', 'Comprobante registrado y multa marcada como saldada.');
    }

    public function comprobanteMulta(Vehiculo $vehiculo, MultaVehiculo $multa): StreamedResponse
    {
        abort_unless(
            $multa->id_vehiculo === $vehiculo->id_vehiculo
                && $multa->ruta_comprobante_pago !== null
                && Storage::disk('local')->exists($multa->ruta_comprobante_pago),
            404,
        );

        $extension = pathinfo($multa->ruta_comprobante_pago, PATHINFO_EXTENSION);
        $nombreDescarga = $extension !== ''
            ? "comprobante-multa-{$vehiculo->id_vehiculo}-{$multa->id_multa}.{$extension}"
            : "comprobante-multa-{$vehiculo->id_vehiculo}-{$multa->id_multa}";

        return Storage::disk('local')->download(
            $multa->ruta_comprobante_pago,
            $nombreDescarga,
            ['X-Content-Type-Options' => 'nosniff'],
        );
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

        $items = array_values(array_unique($validated['items']));
        $usuarioEntregaId = $request->user()->id;
        $usuarioRecepcionId = $validated['usuario_recepcion_id'] ?? null;
        $patioDestinoId = (int) $validated['patio_destino_id'];

        $observacion = trim((string) ($validated['observacion'] ?? ''));

        DB::transaction(function () use (
            $vehiculo,
            $items,
            $usuarioEntregaId,
            $usuarioRecepcionId,
            $patioDestinoId,
            $observacion,
        ): void {
            foreach ($items as $item) {
                $pendiente = CustodiaDocumentoVehiculo::query()
                    ->where('id_vehiculo', $vehiculo->id_vehiculo)
                    ->where('item', $item)
                    ->where('estado', 'pendiente_recepcion')
                    ->lockForUpdate()
                    ->exists();

                if ($pendiente) {
                    $nombreItem = CustodiaDocumentoVehiculo::ITEMS[$item] ?? $item;
                    throw ValidationException::withMessages([
                        'items' => "El ítem \"{$nombreItem}\" ya tiene una solicitud de entrega pendiente de recepción.",
                    ]);
                }

                $patioActualPrevio = CustodiaDocumentoVehiculo::query()
                    ->where('id_vehiculo', $vehiculo->id_vehiculo)
                    ->where('item', $item)
                    ->orderByDesc('id_custodia')
                    ->value('patio_actual_id') ?? $vehiculo->id_patio;

                CustodiaDocumentoVehiculo::create([
                    'id_vehiculo' => $vehiculo->id_vehiculo,
                    'item' => $item,
                    'estado' => 'pendiente_recepcion',
                    'usuario_entrega_id' => $usuarioEntregaId,
                    'usuario_recepcion_id' => $usuarioRecepcionId,
                    'patio_destino_id' => $patioDestinoId,
                    'patio_actual_id' => $patioActualPrevio,
                    'observacion' => $observacion === '' ? null : $observacion,
                    'solicitado_en' => now(),
                ]);
            }
        });

        return back()->with('success', 'Solicitud de entrega-recepción enviada correctamente.');
    }

    public function confirmarRecepcionCustodia(
        Request $request,
        Vehiculo $vehiculo,
        CustodiaDocumentoVehiculo $custodia,
    ): RedirectResponse {
        abort_unless($custodia->id_vehiculo === $vehiculo->id_vehiculo, 404);

        $usuario = $request->user();

        if ($custodia->usuario_recepcion_id !== null
            && $custodia->usuario_recepcion_id !== $usuario->id
            && ! $usuario->hasRole('Administrador')) {
            abort(403, 'Solo el usuario destinatario o un Administrador puede confirmar esta recepción.');
        }

        DB::transaction(function () use ($custodia, $usuario): void {
            $custodiaBloqueada = CustodiaDocumentoVehiculo::query()
                ->whereKey($custodia->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $custodiaBloqueada->estado === 'pendiente_recepcion',
                409,
                'Esta solicitud ya fue recibida anteriormente.',
            );

            if ($custodiaBloqueada->patio_destino_id === null) {
                throw ValidationException::withMessages([
                    'custodia' => 'La solicitud no tiene un patio destino definido.',
                ]);
            }

            $custodiaBloqueada->update([
                'estado' => 'recibido',
                'usuario_recepcion_id' => $usuario->id,
                'patio_actual_id' => $custodiaBloqueada->patio_destino_id,
                'recibido_en' => now(),
            ]);
        });

        return back()->with('success', 'Recepción confirmada y custodia asignada al patio destino de la solicitud.');
    }

    public function registrarAbonoPago(Request $request, Vehiculo $vehiculo): RedirectResponse
    {
        $validated = $request->validate([
            'comprobante' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'monto' => ['required', 'numeric', 'gt:0'],
            'fecha_pago' => ['nullable', 'date'],
        ]);

        $costoAdquisicion = round((float) ($vehiculo->costo_adquisicion ?? 0), 2);
        $montoNuevo = round((float) $validated['monto'], 2);

        $ruta = $request->file('comprobante')->store("vehiculos/{$vehiculo->id_vehiculo}/pagos", 'local');
        if ($ruta === false) {
            throw new RuntimeException('No se pudo almacenar el comprobante del abono.');
        }

        try {
            DB::transaction(function () use ($request, $vehiculo, $validated, $costoAdquisicion, $montoNuevo, $ruta): void {
                $pagadoActual = round((float) PagoVehiculo::query()
                    ->where('id_vehiculo', $vehiculo->id_vehiculo)
                    ->lockForUpdate()
                    ->sum('monto'), 2);

                if ($costoAdquisicion > 0 && ($pagadoActual + $montoNuevo) > ($costoAdquisicion + 0.01)) {
                    $saldo = max(0, round($costoAdquisicion - $pagadoActual, 2));
                    throw ValidationException::withMessages([
                        'monto' => 'El abono ($'.number_format($montoNuevo, 2).') excede el saldo pendiente ($'.number_format($saldo, 2).').',
                    ]);
                }

                $validacionId = ValidacionDocumento::query()
                    ->where('placa', $vehiculo->id_vehiculo)
                    ->orderByDesc('id')
                    ->value('id');

                PagoVehiculo::create([
                    'validacion_documento_id' => $validacionId,
                    'id_vehiculo' => $vehiculo->id_vehiculo,
                    'ruta_comprobante' => $ruta,
                    'monto' => $montoNuevo,
                    'tipo_pago' => 'cuota',
                    'fecha_pago' => ! empty($validated['fecha_pago']) ? $validated['fecha_pago'] : now()->format('Y-m-d'),
                    'registrado_por' => $request->user()->id,
                ]);

                if ($costoAdquisicion > 0 && ($pagadoActual + $montoNuevo) >= ($costoAdquisicion - 0.01)) {
                    Credito::query()
                        ->where('id_vehiculo', $vehiculo->id_vehiculo)
                        ->where('estado', 'pendiente')
                        ->update(['estado' => 'pagado']);
                }

                $tipoMovimiento = Tipo_movimiento::firstOrCreate(['nombre' => 'Abono de Pago']);
                Movimientos_vehiculo::create([
                    'id_vehiculo' => $vehiculo->id_vehiculo,
                    'id_tipo_movimiento' => $tipoMovimiento->id_tipo_movimiento,
                    'id_usuario' => $request->user()->id,
                    'descripcion' => sprintf(
                        'Registro de cuota/abono por $%s (acumulado: $%s / $%s).',
                        number_format($montoNuevo, 2, '.', ','),
                        number_format($pagadoActual + $montoNuevo, 2, '.', ','),
                        number_format($costoAdquisicion, 2, '.', ','),
                    ),
                    'fecha_movimiento' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($ruta);
            throw $exception;
        }

        return back()->with('success', 'Comprobante y abono registrados correctamente.');
    }

    public function comprobantePago(Vehiculo $vehiculo, PagoVehiculo $pago): StreamedResponse
    {
        abort_unless(
            $pago->id_vehiculo === $vehiculo->id_vehiculo
                && Storage::disk('local')->exists($pago->ruta_comprobante),
            404,
        );

        $extension = pathinfo($pago->ruta_comprobante, PATHINFO_EXTENSION);
        $nombreDescarga = $extension !== ''
            ? "comprobante-pago-{$vehiculo->id_vehiculo}-{$pago->id_pago}.{$extension}"
            : "comprobante-pago-{$vehiculo->id_vehiculo}-{$pago->id_pago}";

        return Storage::disk('local')->download(
            $pago->ruta_comprobante,
            $nombreDescarga,
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function subirContrato(Request $request, Vehiculo $vehiculo): RedirectResponse
    {
        $request->validate([
            'contrato' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        // Almacenamiento privado y seguro en disco local (nunca en public)
        $rutaContrato = $request->file('contrato')->store(
            "documentos/contratos/{$vehiculo->id_vehiculo}",
            'local',
        );

        if ($rutaContrato === false) {
            throw new RuntimeException('No se pudo almacenar el contrato en el disco privado.');
        }

        $rutaAnterior = null;

        try {
            DB::transaction(function () use ($vehiculo, $rutaContrato, &$rutaAnterior): void {
                $docExistente = Documentos_vehiculo::query()
                    ->where('id_vehiculo', $vehiculo->id_vehiculo)
                    ->lockForUpdate()
                    ->first();

                if ($docExistente !== null) {
                    $rutaAnterior = $docExistente->ruta_contrato;
                    $docExistente->update([
                        'id_tipo_contrato' => $vehiculo->id_tipo_contrato ?? $docExistente->id_tipo_contrato,
                        'ruta_contrato' => $rutaContrato,
                    ]);
                } else {
                    Documentos_vehiculo::create([
                        'id_vehiculo' => $vehiculo->id_vehiculo,
                        'id_tipo_contrato' => $vehiculo->id_tipo_contrato,
                        'ruta_contrato' => $rutaContrato,
                    ]);
                }
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($rutaContrato);
            throw $exception;
        }

        if ($rutaAnterior !== null
            && $rutaAnterior !== $rutaContrato
            && Storage::disk('local')->exists($rutaAnterior)) {
            Storage::disk('local')->delete($rutaAnterior);
        }

        return back()->with('success', 'Contrato guardado correctamente en almacenamiento privado.');
    }

    public function descargarContrato(Vehiculo $vehiculo): StreamedResponse
    {
        $documento = Documentos_vehiculo::query()
            ->where('id_vehiculo', $vehiculo->id_vehiculo)
            ->first();

        $ruta = $documento?->ruta_contrato;
        abort_unless(is_string($ruta) && trim($ruta) !== '', 404);

        $disco = Storage::disk('local')->exists($ruta)
            ? 'local'
            : (Storage::disk('public')->exists($ruta) ? 'public' : null);

        abort_unless($disco !== null, 404);

        $extension = pathinfo($ruta, PATHINFO_EXTENSION);
        $nombreDescarga = $extension !== ''
            ? "contrato-{$vehiculo->id_vehiculo}.{$extension}"
            : "contrato-{$vehiculo->id_vehiculo}";

        return Storage::disk($disco)->download(
            $ruta,
            $nombreDescarga,
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    /**
     * Sincroniza multas y valores SRI pendientes detectados en la auditoría hacia multas_vehiculos.
     *
     * @param  \Illuminate\Support\Collection<int, Vehiculo>  $vehiculos
     * @param  \Illuminate\Support\Collection<string, ValidacionDocumento>  $validacionesPorPlaca
     */
    private function sincronizarMultasDesdeAuditoria($vehiculos, $validacionesPorPlaca): void
    {
        $placasConMultas = MultaVehiculo::query()
            ->whereIn('id_vehiculo', $vehiculos->pluck('id_vehiculo'))
            ->distinct()
            ->pluck('id_vehiculo')
            ->flip();

        foreach ($vehiculos as $vehiculo) {
            if ($placasConMultas->has($vehiculo->id_vehiculo)) {
                continue;
            }

            /** @var ValidacionDocumento|null $validacion */
            $validacion = $validacionesPorPlaca->get($vehiculo->id_vehiculo);
            $resumen = $validacion?->resumen_auditoria;
            if (! is_array($resumen)) {
                continue;
            }

            $registros = [];

            // 1. Multas Autority / generales
            $multas = is_array($resumen['multas'] ?? null) ? $resumen['multas'] : [];
            if (! empty($multas['tiene_multas'])) {
                $detalle = is_array($multas['listado'] ?? $multas['detalle'] ?? null)
                    ? ($multas['listado'] ?? $multas['detalle'])
                    : [];
                if ($detalle !== []) {
                    foreach ($detalle as $item) {
                        $valor = (float) ($item['valor'] ?? $item['total'] ?? 0);
                        $registros[] = [
                            'id_vehiculo' => $vehiculo->id_vehiculo,
                            'fuente' => (string) ($item['entidad'] ?? 'Autority / ANT'),
                            'concepto' => mb_substr((string) ($item['descripcion'] ?? $item['detalle'] ?? $item['concepto'] ?? 'Multa de tránsito pendiente'), 0, 255),
                            'valor' => $valor,
                            'fecha_infraccion' => isset($item['fecha']) ? (string) $item['fecha'] : null,
                            'estado' => 'pendiente',
                        ];
                    }
                } else {
                    $registros[] = [
                        'id_vehiculo' => $vehiculo->id_vehiculo,
                        'fuente' => 'Autority / ANT',
                        'concepto' => 'Multas de tránsito pendientes (requiere revisión manual si el valor es $0.00)',
                        'valor' => (float) ($multas['total'] ?? 0),
                        'fecha_infraccion' => null,
                        'estado' => 'pendiente',
                    ];
                }
            }

            // 2. Fuentes directas AMT / CTE / ATM (si no se duplicaron en multas generales)
            foreach (['amt' => 'AMT (Quito)', 'cte' => 'CTE', 'atm' => 'ATM (Guayaquil)'] as $clave => $nombreFuente) {
                $fuenteData = is_array($resumen[$clave] ?? null) ? $resumen[$clave] : [];
                if (empty($fuenteData['tiene_multas'])) {
                    continue;
                }
                $listado = is_array($fuenteData['listado'] ?? null) ? $fuenteData['listado'] : [];
                if ($listado !== []) {
                    foreach ($listado as $item) {
                        $registros[] = [
                            'id_vehiculo' => $vehiculo->id_vehiculo,
                            'fuente' => $nombreFuente,
                            'concepto' => mb_substr((string) ($item['descripcion'] ?? $item['concepto'] ?? "Multa {$nombreFuente}"), 0, 255),
                            'valor' => (float) ($item['valor'] ?? $item['total'] ?? 0),
                            'fecha_infraccion' => isset($item['fecha']) ? (string) $item['fecha'] : null,
                            'estado' => 'pendiente',
                        ];
                    }
                } else {
                    $registros[] = [
                        'id_vehiculo' => $vehiculo->id_vehiculo,
                        'fuente' => $nombreFuente,
                        'concepto' => "Multas pendientes en {$nombreFuente} (requiere revisión manual si el valor es \$0.00)",
                        'valor' => (float) ($fuenteData['total'] ?? 0),
                        'fecha_infraccion' => null,
                        'estado' => 'pendiente',
                    ];
                }
            }

            // 3. Valores pendientes SRI
            $sri = is_array($resumen['sri'] ?? null) ? $resumen['sri'] : [];
            if (! empty($sri['tiene_deuda']) && (float) ($sri['total'] ?? 0) > 0) {
                $rubros = is_array($sri['rubros'] ?? null) ? $sri['rubros'] : [];
                if ($rubros !== []) {
                    foreach ($rubros as $rubro) {
                        $valorRubro = (float) ($rubro['valor'] ?? 0);
                        if ($valorRubro <= 0) {
                            continue;
                        }
                        $registros[] = [
                            'id_vehiculo' => $vehiculo->id_vehiculo,
                            'fuente' => 'SRI',
                            'concepto' => mb_substr((string) ($rubro['descripcion'] ?? 'Valor pendiente SRI'), 0, 255),
                            'valor' => $valorRubro,
                            'fecha_infraccion' => isset($rubro['anio']) ? (string) $rubro['anio'] : null,
                            'estado' => 'pendiente',
                        ];
                    }
                } else {
                    $registros[] = [
                        'id_vehiculo' => $vehiculo->id_vehiculo,
                        'fuente' => 'SRI',
                        'concepto' => 'Valores pendientes de matriculación SRI',
                        'valor' => (float) $sri['total'],
                        'fecha_infraccion' => null,
                        'estado' => 'pendiente',
                    ];
                }
            }

            foreach ($registros as $registro) {
                MultaVehiculo::create($registro);
            }
        }
    }
}
