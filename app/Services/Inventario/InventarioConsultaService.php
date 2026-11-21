<?php

namespace App\Services\Inventario;

use App\Models\CustodiaDocumentoVehiculo;
use App\Models\MultaVehiculo;
use App\Models\PagoVehiculo;
use App\Models\Patio;
use App\Models\User;
use App\Models\ValidacionDocumento;
use App\Models\Vehiculo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class InventarioConsultaService
{
    /**
     * @return array<string, mixed>
     */
    public function obtenerDatosDashboard(User $usuario): array
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
            'multas' => fn ($query) => $query
                ->with('pagador:id,name')
                ->orderByRaw("CASE WHEN estado = 'pendiente' THEN 0 ELSE 1 END")
                ->orderByDesc('id_multa'),
            'custodias' => fn ($query) => $query
                ->with([
                    'usuarioEntrega:id,name',
                    'usuarioRecepcion:id,name',
                    'patioDestino:id_patio,nombre',
                    'patioActual:id_patio,nombre',
                ])
                ->orderByDesc('id_custodia'),
            'credito.registrador:id,name',
            'pagos' => fn ($query) => $query->with('registrador:id,name')->orderBy('id_pago'),
        ]);

        $vehiculos->each(function (Vehiculo $vehiculo) use ($validacionesPorPlaca): void {
            $this->enriquecerVehiculo($vehiculo, $validacionesPorPlaca->get($vehiculo->id_vehiculo));
        });

        $usuario->load('patio:id_patio,nombre');

        return [
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
        ];
    }

    private function enriquecerVehiculo(Vehiculo $vehiculo, ?ValidacionDocumento $validacion): void
    {
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

        $metodoIngreso = $vehiculo->metodo_ingreso ?? $validacion?->categoria_ingreso;
        $vehiculo->setAttribute(
            'metodo_ingreso',
            is_string($metodoIngreso) && trim($metodoIngreso) !== ''
                ? strtolower(trim($metodoIngreso))
                : null,
        );

        $this->adjuntarIndicadoresMultas($vehiculo);
        $this->adjuntarIndicadoresCustodia($vehiculo, $validacion);
        $this->adjuntarIndicadoresPagosYCredito($vehiculo, $validacion);
        $this->adjuntarIndicadoresContrato($vehiculo, $validacion);
    }

    private function adjuntarIndicadoresMultas(Vehiculo $vehiculo): void
    {
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
    }

    private function adjuntarIndicadoresCustodia(Vehiculo $vehiculo, ?ValidacionDocumento $validacion): void
    {
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

        $custodiaLlaves = $vehiculo->custodias->firstWhere('item', 'llaves');
        $custodiaMatricula = $vehiculo->custodias->firstWhere('item', 'matricula');
        $custodiaRtv = $vehiculo->custodias->firstWhere('item', 'rtv');

        $rutaMatricula = $vehiculo->documentos?->ruta_matricula ?? $validacion?->ruta_documento;
        $tieneMatriculaCargada = (is_string($rutaMatricula)
            && trim($rutaMatricula) !== ''
            && Storage::disk('local')->exists($rutaMatricula))
            || $custodiaMatricula?->estado === 'recibido';

        $vehiculo->setAttribute('custodia_actual', $custodiaActual);
        $vehiculo->setAttribute('entrega_llaves_solicitada', $custodiaLlaves !== null);
        $vehiculo->setAttribute('entrega_llaves_estado', $custodiaLlaves?->estado ?? 'sin_asignar');
        $vehiculo->setAttribute('tiene_matricula_cargada', $tieneMatriculaCargada);
        $vehiculo->setAttribute('custodia_rtv_recibida', $custodiaRtv?->estado === 'recibido');
    }

    private function adjuntarIndicadoresPagosYCredito(Vehiculo $vehiculo, ?ValidacionDocumento $validacion): void
    {
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

        $creditoVencido = $vehiculo->credito !== null
            && $vehiculo->credito->estado === 'pendiente'
            && $vehiculo->credito->fecha_vencimiento !== null
            && $vehiculo->credito->fecha_vencimiento->copy()->endOfDay()->isPast();

        $vehiculo->setAttribute(
            'forma_pago',
            $validacion?->forma_pago
                ?? ($vehiculo->credito ? 'credito' : ($pagosDetalle->isNotEmpty() ? $pagosDetalle->first()['tipo_pago'] : null)),
        );
        $vehiculo->setAttribute('pagos_detalle', $pagosDetalle);
        $vehiculo->setAttribute('total_pagado', $totalPagado);
        $vehiculo->setAttribute('saldo_pendiente_pago', $saldoPendientePago);
        $vehiculo->setAttribute('credito_vencido', $creditoVencido);
        $vehiculo->setAttribute('credito_detalle', $vehiculo->credito === null ? null : [
            'id_credito' => $vehiculo->credito->id_credito,
            'monto_total' => (float) $vehiculo->credito->monto_total,
            'fecha_vencimiento' => $vehiculo->credito->fecha_vencimiento?->format('Y-m-d'),
            'estado' => $vehiculo->credito->estado,
            'observaciones' => $vehiculo->credito->observaciones,
            'registrado_por' => $vehiculo->credito->registrador?->name,
        ]);
    }

    private function adjuntarIndicadoresContrato(Vehiculo $vehiculo, ?ValidacionDocumento $validacion): void
    {
        $tipoContrato = $vehiculo->tipoContrato ?? $validacion?->tipoContrato;
        $tipoContratoNombre = $tipoContrato?->nombre;
        $requiereDocContrato = $tipoContrato !== null ? (bool) $tipoContrato->requiere_documento : false;

        $rutaContratoDoc = $vehiculo->documentos?->ruta_contrato;
        $tieneContratoCargado = is_string($rutaContratoDoc)
            && trim($rutaContratoDoc) !== ''
            && Storage::disk('local')->exists($rutaContratoDoc);

        $vehiculo->setAttribute('tipo_contrato_nombre', $tipoContratoNombre);
        $vehiculo->setAttribute('requiere_documento_contrato', $requiereDocContrato);
        $vehiculo->setAttribute('tiene_contrato_cargado', $tieneContratoCargado);
        $vehiculo->setAttribute(
            'contrato_cantidad_hojas',
            $tieneContratoCargado ? $this->contarHojasDocumento($rutaContratoDoc) : null,
        );
        $vehiculo->setAttribute('contrato_pendiente', $requiereDocContrato && ! $tieneContratoCargado);
        $vehiculo->setAttribute(
            'contrato_archivo_url',
            $tieneContratoCargado ? route('vehiculos.contrato.descargar', $vehiculo->id_vehiculo) : null,
        );
    }

    private function contarHojasDocumento(string $ruta): ?int
    {
        $extension = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return 1;
        }

        if ($extension === 'pdf') {
            $contenido = Storage::disk('local')->get($ruta);
            if (! is_string($contenido) || $contenido === '') {
                return null;
            }

            $conteoPage = preg_match_all('/\/Type\s*\/Page\b(?!s)/', $contenido);
            $maxCount = 0;
            if (preg_match_all('/\/Count\s+(\d+)/', $contenido, $matches) && ! empty($matches[1])) {
                $maxCount = max(array_map('intval', $matches[1]));
            }

            $hojas = max((int) $conteoPage, $maxCount);

            return $hojas > 0 ? $hojas : 1;
        }

        if ($extension === 'docx' && class_exists(ZipArchive::class)) {
            $rutaAbsoluta = Storage::disk('local')->path($ruta);
            $zip = new ZipArchive();
            if ($zip->open($rutaAbsoluta) === true) {
                $appXml = $zip->getFromName('docProps/app.xml');
                $zip->close();
                if (is_string($appXml) && preg_match('/<Pages>(\d+)<\/Pages>/', $appXml, $m)) {
                    return max(1, (int) $m[1]);
                }
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Vehiculo>  $vehiculos
     * @param  Collection<string, ValidacionDocumento>  $validacionesPorPlaca
     */
    private function sincronizarMultasDesdeAuditoria(Collection $vehiculos, Collection $validacionesPorPlaca): void
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

            $multas = is_array($resumen['multas'] ?? null) ? $resumen['multas'] : [];
            if (! empty($multas['tiene_multas'])) {
                $detalle = is_array($multas['listado'] ?? $multas['detalle'] ?? null)
                    ? ($multas['listado'] ?? $multas['detalle'])
                    : [];
                if ($detalle !== []) {
                    foreach ($detalle as $item) {
                        $registros[] = [
                            'id_vehiculo' => $vehiculo->id_vehiculo,
                            'fuente' => (string) ($item['entidad'] ?? 'Autority / ANT'),
                            'concepto' => mb_substr((string) ($item['descripcion'] ?? $item['detalle'] ?? $item['concepto'] ?? 'Multa de tránsito pendiente'), 0, 255),
                            'valor' => (float) ($item['valor'] ?? $item['total'] ?? 0),
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
