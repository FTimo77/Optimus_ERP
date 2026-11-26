<?php

namespace App\Services;

use App\Models\CuotaCredito;
use App\Models\PagoVenta;
use App\Models\TipoContrato;
use App\Models\ValidacionDocumento;
use App\Models\Venta;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use NumberFormatter;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use ZipArchive;

class ConstanciaVentaGeneratorService
{
    public const CATEGORIA_PROPIO = 'PROPIO';

    public const CATEGORIA_PODER = 'PODER';

    public const MODALIDAD_CONTADO = 'CONTADO';

    public const MODALIDAD_CREDITO = 'CREDITO';

    public const PLANTILLA_PROPIO_CONTADO = 'app/plantillas/Contado_propio.docx';

    public const PLANTILLA_PROPIO_CREDITO = 'app/plantillas/Credito-Propio.docx';

    public const PLANTILLA_PODER_CONTADO = 'app/plantillas/Contado_poder.docx';

    public const PLANTILLA_PODER_CREDITO = 'app/plantillas/credito_poder.docx';

    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * Resuelve la matriz 2x2 (Categoría `PROPIO`|`PODER` × Modalidad `CONTADO`|`CREDITO`) para la venta.
     *
     * @return array{
     *     codigo: string,
     *     categoria: string,
     *     modalidad: string,
     *     requiere_cliente_vendedor: bool,
     *     etiqueta: string,
     *     archivo_plantilla: string,
     *     tipo_contrato_id: int|null,
     *     tipo_contrato_nombre: string,
     *     plantilla_relativa: string,
     *     descripcion: string,
     * }
     */
    public function resolverEstrategia(Venta $venta): array
    {
        $venta->loadMissing([
            'vehiculo.tipoContrato',
            'vehiculo.documentos.tipoContrato',
            'credito',
        ]);

        $tipoContrato = $venta->vehiculo?->tipoContrato
            ?? $venta->vehiculo?->documentos?->tipoContrato;

        $categoria = $this->resolverCategoriaTipoContrato($tipoContrato);
        $modalidad = $this->resolverModalidadPago($venta);

        return $this->construirDescriptorEstrategia($tipoContrato, $categoria, $modalidad);
    }

    public function resolverCategoriaTipoContrato(?TipoContrato $tipoContrato): string
    {
        $nombre = trim((string) ($tipoContrato?->nombre ?? ''));
        $norm = $this->normalizarTextoComparacion($nombre);

        // Categoría PODER: Poder, Contrato de mandato, Firma directa
        if (
            $norm !== ''
            && (
                str_contains($norm, 'poder')
                || str_contains($norm, 'mandato')
                || str_contains($norm, 'firma directa')
            )
        ) {
            return self::CATEGORIA_PODER;
        }

        // Categoría PROPIO: Doble contrato, Contrato directo, Propietario (y fallback por defecto)
        return self::CATEGORIA_PROPIO;
    }

    public function resolverModalidadPago(Venta $venta): string
    {
        $formaPago = strtolower(trim((string) ($venta->forma_pago ?? '')));
        if ($formaPago === 'credito') {
            return self::MODALIDAD_CREDITO;
        }

        $credito = $venta->credito;
        if (
            $formaPago === ''
            && $credito !== null
            && strtolower((string) ($credito->modalidad ?? 'credito')) === 'credito'
            && in_array($credito->estado, ['pendiente_aprobacion_credito', 'activo', 'en_mora', 'pagado'], true)
        ) {
            return self::MODALIDAD_CREDITO;
        }

        return self::MODALIDAD_CONTADO;
    }

    /**
     * @return array{
     *     codigo: string,
     *     categoria: string,
     *     modalidad: string,
     *     requiere_cliente_vendedor: bool,
     *     etiqueta: string,
     *     archivo_plantilla: string,
     *     tipo_contrato_id: int|null,
     *     tipo_contrato_nombre: string,
     *     plantilla_relativa: string,
     *     descripcion: string,
     * }
     */
    private function construirDescriptorEstrategia(
        ?TipoContrato $tipoContrato,
        string $categoria,
        string $modalidad,
    ): array {
        $nombreContrato = trim((string) ($tipoContrato?->nombre ?? ''));
        $esPoder = $categoria === self::CATEGORIA_PODER;
        $esCredito = $modalidad === self::MODALIDAD_CREDITO;

        if ($esPoder && $esCredito) {
            $plantilla = self::PLANTILLA_PODER_CREDITO;
            $codigo = 'poder_credito';
            $etiqueta = 'Constancia Poder / Comisionista · Crédito';
            $descripcion = 'Modelo para vehículos con Poder, Mandato o Firma Directa vendidos a crédito.';
        } elseif ($esPoder && ! $esCredito) {
            $plantilla = self::PLANTILLA_PODER_CONTADO;
            $codigo = 'poder_contado';
            $etiqueta = 'Acta Liquidación Poder / Comisionista · Contado';
            $descripcion = 'Modelo para vehículos con Poder, Mandato o Firma Directa vendidos de contado.';
        } elseif (! $esPoder && $esCredito) {
            $plantilla = self::PLANTILLA_PROPIO_CREDITO;
            $codigo = 'propio_credito';
            $etiqueta = 'Constancia Vehículo Propio · Crédito';
            $descripcion = 'Modelo para vehículos Propios, Doble Contrato o Contrato Directo vendidos a crédito.';
        } else {
            $plantilla = self::PLANTILLA_PROPIO_CONTADO;
            $codigo = 'propio_contado';
            $etiqueta = 'Constancia Vehículo Propio · Contado';
            $descripcion = 'Modelo para vehículos Propios, Doble Contrato o Contrato Directo vendidos de contado.';
        }

        return [
            'codigo' => $codigo,
            'categoria' => $categoria,
            'modalidad' => $modalidad,
            'requiere_cliente_vendedor' => $esPoder,
            'etiqueta' => $etiqueta,
            'archivo_plantilla' => basename($plantilla),
            'tipo_contrato_id' => $tipoContrato?->id_tipo_contrato,
            'tipo_contrato_nombre' => $nombreContrato !== '' ? $nombreContrato : 'Propietario (Por defecto)',
            'plantilla_relativa' => $plantilla,
            'descripcion' => $descripcion,
        ];
    }

