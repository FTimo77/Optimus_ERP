<?php

namespace App\Services\Inventario;

use App\Models\Credito;
use App\Models\Movimientos_vehiculo;
use App\Models\MultaVehiculo;
use App\Models\PagoVehiculo;
use App\Models\Tipo_movimiento;
use App\Models\User;
use App\Models\ValidacionDocumento;
use App\Models\Vehiculo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MultasPagosVehiculoService
{
    public function pagarMulta(Vehiculo $vehiculo, MultaVehiculo $multa, UploadedFile $comprobante, User $usuario): void
    {
        abort_unless($multa->id_vehiculo === $vehiculo->id_vehiculo, 404);

        $rutaComprobante = $comprobante->store(
            "vehiculos/{$vehiculo->id_vehiculo}/multas/{$multa->id_multa}",
            'local',
        );

        if ($rutaComprobante === false) {
            throw new RuntimeException('No se pudo almacenar el comprobante de pago de la multa.');
        }

        try {
            DB::transaction(function () use ($multa, $rutaComprobante, $usuario): void {
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
                    'pagado_por' => $usuario->id,
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
    }

    public function descargarComprobanteMulta(Vehiculo $vehiculo, MultaVehiculo $multa): StreamedResponse
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

    public function registrarAbono(
        Vehiculo $vehiculo,
        UploadedFile $comprobante,
        float $monto,
        ?string $fechaPago,
        User $usuario,
    ): void {
        $costoAdquisicion = round((float) ($vehiculo->costo_adquisicion ?? 0), 2);
        $montoNuevo = round($monto, 2);

        $ruta = $comprobante->store("vehiculos/{$vehiculo->id_vehiculo}/pagos", 'local');
        if ($ruta === false) {
            throw new RuntimeException('No se pudo almacenar el comprobante del abono.');
        }

        try {
            DB::transaction(function () use ($vehiculo, $costoAdquisicion, $montoNuevo, $fechaPago, $usuario, $ruta): void {
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
                    'fecha_pago' => $fechaPago ?: now()->format('Y-m-d'),
                    'registrado_por' => $usuario->id,
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
                    'id_usuario' => $usuario->id,
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
    }

    public function descargarComprobantePago(Vehiculo $vehiculo, PagoVehiculo $pago): StreamedResponse
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
}
