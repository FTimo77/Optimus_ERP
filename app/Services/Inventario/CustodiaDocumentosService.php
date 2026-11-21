<?php

namespace App\Services\Inventario;

use App\Models\CustodiaDocumentoVehiculo;
use App\Models\Documentos_vehiculo;
use App\Models\User;
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
        });
    }

    public function subirContratoPrivado(Vehiculo $vehiculo, UploadedFile $archivo): void
    {
        // Almacenamiento privado y seguro en disco local (nunca en public)
        $rutaContrato = $archivo->store(
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
    }

    public function descargarContrato(Vehiculo $vehiculo): StreamedResponse
    {
        $documento = Documentos_vehiculo::query()
            ->where('id_vehiculo', $vehiculo->id_vehiculo)
            ->first();

        $ruta = $documento?->ruta_contrato;
        abort_unless(
            is_string($ruta)
                && trim($ruta) !== ''
                && Storage::disk('local')->exists($ruta),
            404,
        );

        $extension = pathinfo($ruta, PATHINFO_EXTENSION);
        $nombreDescarga = $extension !== ''
            ? "contrato-{$vehiculo->id_vehiculo}.{$extension}"
            : "contrato-{$vehiculo->id_vehiculo}";

        return Storage::disk('local')->download(
            $ruta,
            $nombreDescarga,
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }
}