    /**
     * Resuelve nombre y cédula del cliente vendedor / propietario anterior del vehículo.
     *
     * @param  array<string, mixed>  $datosEntrada
     * @return array{nombre_completo: string, cedula: string}
     */
    public function resolverDatosClienteVendedor(Venta $venta, array $datosEntrada = []): array
    {
        $venta->loadMissing([
            'vehiculo.clienteVendedor',
            'vehiculo.proveedor',
        ]);

        $nombreManual = trim((string) ($datosEntrada['nombre_cliente_vendedor'] ?? ''));
        $cedulaManual = preg_replace('/[^0-9A-Za-z]/', '', trim((string) ($datosEntrada['cedula_cliente_vendedor'] ?? ''))) ?? '';

        $vehiculo = $venta->vehiculo;
        $clienteVendedor = $vehiculo?->clienteVendedor;
        $proveedor = $vehiculo?->proveedor;

        $nombre = $nombreManual !== ''
            ? mb_strtoupper($nombreManual, 'UTF-8')
            : ($clienteVendedor !== null ? mb_strtoupper(trim($clienteVendedor->nombre_completo), 'UTF-8') : '');
        $cedula = $cedulaManual !== ''
            ? $cedulaManual
            : ($clienteVendedor !== null ? trim((string) $clienteVendedor->cedula) : '');

        if (($nombre === '' || $cedula === '') && $vehiculo !== null && Schema::hasTable('validacion_documentos')) {
            $validacion = ValidacionDocumento::query()
                ->where('placa', $vehiculo->id_vehiculo)
                ->latest('id')
                ->first();

            if ($validacion !== null) {
                $datosCedula = is_array($validacion->datos_cedula_cliente) ? $validacion->datos_cedula_cliente : [];
                $datosIngreso = is_array($validacion->datos_ingreso) ? $validacion->datos_ingreso : [];
                $datosOficiales = is_array($validacion->datos_oficiales) ? $validacion->datos_oficiales : [];
                $datosDoc = is_array($validacion->datos_documento) ? $validacion->datos_documento : [];

                if ($nombre === '') {
                    $candidatoNombre = trim((string) (
                        $datosCedula['nombre_completo']
                        ?? $datosIngreso['vendedor_nombre']
                        ?? $datosOficiales['propietario']
                        ?? $datosDoc['propietario']
                        ?? ''
                    ));
                    if ($candidatoNombre !== '') {
                        $nombre = mb_strtoupper($candidatoNombre, 'UTF-8');
                    }
                }

                if ($cedula === '') {
                    $candidatoCedula = preg_replace('/[^0-9A-Za-z]/', '', trim((string) (
                        $datosCedula['cedula']
                        ?? $datosIngreso['vendedor_cedula']
                        ?? $datosOficiales['cedula_propietario']
                        ?? $datosDoc['cedula']
                        ?? ''
                    ))) ?? '';
                    if ($candidatoCedula !== '') {
                        $cedula = $candidatoCedula;
                    }
                }
            }
        }

        // Último fallback a proveedores únicamente si no es un registro genérico de demostración
        if ($nombre === '' && $proveedor !== null && trim((string) $proveedor->nombre) !== '') {
            $nombre = mb_strtoupper(trim((string) $proveedor->nombre), 'UTF-8');
        }
        if ($cedula === '' && $proveedor !== null && trim((string) $proveedor->cedula) !== '') {
            $cedula = trim((string) $proveedor->cedula);
        }

        return [
            'nombre_completo' => $nombre,
            'cedula' => $cedula,
        ];
    }

