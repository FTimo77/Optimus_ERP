<?php

namespace App\Services\Inventario;

use App\Models\CustodiaDocumentoVehiculo;
use App\Models\Documentos_vehiculo;
use App\Models\Movimientos_vehiculo;
use App\Models\Patio;
use App\Models\Tipo_movimiento;
use App\Models\User;
use App\Models\ValidacionDocumento;
use App\Models\Vehiculo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CustodiaDocumentosService
{
    /**
     * @param  list<string>  $items
     */
    public function solicitarEntrega(
        Vehiculo $vehiculo,
        array $items,
        int $patioDestinoId,
        ?int $usuarioRecepcionId,
        ?string $observacion,
        User $usuarioEntrega,
    ): void {
        $itemsUnicos = array_values(array_unique($items));
        $observacionLimpia = trim((string) ($observacion ?? ''));

        DB::transaction(function () use (
            $vehiculo,
            $itemsUnicos,
            $patioDestinoId,
            $usuarioRecepcionId,
            $observacionLimpia,
            $usuarioEntrega,
        ): void {
            foreach ($itemsUnicos as $item) {
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
                    'usuario_entrega_id' => $usuarioEntrega->id,
                    'usuario_recepcion_id' => $usuarioRecepcionId,
                    'patio_destino_id' => $patioDestinoId,
                    'patio_actual_id' => $patioActualPrevio,
                    'observacion' => $observacionLimpia === '' ? null : $observacionLimpia,
                    'solicitado_en' => now(),
                ]);
            }
        });
    }

    public function confirmarRecepcion(
        Vehiculo $vehiculo,
        CustodiaDocumentoVehiculo $custodia,
        User $usuarioReceptor,
    ): void {
        abort_unless($custodia->id_vehiculo === $vehiculo->id_vehiculo, 404);

        if ($custodia->usuario_recepcion_id !== null
            && $custodia->usuario_recepcion_id !== $usuarioReceptor->id
            && ! $usuarioReceptor->hasRole('Administrador')) {
            abort(403, 'Solo el usuario destinatario o un Administrador puede confirmar esta recepción.');
        }

        DB::transaction(function () use ($custodia, $usuarioReceptor): void {
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
                'usuario_recepcion_id' => $usuarioReceptor->id,
                'patio_actual_id' => $custodiaBloqueada->patio_destino_id,
                'recibido_en' => now(),
            ]);

            $pendientesRestantes = CustodiaDocumentoVehiculo::query()
                ->where('id_vehiculo', $custodiaBloqueada->id_vehiculo)
                ->where('estado', 'pendiente_recepcion')
                ->exists();

            if (! $pendientesRestantes || $custodiaBloqueada->item === 'llaves') {
                $vehiculoActual = Vehiculo::query()
                    ->where('id_vehiculo', $custodiaBloqueada->id_vehiculo)
                    ->lockForUpdate()
                    ->first();

                if ($vehiculoActual !== null && $vehiculoActual->id_patio !== $custodiaBloqueada->patio_destino_id) {
                    $patioOrigen = Patio::query()->find($vehiculoActual->id_patio)?->nombre ?? 'Sin asignar';
                    $patioDestino = Patio::query()->find($custodiaBloqueada->patio_destino_id)?->nombre ?? 'Patio destino';

                    $vehiculoActual->update([
                        'id_patio' => $custodiaBloqueada->patio_destino_id,
                    ]);

                    $tipoMovimiento = Tipo_movimiento::firstOrCreate(['nombre' => 'Recepción en Patio']);
                    Movimientos_vehiculo::create([
                        'id_vehiculo' => $vehiculoActual->id_vehiculo,
                        'id_tipo_movimiento' => $tipoMovimiento->id_tipo_movimiento,
                        'id_usuario' => $usuarioReceptor->id,
                        'descripcion' => "Recepción confirmada por {$usuarioReceptor->name}: vehículo ubicado en {$patioDestino} (origen: {$patioOrigen}).",
                        'fecha_movimiento' => now(),
                    ]);
                }
            }
        });
    }

    /**
     * @param  list<string>  $items
     */
    public function solicitarTransferenciaPatio(
        Vehiculo $vehiculo,
        array $items,
        int $patioDestinoId,
        int $usuarioRecepcionId,
        ?string $observacion,
        User $usuarioEntrega,
    ): void {
        if ($vehiculo->id_patio !== null && (int) $vehiculo->id_patio === $patioDestinoId) {
            throw ValidationException::withMessages([
                'patio_destino_id' => 'El patio de destino debe ser diferente al patio actual del vehículo.',
            ]);
        }

        $itemsUnicos = array_values(array_unique($items));
        $observacionLimpia = trim((string) ($observacion ?? ''));

        DB::transaction(function () use (
            $vehiculo,
            $itemsUnicos,
            $patioDestinoId,
            $usuarioRecepcionId,
            $observacionLimpia,
            $usuarioEntrega,
        ): void {
            $vehiculo->loadMissing('patio');
            $patioOrigenNombre = $vehiculo->patio?->nombre ?? 'Sin asignar';
            $patioDestinoNombre = Patio::query()->findOrFail($patioDestinoId)->nombre;
            $usuarioReceptor = User::query()->findOrFail($usuarioRecepcionId);

            foreach ($itemsUnicos as $item) {
                $pendiente = CustodiaDocumentoVehiculo::query()
                    ->where('id_vehiculo', $vehiculo->id_vehiculo)
                    ->where('item', $item)
                    ->where('estado', 'pendiente_recepcion')
                    ->lockForUpdate()
                    ->exists();

                if ($pendiente) {
                    $nombreItem = CustodiaDocumentoVehiculo::ITEMS[$item] ?? $item;
                    throw ValidationException::withMessages([
                        'items' => "El ítem \"{$nombreItem}\" ya tiene una solicitud pendiente de recepción.",
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
                    'usuario_entrega_id' => $usuarioEntrega->id,
                    'usuario_recepcion_id' => $usuarioReceptor->id,
                    'patio_destino_id' => $patioDestinoId,
                    'patio_actual_id' => $patioActualPrevio,
                    'observacion' => $observacionLimpia === '' ? "Transferencia {$patioOrigenNombre} → {$patioDestinoNombre}" : $observacionLimpia,
                    'solicitado_en' => now(),
                ]);
            }

            $etiquetasItems = array_map(
                static fn (string $clave): string => CustodiaDocumentoVehiculo::ITEMS[$clave] ?? $clave,
                $itemsUnicos,
            );

            $descripcion = sprintf(
                'Transferencia de patio solicitada: %s → %s · Envía: %s · Receptor: %s · Checklist: %s.',
                $patioOrigenNombre,
                $patioDestinoNombre,
                $usuarioEntrega->name,
                $usuarioReceptor->name,
                implode(', ', $etiquetasItems),
            );

            if ($observacionLimpia !== '') {
                $descripcion .= " Observación: {$observacionLimpia}";
            }

            $tipoMovimiento = Tipo_movimiento::firstOrCreate(['nombre' => 'Transferencia de Patio']);
            Movimientos_vehiculo::create([
                'id_vehiculo' => $vehiculo->id_vehiculo,
                'id_tipo_movimiento' => $tipoMovimiento->id_tipo_movimiento,
                'id_usuario' => $usuarioEntrega->id,
                'descripcion' => $descripcion,
                'fecha_movimiento' => now(),
            ]);
        });
    }

    public function subirContratoPrivado(Vehiculo $vehiculo, UploadedFile $archivo): void
    {
        $this->subirContratosPrivados($vehiculo, $archivo, null);
    }

    public function subirContratosPrivados(
        Vehiculo $vehiculo,
        ?UploadedFile $archivo1,
        ?UploadedFile $archivo2 = null,
    ): void {
        if ($archivo1 === null && $archivo2 === null) {
            throw ValidationException::withMessages([
                'contrato' => 'Debes seleccionar al menos un documento de contrato para subir.',
            ]);
        }

        $rutasNuevas = [];
        $rutaContrato1 = null;
        $rutaContrato2 = null;

        try {
            // Almacenamiento privado y seguro en disco local (nunca en public)
            if ($archivo1 !== null) {
                $ruta = $archivo1->store("documentos/contratos/{$vehiculo->id_vehiculo}", 'local');
                if ($ruta === false) {
                    throw new RuntimeException('No se pudo almacenar el contrato 1 en el disco privado.');
                }
                $rutaContrato1 = $ruta;
                $rutasNuevas[] = $ruta;
            }

            if ($archivo2 !== null) {
                $ruta = $archivo2->store("documentos/contratos/{$vehiculo->id_vehiculo}", 'local');
                if ($ruta === false) {
                    throw new RuntimeException('No se pudo almacenar el contrato 2 en el disco privado.');
                }
                $rutaContrato2 = $ruta;
                $rutasNuevas[] = $ruta;
            }

            $rutasAnteriores = [];

            DB::transaction(function () use ($vehiculo, $rutaContrato1, $rutaContrato2, &$rutasAnteriores): void {
                $docExistente = Documentos_vehiculo::query()
                    ->where('id_vehiculo', $vehiculo->id_vehiculo)
                    ->lockForUpdate()
                    ->first();

                if ($docExistente !== null) {
                    $atributos = [
                        'id_tipo_contrato' => $vehiculo->id_tipo_contrato ?? $docExistente->id_tipo_contrato,
                    ];

                    if ($rutaContrato1 !== null) {
                        if ($docExistente->ruta_contrato !== null && $docExistente->ruta_contrato !== $rutaContrato1) {
                            $rutasAnteriores[] = $docExistente->ruta_contrato;
                        }
                        $atributos['ruta_contrato'] = $rutaContrato1;
                    }

                    if ($rutaContrato2 !== null) {
                        if ($docExistente->ruta_contrato_2 !== null && $docExistente->ruta_contrato_2 !== $rutaContrato2) {
                            $rutasAnteriores[] = $docExistente->ruta_contrato_2;
                        }
                        $atributos['ruta_contrato_2'] = $rutaContrato2;
                    }

                    $docExistente->update($atributos);
                } else {
                    Documentos_vehiculo::create([
                        'id_vehiculo' => $vehiculo->id_vehiculo,
                        'id_tipo_contrato' => $vehiculo->id_tipo_contrato,
                        'ruta_contrato' => $rutaContrato1,
                        'ruta_contrato_2' => $rutaContrato2,
                    ]);
                }
            });

            foreach ($rutasAnteriores as $rutaAnterior) {
                if (Storage::disk('local')->exists($rutaAnterior)) {
                    Storage::disk('local')->delete($rutaAnterior);
                }
            }
        } catch (Throwable $exception) {
            if ($rutasNuevas !== []) {
                Storage::disk('local')->delete($rutasNuevas);
            }
            throw $exception;
        }
    }

    public function descargarContrato(Vehiculo $vehiculo, int $numero = 1): StreamedResponse
    {
        $documento = Documentos_vehiculo::query()
            ->where('id_vehiculo', $vehiculo->id_vehiculo)
            ->first();

        $ruta = $numero === 2 ? $documento?->ruta_contrato_2 : $documento?->ruta_contrato;
        abort_unless(
            is_string($ruta)
                && trim($ruta) !== ''
                && Storage::disk('local')->exists($ruta),
            404,
        );

        $extension = pathinfo($ruta, PATHINFO_EXTENSION);
        $sufijo = $numero === 2 ? '-2' : '';
        $nombreDescarga = $extension !== ''
            ? "contrato{$sufijo}-{$vehiculo->id_vehiculo}.{$extension}"
            : "contrato{$sufijo}-{$vehiculo->id_vehiculo}";

        return Storage::disk('local')->download(
            $ruta,
            $nombreDescarga,
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function subirDocumentoVehiculoNuevo(Vehiculo $vehiculo, string $tipo, UploadedFile $archivo): void
    {
        $mapaColumnas = [
            'matricula' => 'ruta_matricula',
            'revision' => 'ruta_revision',
            'factura' => 'ruta_factura',
        ];

        if (! isset($mapaColumnas[$tipo])) {
            throw ValidationException::withMessages([
                'tipo' => 'Tipo de documento no permitido para vehículos nuevos.',
            ]);
        }

        if (strtolower(trim((string) ($vehiculo->metodo_ingreso ?? ''))) !== 'nuevos') {
            throw ValidationException::withMessages([
                'archivo' => 'Esta carga directa aplica únicamente para vehículos ingresados en la categoría «Nuevos».',
            ]);
        }

        $columna = $mapaColumnas[$tipo];

        // Almacenamiento privado y seguro exclusivamente en disco local (nunca en public)
        $rutaNueva = $archivo->store("documentos/vehiculos/{$vehiculo->id_vehiculo}/{$tipo}", 'local');
        if ($rutaNueva === false) {
            throw new RuntimeException('No se pudo almacenar el documento en el disco privado.');
        }

        $rutaAnterior = null;

        try {
            DB::transaction(function () use ($vehiculo, $columna, $rutaNueva, &$rutaAnterior): void {
                $docExistente = Documentos_vehiculo::query()
                    ->where('id_vehiculo', $vehiculo->id_vehiculo)
                    ->lockForUpdate()
                    ->first();

                if ($docExistente !== null) {
                    $rutaAnterior = $docExistente->{$columna};
                    $docExistente->update([
                        $columna => $rutaNueva,
                    ]);
                } else {
                    Documentos_vehiculo::create([
                        'id_vehiculo' => $vehiculo->id_vehiculo,
                        'id_tipo_contrato' => $vehiculo->id_tipo_contrato,
                        $columna => $rutaNueva,
                    ]);
                }
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($rutaNueva);
            throw $exception;
        }

        if (is_string($rutaAnterior)
            && $rutaAnterior !== ''
            && $rutaAnterior !== $rutaNueva
            && Storage::disk('local')->exists($rutaAnterior)) {
            Storage::disk('local')->delete($rutaAnterior);
        }
    }

    public function servirDocumentoExpediente(
        Vehiculo $vehiculo,
        string $tipo,
        bool $inline,
        bool $puedeVerFinancieros,
    ): StreamedResponse {
        if ($tipo === 'comprobante_pago') {
            abort_unless($puedeVerFinancieros, 403, 'No tienes permiso para acceder a comprobantes de pago.');
        }

        $documento = Documentos_vehiculo::query()
            ->where('id_vehiculo', $vehiculo->id_vehiculo)
            ->first();

        $validacion = ValidacionDocumento::query()
            ->where('placa', $vehiculo->id_vehiculo)
            ->orderByDesc('id')
            ->first();

        $mapaRutas = [
            'matricula' => $documento?->ruta_matricula ?? $validacion?->ruta_documento,
            'cuv' => $documento?->ruta_cuv ?? $validacion?->ruta_cuv,
            'revision' => $documento?->ruta_revision,
            'factura' => $documento?->ruta_factura,
            'contrato' => $documento?->ruta_contrato ?? $validacion?->ruta_contrato_generado,
            'contrato_2' => $documento?->ruta_contrato_2,
            'cedula' => $validacion?->ruta_cedula_cliente,
            'constancia' => $documento?->ruta_constancia,
            'acta_entrega' => $documento?->ruta_acta_entrega,
            'impronta' => $documento?->ruta_impronta,
            'comprobante_pago' => $documento?->ruta_comprobante_pago ?? $validacion?->ruta_comprobante_pago,
        ];

        abort_unless(array_key_exists($tipo, $mapaRutas), 404);

        $ruta = $mapaRutas[$tipo];
        abort_unless(
            is_string($ruta)
                && trim($ruta) !== ''
                && Storage::disk('local')->exists($ruta),
            404,
        );

        if ($inline) {
            return Storage::disk('local')->response($ruta, null, [
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $extension = pathinfo($ruta, PATHINFO_EXTENSION);
        $nombreDescarga = $extension !== ''
            ? "{$tipo}-{$vehiculo->id_vehiculo}.{$extension}"
            : "{$tipo}-{$vehiculo->id_vehiculo}";

        return Storage::disk('local')->download(
            $ruta,
            $nombreDescarga,
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }
}