    /**
     * Genera la constancia de compra-venta (.docx) en el disco privado `local`
     * (`storage/app/private/ventas/{id_venta}/constancias/...`) y devuelve su ruta relativa.
     *
     * @param  array<string, mixed>  $datosComprador
     */
    public function generar(Venta $venta, array $datosComprador = []): string
    {
        $venta->loadMissing([
            'cliente',
            'vehiculo.marca',
            'vehiculo.color',
            'vehiculo.tipoContrato',
            'vehiculo.documentos.tipoContrato',
            'vehiculo.clienteVendedor.tipoCliente',
            'vehiculo.proveedor',
            'pagos',
            'cuotasCredito.pagos',
            'credito.cuotas.pagos',
        ]);

        $estrategia = $this->resolverEstrategia($venta);
        $plantillaOriginal = storage_path($estrategia['plantilla_relativa']);

        if (! is_file($plantillaOriginal)) {
            throw new RuntimeException('No se encontró la plantilla Word en storage/'.$estrategia['plantilla_relativa']);
        }

        $token = bin2hex(random_bytes(12));
        $plantillaTemporal = storage_path("app/constancia_tpl_{$token}.docx");

        try {
            $this->prepararPlantillaNormalizada($plantillaOriginal, $plantillaTemporal);

            $template = new TemplateProcessor($plantillaTemporal);

            // A. Fecha de emisión (America/Guayaquil, en español)
            $ahora = Carbon::now('America/Guayaquil');
            $diaNum = (int) $ahora->format('j');
            $anioNum = (int) $ahora->format('Y');
            $mesNombre = $this->nombreMesEspanol((int) $ahora->format('n'));
            $diaLetras = $this->numeroALetras($diaNum);
            $anioLetras = $this->numeroALetras($anioNum);

            $template->setValue('dia', str_pad((string) $diaNum, 2, '0', STR_PAD_LEFT));
            $template->setValue('dia_letras', $this->escaparXml($diaLetras));
            $template->setValue('mes_letras', $this->escaparXml($mesNombre));
            $template->setValue('mes', $this->escaparXml($mesNombre));
            $template->setValue('anio', (string) $anioNum);
            $template->setValue('anio_letras', $this->escaparXml($anioLetras));
            $template->setValue('anio_letra', $this->escaparXml($anioLetras));

            // B. Datos del Comprador ($venta->cliente + datos escaneados/confirmados)
            $compradorModel = $venta->cliente;
            $datosGuardados = is_array($venta->datos_cedula_comprador) ? $venta->datos_cedula_comprador : [];

            $nombreComprador = trim((string) (
                $datosComprador['nombre_completo']
                ?? ($compradorModel !== null ? $compradorModel->nombre_completo : '')
                ?: ($datosGuardados['nombre_completo'] ?? '')
            ));
            $nombreComprador = mb_strtoupper($nombreComprador !== '' ? $nombreComprador : 'POR REGISTRAR', 'UTF-8');

            $cedulaComprador = trim((string) (
                $datosComprador['cedula']
                ?? $compradorModel?->cedula
                ?? $datosGuardados['cedula']
                ?? ''
            ));
            $direccionComprador = trim((string) (
                $datosComprador['direccion']
                ?? $compradorModel?->direccion
                ?? $datosGuardados['direccion']
                ?? ''
            ));
            $telefonoComprador = trim((string) (
                $datosComprador['telefono']
                ?? $compradorModel?->telefono
                ?? $datosGuardados['telefono']
                ?? ''
            ));
            $correoComprador = trim((string) (
                $datosComprador['correo']
                ?? $compradorModel?->correo
                ?? $datosGuardados['correo']
                ?? ''
            ));

            $estadoCivilRaw = trim((string) (
                $datosComprador['estado_civil']
                ?? $datosGuardados['estado_civil']
                ?? ''
            ));
            if ($estadoCivilRaw === '' && ! empty($datosComprador['es_casado'] ?? $datosGuardados['es_casado'] ?? false)) {
                $estadoCivilRaw = 'casado';
            }
            $estadoCivilTexto = $estadoCivilRaw !== ''
                ? mb_strtolower($estadoCivilRaw, 'UTF-8')
                : 'soltero';

            $nombreCompradorXml = $this->escaparXml($nombreComprador);
            $cedulaCompradorXml = $this->escaparXml($cedulaComprador);
            $direccionCompradorXml = $this->escaparXml($direccionComprador);
            $telefonoCompradorXml = $this->escaparXml($telefonoComprador);
            $correoCompradorXml = $this->escaparXml($correoComprador);

            $template->setValue('nombre_cliente_comprador', $nombreCompradorXml);
            $template->setValue('nombre_cliente', $nombreCompradorXml);
            $template->setValue('cliente', $nombreCompradorXml);

            $template->setValue('cedula_cliente_comprador', $cedulaCompradorXml);
            $template->setValue('cedula_cliente', $cedulaCompradorXml);
            $template->setValue('ci_cliente', $cedulaCompradorXml);

            $template->setValue('direccion_cliente', $direccionCompradorXml);
            $template->setValue('dirección_cliente', $direccionCompradorXml);
            $template->setValue('direccion_cliente_comprador', $direccionCompradorXml);

            $template->setValue('telefono_cliente', $telefonoCompradorXml);
            $template->setValue('teléfono_cliente', $telefonoCompradorXml);
            $template->setValue('telefono cliente', $telefonoCompradorXml);
            $template->setValue('telefono_cliente_comprador', $telefonoCompradorXml);

            $template->setValue('correo_cliente', $correoCompradorXml);
            $template->setValue('correo_cliente_comprador', $correoCompradorXml);
            $template->setValue('estado_civil', $this->escaparXml($estadoCivilTexto));

            // C. Datos del Vendedor / Propietario Anterior (Modelos PODER)
            $datosVendedor = $this->resolverDatosClienteVendedor($venta, $datosComprador);
            $nombreVendedorXml = $this->escaparXml($datosVendedor['nombre_completo']);
            $cedulaVendedorXml = $this->escaparXml($datosVendedor['cedula']);

            $template->setValue('nombre_cliente_vendedor', $nombreVendedorXml);
            $template->setValue('cedula_cliente_vendedor', $cedulaVendedorXml);
            $template->setValue('vendedor_vehiculo_nombre', $nombreVendedorXml);
            $template->setValue('vendedor_vehiculo_cedula', $cedulaVendedorXml);

            // D. Datos del Vehículo
            $vehiculo = $venta->vehiculo;
            $marcaXml = $this->escaparXml((string) ($vehiculo?->marca?->nombre ?? ''));
            $modeloXml = $this->escaparXml((string) ($vehiculo?->modelo ?? ''));
            $anioVehiculoXml = $this->escaparXml((string) ($vehiculo?->anio ?? ''));
            $colorXml = $this->escaparXml((string) ($vehiculo?->color?->nombre ?? ''));
            $chasisXml = $this->escaparXml((string) ($vehiculo?->chasis ?? ''));
            $motorXml = $this->escaparXml((string) ($vehiculo?->motor ?? ''));
            $placaXml = $this->escaparXml((string) ($vehiculo?->id_vehiculo ?? $venta->id_vehiculo));

            $template->setValue('marca_vehiculo', $marcaXml);
            $template->setValue('modelo_vehiculo', $modeloXml);
            $template->setValue('modelo_vehiculo ', $modeloXml);
            $template->setValue('anio_vehiculo', $anioVehiculoXml);
            $template->setValue('anio_vehiculo ', $anioVehiculoXml);
            $template->setValue('color_vehiculo', $colorXml);
            $template->setValue('color_vehiculo ', $colorXml);
            $template->setValue('chasis_vehiculo', $chasisXml);
            $template->setValue('chasis_vehiculo ', $chasisXml);
            $template->setValue('motor_vehiculo', $motorXml);
            $template->setValue('motor_vehiculo ', $motorXml);
            $template->setValue('id_vehiculo', $placaXml);
            $template->setValue('tipo_contrato_vehiculo', $this->escaparXml($estrategia['tipo_contrato_nombre']));

            // E. Precio Acordado y Bloque Dinámico de Pagos (${bloque_pagos})
            $precioOperacion = round((float) ($venta->precio_acordado ?? $venta->precio_venta ?? $venta->precio_base ?? 0), 2);
            $precioFormateado = number_format($precioOperacion, 2, '.', ',');
            $precioLetras = $this->montoALetras($precioOperacion);

            $template->setValue('precio_acordado', $precioFormateado);
            $template->setValue('precio_acordado_letras', $this->escaparXml($precioLetras));
            $template->setValue('costo_adquisicion', $precioFormateado);
            $template->setValue('costo_letras', $this->escaparXml($precioLetras));

            $clausulasPagos = $this->construirClausulasBloquePagos($venta, $estrategia['modalidad'], $precioOperacion);
            $template->cloneBlock('bloque_pagos', 0, true, false, $clausulasPagos);

            $placaArchivo = preg_replace('/[^A-Z0-9]/i', '', (string) $venta->id_vehiculo) ?: (string) $venta->id_venta;
            $rutaRelativa = sprintf(
                'ventas/%d/constancias/constancia_%s_%s_%s_%s.docx',
                $venta->id_venta,
                strtolower($estrategia['categoria']),
                strtolower($estrategia['modalidad']),
                $placaArchivo,
                $ahora->format('Ymd_His'),
            );

            $rutaAbsoluta = Storage::disk('local')->path($rutaRelativa);
            $directorio = dirname($rutaAbsoluta);

            if (! is_dir($directorio) && ! mkdir($directorio, 0775, true) && ! is_dir($directorio)) {
                throw new RuntimeException('No se pudo crear el directorio privado para la constancia de venta.');
            }

            $template->saveAs($rutaAbsoluta);

            return $rutaRelativa;
        } finally {
            @unlink($plantillaTemporal);
        }
    }

    /**
     * Obtiene las cuotas/pagos registrados en `cuotas_credito` para la venta (tanto contado como crédito).
     *
     * @return Collection<int, CuotaCredito>
     */
    public function obtenerCuotasVenta(Venta $venta): Collection
    {
        $venta->loadMissing(['cuotasCredito.pagos', 'credito.cuotas.pagos']);

        if ($venta->credito !== null && $venta->credito->cuotas->isNotEmpty()) {
            return $venta->credito->cuotas;
        }

        if ($venta->cuotasCredito->isNotEmpty()) {
            return $venta->cuotasCredito;
        }

        return new Collection;
    }

    /**
     * Construye el arreglo de reemplazos para `$template->cloneBlock('bloque_pagos', 0, true, false, $clausulas)`.
     *
     * @return list<array{ordinal_pago: string, monto_pago_letras: string, monto_pago: string, detalle_pago: string}>
     */
    private function construirClausulasBloquePagos(Venta $venta, string $modalidad, float $precioVenta): array
    {
        $clausulas = [];
        $indiceOrdinal = 1;
        $cuotas = $this->obtenerCuotasVenta($venta)->sortBy('numero_cuota')->values();

        if ($modalidad === self::MODALIDAD_CREDITO && $venta->credito !== null) {
            $credito = $venta->credito;
            $montoInicial = round((float) $credito->monto_inicial, 2);

            // Si hubo entrada inicial antes del financiamiento, se lista primero como pago realizado
            if ($montoInicial > 0.009) {
                /** @var PagoVenta|null $pagoEntrada */
                $pagoEntrada = $venta->pagos->firstWhere('tipo_pago', 'entrada');
                $metodoEntrada = $pagoEntrada !== null ? 'transferencia' : 'efectivo';
                $clausulas[] = $this->crearItemClausulaPago(
                    $indiceOrdinal++,
                    $montoInicial,
                    $this->formatearDetallePagoRealizado($metodoEntrada, ''),
                );
            }

            $totalCuotas = max(1, $cuotas->count());
            $numeroPagare = str_pad((string) $credito->id_credito, 6, '0', STR_PAD_LEFT);

            foreach ($cuotas as $cuota) {
                $montoCuota = round((float) $cuota->valor_cuota, 2);
                if ($cuota->estado === 'pagada') {
                    $primerPago = $cuota->pagos->first();
                    $detalle = $this->formatearDetallePagoRealizado(
                        (string) ($primerPago?->metodo_pago ?? 'transferencia'),
                        (string) ($primerPago?->numero_referencia ?? ''),
                    );
                } else {
                    $fechaLetras = $cuota->fecha_vencimiento !== null
                        ? $this->fechaEnLetras(Carbon::parse($cuota->fecha_vencimiento))
                        : 'la fecha pactada';
                    $detalle = sprintf(
                        'va a cancelar el %s mediante cuota %d de %d según Pagaré No. %s',
                        $fechaLetras,
                        (int) $cuota->numero_cuota,
                        $totalCuotas,
                        $numeroPagare,
                    );
                }

                $clausulas[] = $this->crearItemClausulaPago($indiceOrdinal++, $montoCuota, $detalle);
            }

            if ($clausulas === []) {
                $clausulas[] = $this->crearItemClausulaPago(
                    1,
                    $precioVenta,
                    'va a cancelar cuando se realice el contrato de compra y venta',
                );
            }

            return $clausulas;
        }

        // Modalidad CONTADO (y abonos unificados en cuotas_credito)
        $sumaRegistrada = 0.0;

        if ($cuotas->isNotEmpty()) {
            foreach ($cuotas as $cuota) {
                $monto = round((float) $cuota->valor_cuota, 2);
                $sumaRegistrada = round($sumaRegistrada + $monto, 2);

                if ($cuota->estado === 'pagada') {
                    $primerPago = $cuota->pagos->first();
                    $detalle = $this->formatearDetallePagoRealizado(
                        (string) ($primerPago?->metodo_pago ?? 'transferencia'),
                        (string) ($primerPago?->numero_referencia ?? ''),
                    );
                } else {
                    $detalle = 'va a cancelar cuando se realice el contrato de compra y venta';
                }

                $clausulas[] = $this->crearItemClausulaPago($indiceOrdinal++, $monto, $detalle);
            }
        } elseif ($venta->pagos->isNotEmpty()) {
            // Respaldo para ventas históricas previas a la unificación en cuotas_credito
            foreach ($venta->pagos as $pago) {
                $monto = round((float) $pago->monto, 2);
                $sumaRegistrada = round($sumaRegistrada + $monto, 2);
                $clausulas[] = $this->crearItemClausulaPago(
                    $indiceOrdinal++,
                    $monto,
                    $this->formatearDetallePagoRealizado((string) $pago->tipo_pago, ''),
                );
            }
        }

        // Si en una operación de contado/cuotas quedó un saldo pendiente contra firma de contrato
        $saldoContraContrato = round($precioVenta - $sumaRegistrada, 2);
        if ($precioVenta > 0 && $saldoContraContrato > 0.009) {
            $clausulas[] = $this->crearItemClausulaPago(
                $indiceOrdinal++,
                $saldoContraContrato,
                'va a cancelar cuando se realice el contrato de compra y venta',
            );
        }

        if ($clausulas === []) {
            $clausulas[] = $this->crearItemClausulaPago(
                1,
                $precioVenta,
                $this->formatearDetallePagoRealizado('efectivo', ''),
            );
        }

        return $clausulas;
    }

    /**
     * @return array{ordinal_pago: string, monto_pago_letras: string, monto_pago: string, detalle_pago: string}
     */
    private function crearItemClausulaPago(int $numero, float $monto, string $detalle): array
    {
        return [
            'ordinal_pago' => $this->escaparXml($this->ordinalPago($numero)),
            'monto_pago_letras' => $this->escaparXml($this->montoALetras($monto)),
            'monto_pago' => number_format($monto, 2, '.', ','),
            'detalle_pago' => $this->escaparXml($detalle),
        ];
    }

    private function formatearDetallePagoRealizado(string $metodoPago, string $numeroComprobante): string
    {
        $metodo = strtolower(trim($metodoPago));
        $comprobante = trim($numeroComprobante);
        $cuenta = trim((string) config('app.cuenta_bancaria_concesionario', ''));
        $banco = trim((string) config('app.banco_concesionario', ''));
        $titular = trim((string) config('app.titular_cuenta_concesionario', 'HUGO DANIEL SORIA ROBALINO'));
        if ($titular === '') {
            $titular = 'HUGO DANIEL SORIA ROBALINO';
        }

        if ($metodo === 'efectivo') {
            return 'cancela en efectivo al momento de la suscripción de la presente acta';
        }

        if ($metodo === 'cheque') {
            return $comprobante !== ''
                ? "cancela con cheque a nombre de {$titular}, con numero de comprobante {$comprobante}"
                : "cancela con cheque a nombre de {$titular}";
        }

        $medioTexto = $metodo === 'deposito' ? 'depósito' : 'transferencia';

        if ($cuenta !== '' && $banco !== '') {
            $base = "cancela con {$medioTexto} a la cuenta {$cuenta} del {$banco} a nombre de {$titular}";
        } elseif ($banco !== '') {
            $base = "cancela con {$medioTexto} en {$banco} a nombre de {$titular}";
        } else {
            $base = "cancela con {$medioTexto} bancaria a nombre de {$titular}";
        }

        if ($comprobante !== '') {
            return "{$base}, con numero de comprobante {$comprobante}";
        }

        return $base;
    }

    /**
     * Crea una copia temporal limpia de la plantilla `.docx` corrigiendo automáticamente:
     * 1) Fragmentación XML (`<w:proofErr>`, `<w:r>` partidos en medio de `${...}`).
     * 2) Cierre faltante `${/bloque_pagos}` antes de `CUARTA` y párrafos limpios para `cloneBlock`.
     * 3) Palabra residual `QUINIENTOS ` después de `${precio_acordado_letras}` en `Contado_poder.docx`.
     * 4) Variantes de etiquetas con espacios/tildes (`${telefono cliente}`) y `, soltero,` dinámico.
     */
    private function prepararPlantillaNormalizada(string $origen, string $destino): void
    {
        if (! copy($origen, $destino)) {
            throw new RuntimeException('No se pudo copiar la plantilla de constancia a un archivo temporal.');
        }

        $zip = new ZipArchive;
        if ($zip->open($destino) !== true) {
            throw new RuntimeException('No se pudo abrir la copia temporal de la plantilla de constancia.');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nombre = $zip->getNameIndex($i);
                if (! is_string($nombre) || ! preg_match('/^word\/(document|header\d*|footer\d*)\.xml$/', $nombre)) {
                    continue;
                }

                $xmlOriginal = $zip->getFromName($nombre);
                if (! is_string($xmlOriginal) || $xmlOriginal === '') {
                    continue;
                }

                $xmlLimpio = $this->limpiarXmlWord($xmlOriginal, $nombre === 'word/document.xml');
                $zip->addFromString($nombre, $xmlLimpio);
            }
        } finally {
            $zip->close();
        }
    }

    private function limpiarXmlWord(string $xml, bool $esDocumentoPrincipal): string
    {
        $xml = preg_replace('/<w:proofErr\b[^>]*\/>/', '', $xml) ?? $xml;

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;

        if (! @$dom->loadXML($xml)) {
            return $xml;
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::WORD_NS);

        // 1. Desfragmenta etiquetas ${...} partidas en múltiples <w:t> dentro de cada párrafo
        foreach ($xpath->query('//w:p') as $parrafo) {
            if ($parrafo instanceof DOMElement) {
                $this->desfragmentarEtiquetasEnParrafo($xpath, $parrafo);
            }
        }

        if ($esDocumentoPrincipal) {
            $tieneAperturaBloque = false;
            $tieneCierreBloque = false;
            $parrafoCuarta = null;

            foreach ($xpath->query('//w:p') as $parrafo) {
                if (! $parrafo instanceof DOMElement) {
                    continue;
                }

                $textoParrafo = $this->obtenerTextoParrafo($xpath, $parrafo);
                $textoTrim = trim($textoParrafo);

                // 2. Elimina palabra residual "QUINIENTOS " junto a ${precio_acordado_letras}
                if (str_contains($textoParrafo, '${precio_acordado_letras}') && preg_match('/QUINIENTOS\s+DOLARES/iu', $textoParrafo)) {
                    $this->reemplazarTextoEnParrafo(
                        $xpath,
                        $parrafo,
                        '/(\$\{precio_acordado_letras\}\s*)QUINIENTOS\s+(DOLARES)/iu',
                        '$1$2',
                    );
                }

                // 3. Convierte ", soltero," fijo en el párrafo de comparecientes en ", ${estado_civil},"
                if (str_contains($textoParrafo, 'comparece') && preg_match('/,\s*soltero\s*,/iu', $textoParrafo)) {
                    $this->reemplazarTextoEnParrafo(
                        $xpath,
                        $parrafo,
                        '/,\s*soltero\s*,/iu',
                        ', ${estado_civil},',
                    );
                }

                // 4. Normaliza etiquetas con espacios internos como ${telefono cliente} y cierre de ${detalle_pago}
                if (str_contains($textoParrafo, '${telefono cliente}')) {
                    $this->reemplazarTextoEnParrafo(
                        $xpath,
                        $parrafo,
                        '/\$\{telefono\s+cliente\}/u',
                        '${telefono_cliente}',
                    );
                }

                if (str_contains($textoParrafo, '${detalle_pago}')) {
                    $this->reemplazarTextoEnParrafo(
                        $xpath,
                        $parrafo,
                        '/\$\{detalle_pago\}\s*,\s*a la presente acta\./iu',
                        '${detalle_pago}.',
                    );
                }

                if ($textoTrim === '${bloque_pagos}') {
                    $tieneAperturaBloque = true;
                    $this->reescribirParrafoMacroUnica($dom, $xpath, $parrafo, '${bloque_pagos}');
                } elseif ($textoTrim === '${/bloque_pagos}') {
                    $tieneCierreBloque = true;
                    $this->reescribirParrafoMacroUnica($dom, $xpath, $parrafo, '${/bloque_pagos}');
                } elseif ($parrafoCuarta === null && preg_match('/^CUARTA\b/iu', $textoTrim)) {
                    $parrafoCuarta = $parrafo;
                }
            }

            // 5. Inserta ${/bloque_pagos} justo antes de CUARTA si faltaba en el documento original
            if ($tieneAperturaBloque && ! $tieneCierreBloque && $parrafoCuarta instanceof DOMElement && $parrafoCuarta->parentNode !== null) {
                $nuevoP = $dom->createElementNS(self::WORD_NS, 'w:p');
                $nuevoR = $dom->createElementNS(self::WORD_NS, 'w:r');
                $nuevoT = $dom->createElementNS(self::WORD_NS, 'w:t', '${/bloque_pagos}');
                $nuevoR->appendChild($nuevoT);
                $nuevoP->appendChild($nuevoR);
                $parrafoCuarta->parentNode->insertBefore($nuevoP, $parrafoCuarta);
            }
        }

        return $dom->saveXML() ?: $xml;
    }

    private function obtenerTextoParrafo(DOMXPath $xpath, DOMElement $parrafo): string
    {
        $texto = '';
        foreach ($xpath->query('.//w:t', $parrafo) as $tNode) {
            $texto .= $tNode->nodeValue;
        }

        return $texto;
    }

    private function reemplazarTextoEnParrafo(
        DOMXPath $xpath,
        DOMElement $parrafo,
        string $patronRegex,
        string $reemplazo,
    ): void {
        $textNodes = [];
        foreach ($xpath->query('.//w:t', $parrafo) as $node) {
            if ($node instanceof DOMElement) {
                $textNodes[] = $node;
            }
        }

        if ($textNodes === []) {
            return;
        }

        $completo = '';
        foreach ($textNodes as $node) {
            $completo .= $node->nodeValue;
        }

        $nuevoTexto = preg_replace($patronRegex, $reemplazo, $completo);
        if (! is_string($nuevoTexto) || $nuevoTexto === $completo) {
            return;
        }

        $textNodes[0]->nodeValue = $nuevoTexto;
        $textNodes[0]->setAttribute('xml:space', 'preserve');
        for ($i = 1, $total = count($textNodes); $i < $total; $i++) {
            $textNodes[$i]->nodeValue = '';
        }
    }

    private function reescribirParrafoMacroUnica(
        DOMDocument $dom,
        DOMXPath $xpath,
        DOMElement $parrafo,
        string $macro,
    ): void {
        $pPr = $xpath->query('./w:pPr', $parrafo)->item(0);
        while ($parrafo->firstChild !== null) {
            $parrafo->removeChild($parrafo->firstChild);
        }
        if ($pPr !== null) {
            $parrafo->appendChild($pPr);
        }
        $run = $dom->createElementNS(self::WORD_NS, 'w:r');
        $text = $dom->createElementNS(self::WORD_NS, 'w:t', $macro);
        $run->appendChild($text);
        $parrafo->appendChild($run);
    }

    private function desfragmentarEtiquetasEnParrafo(DOMXPath $xpath, DOMElement $parrafo): void
    {
        $textNodes = [];
        foreach ($xpath->query('.//w:t', $parrafo) as $node) {
            if ($node instanceof DOMElement) {
                $textNodes[] = $node;
            }
        }

        if (count($textNodes) < 2) {
            return;
        }

        $textoCompleto = '';
        $rangos = [];
        foreach ($textNodes as $idx => $node) {
            $inicio = strlen($textoCompleto);
            $textoCompleto .= $node->nodeValue;
            $rangos[$idx] = [$inicio, strlen($textoCompleto)];
        }

        if (! preg_match_all('/\$\{[^}]+\}/u', $textoCompleto, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[0] as [$etiqueta, $offsetInicio]) {
            $offsetFin = $offsetInicio + strlen($etiqueta);
            $nodosAfectados = [];

            foreach ($rangos as $idx => [$inicio, $fin]) {
                if ($fin > $offsetInicio && $inicio < $offsetFin) {
                    $nodosAfectados[] = $idx;
                }
            }

            if (count($nodosAfectados) <= 1) {
                continue;
            }

            $primero = $nodosAfectados[0];
            $ultimo = $nodosAfectados[count($nodosAfectados) - 1];

            $prefijo = substr($textoCompleto, $rangos[$primero][0], $offsetInicio - $rangos[$primero][0]);
            $sufijo = substr($textoCompleto, $offsetFin, $rangos[$ultimo][1] - $offsetFin);

            $textNodes[$primero]->nodeValue = $prefijo.$etiqueta.$sufijo;
            $textNodes[$primero]->setAttribute('xml:space', 'preserve');

            for ($k = 1, $totalAfectados = count($nodosAfectados); $k < $totalAfectados; $k++) {
                $textNodes[$nodosAfectados[$k]]->nodeValue = '';
            }

            $textoCompleto = '';
            foreach ($textNodes as $idx => $node) {
                $inicio = strlen($textoCompleto);
                $textoCompleto .= $node->nodeValue;
                $rangos[$idx] = [$inicio, strlen($textoCompleto)];
            }
        }
    }

    private function normalizarTextoComparacion(string $texto): string
    {
        $min = mb_strtolower(trim($texto), 'UTF-8');
        $sinTildes = strtr($min, [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
        ]);

        return preg_replace('/\s+/', ' ', $sinTildes) ?? $sinTildes;
    }

    private function escaparXml(string $valor): string
    {
        return htmlspecialchars($valor, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function nombreMesEspanol(int $mes): string
    {
        $meses = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
            5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ];

        return $meses[$mes] ?? 'enero';
    }

    private function fechaEnLetras(Carbon $fecha): string
    {
        $dia = $this->numeroALetras((int) $fecha->format('j'));
        $mes = $this->nombreMesEspanol((int) $fecha->format('n'));
        $anio = $this->numeroALetras((int) $fecha->format('Y'));

        return "{$dia} ({$fecha->format('d')}) de {$mes} del año {$anio} ({$fecha->format('Y')})";
    }

    private function ordinalPago(int $numero): string
    {
        $especiales = [
            1 => 'Uno',
            2 => 'Dos',
            3 => 'Tres',
            4 => 'Cuatro',
            5 => 'Cinco',
            6 => 'Seis',
            7 => 'Siete',
            8 => 'Ocho',
            9 => 'Nueve',
            10 => 'Diez',
        ];

        if (isset($especiales[$numero])) {
            return $especiales[$numero];
        }

        $letras = $this->numeroALetras($numero);

        return mb_strtoupper(mb_substr($letras, 0, 1, 'UTF-8'), 'UTF-8').mb_substr($letras, 1, null, 'UTF-8');
    }

    private function montoALetras(float $monto): string
    {
        $abs = abs($monto);
        $entero = (int) floor($abs);
        $centavos = (int) round(($abs - $entero) * 100);
        if ($centavos >= 100) {
            $entero++;
            $centavos = 0;
        }

        $textoEntero = '';
        if (class_exists(NumberFormatter::class)) {
            try {
                $formatter = new NumberFormatter('es', NumberFormatter::SPELLOUT);
                $formateado = $formatter->format($entero);
                if (is_string($formateado) && trim($formateado) !== '') {
                    $textoEntero = preg_replace('/\buno mil\b/iu', 'un mil', $formateado) ?? $formateado;
                    $textoEntero = preg_replace('/\bveintiún\b/iu', 'veintiuno', $textoEntero) ?? $textoEntero;
                }
            } catch (\Throwable) {
                $textoEntero = '';
            }
        }

        if ($textoEntero === '') {
            $textoEntero = $this->numeroALetras($entero);
        }

        $mayusculas = mb_strtoupper(trim($textoEntero), 'UTF-8');

        return $centavos > 0
            ? sprintf('%s CON %02d/100', $mayusculas, $centavos)
            : $mayusculas;
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
