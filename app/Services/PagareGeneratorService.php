<?php

namespace App\Services;

use App\Models\Credito;
use App\Models\CuotaCredito;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use NumberFormatter;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use Throwable;
use ZipArchive;

class PagareGeneratorService
{
    public const PLANTILLA_RELATIVA = 'app/plantillas/Optimus_Pagare_Base_Casabaca 2.docx';

    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * Genera el Pagaré a la Orden y Tabla de Amortización (.docx) en el disco privado `local`
     * y devuelve la ruta relativa almacenada.
     */
    public function generar(Credito $credito): string
    {
        $plantillaOriginal = storage_path(self::PLANTILLA_RELATIVA);
        if (! is_file($plantillaOriginal)) {
            throw new RuntimeException('No se encontró la plantilla del pagaré en storage/'.self::PLANTILLA_RELATIVA);
        }

        $credito->loadMissing([
            'cliente',
            'venta.vehiculo.marca',
            'venta.vehiculo.color',
            'vehiculo.marca',
            'vehiculo.color',
            'tasaInteres',
            'cuotas',
        ]);

        $token = bin2hex(random_bytes(12));
        $plantillaTemporal = storage_path("app/pagare_tpl_{$token}.docx");
        $salidaTemporal = storage_path("app/pagare_out_{$token}.docx");

        try {
            $this->prepararPlantillaNormalizada($plantillaOriginal, $plantillaTemporal);

            $template = new TemplateProcessor($plantillaTemporal);

            $venta = $credito->venta;
            $cliente = $credito->cliente ?? $venta?->cliente;
            $tasa = $credito->tasaInteres;
            $datosCedulaVenta = is_array($venta?->datos_cedula_comprador) ? $venta->datos_cedula_comprador : [];

            $numeroPagare = str_pad((string) $credito->id_credito, 6, '0', STR_PAD_LEFT);
            $nombreCliente = $this->resolverNombreCliente($cliente?->nombre, $cliente?->apellido, $datosCedulaVenta);
            $cedulaCliente = trim((string) ($cliente?->cedula ?? $datosCedulaVenta['cedula'] ?? 'POR REGISTRAR'));
            $correoCliente = trim((string) ($cliente?->correo ?? $datosCedulaVenta['correo'] ?? ''));
            $correoCliente = $correoCliente !== '' ? $correoCliente : 'No registrado';
            $direccionCliente = trim((string) ($cliente?->direccion ?? $datosCedulaVenta['direccion'] ?? ''));
            $direccionCliente = $direccionCliente !== '' ? $direccionCliente : 'No registrada';

            $correoBeneficiario = trim((string) (config('app.correo_beneficiario_pagare') ?: config('mail.from.address', 'gerencia@optimusautos.ec')));
            if ($correoBeneficiario === '') {
                $correoBeneficiario = 'gerencia@optimusautos.ec';
            }

            $montoFinanciado = round((float) $credito->monto_financiado, 2);
            $montoFinanciadoFormateado = number_format($montoFinanciado, 2, '.', ',');
            $montoFinanciadoLetras = $this->montoALetras($montoFinanciado);

            $cuotas = $credito->cuotas->sortBy('numero_cuota')->values();
            $totalPagarCronograma = $cuotas->isNotEmpty()
                ? round((float) $cuotas->sum('valor_cuota'), 2)
                : round((float) $credito->saldo_pendiente, 2);

            $tasaMensualVal = $tasa !== null ? (float) $tasa->tasa_mensual : 1.70;
            $tasaMes13Val = $tasa !== null && $tasa->tasa_mensual_desde_mes_13 !== null
                ? (float) $tasa->tasa_mensual_desde_mes_13
                : 1.50;
            $tasaNominalVal = $tasa !== null ? (float) ($tasa->tasa_nominal_anual ?? $tasa->tasa_anual ?? 20.40) : 20.40;
            $tasaEfectivaVal = $tasa !== null ? (float) ($tasa->tasa_efectiva_anual ?? 22.42) : 22.42;

            $etiquetaTasaMensual = ($cuotas->count() > 12 && $tasaMes13Val > 0 && abs($tasaMes13Val - $tasaMensualVal) > 0.001)
                ? sprintf('%s%% (mes 1-12) / %s%% (mes 13+)', number_format($tasaMensualVal, 2, '.', ','), number_format($tasaMes13Val, 2, '.', ','))
                : number_format($tasaMensualVal, 2, '.', ',').'%';

            $fechaHoyTexto = $this->formatearFechaEspanol(Carbon::now('America/Guayaquil'));

            // A. Variables simples (Cabecera, Cuerpo Legal, Firmas y Pie de Página)
            $template->setValue('id_credito', $numeroPagare);
            $template->setValue('nombre_cliente', $nombreCliente);
            $template->setValue('cedula_cliente', $cedulaCliente);
            $template->setValue('correo_cliente', $correoCliente);
            $template->setValue('direccion_cliente', $direccionCliente);
            $template->setValue('dirección_cliente', $direccionCliente);
            $template->setValue('monto_financiado', $montoFinanciadoFormateado);
            $template->setValue('monto_financiado_letras', $montoFinanciadoLetras);
            $template->setValue('fecha_hoy', $fechaHoyTexto);
            $template->setValue('correo_beneficiario', $correoBeneficiario);

            // B. Resumen Financiero y Tasas
            $template->setValue('tabla_cuotas', '');
            $template->setValue('tasa_mensual', $etiquetaTasaMensual);
            $template->setValue('saldo_pendiente', number_format($totalPagarCronograma, 2, '.', ','));
            $template->setValue('tasa_nominal_anual', number_format($tasaNominalVal, 2, '.', ',').'%');
            $template->setValue('tasa_efectiva_anual', number_format($tasaEfectivaVal, 2, '.', ',').'%');

            // C. Tabla Dinámica de Amortización (8 columnas con cloneRow)
            $this->llenarTablaAmortizacion($template, $credito, $cuotas, $montoFinanciado);

            $placaLimpia = preg_replace('/[^A-Z0-9]/i', '', (string) ($credito->id_vehiculo ?? $venta?->id_vehiculo ?? '')) ?: (string) $credito->id_venta;
            $rutaRelativa = sprintf(
                'documentos/pagares/pagare_credito_%s_%d_%s.docx',
                $placaLimpia,
                $credito->id_credito,
                Carbon::now('America/Guayaquil')->format('Ymd_His'),
            );
            $template->saveAs($salidaTemporal);
            $contenidoDocx = file_get_contents($salidaTemporal);
            if ($contenidoDocx === false || ! Storage::disk(config('filesystems.default'))->put($rutaRelativa, $contenidoDocx)) {
                throw new RuntimeException('No se pudo subir el pagaré generado al almacenamiento configurado.');
            }

            return $rutaRelativa;
        } finally {
            @unlink($plantillaTemporal);
            @unlink($salidaTemporal);
        }
    }

    /**
     * Genera el nuevo pagaré, actualiza `creditos.ruta_pagare` y elimina el archivo anterior del disco privado `local`.
     */
    public function regenerarYGuardar(Credito $credito): ?string
    {
        try {
            $rutaAnterior = $credito->ruta_pagare;
            $rutaNueva = $this->generar($credito);

            $credito->update(['ruta_pagare' => $rutaNueva]);

            if (is_string($rutaAnterior)
                && $rutaAnterior !== ''
                && $rutaAnterior !== $rutaNueva
                && Storage::disk(config('filesystems.default'))->exists($rutaAnterior)) {
                Storage::disk(config('filesystems.default'))->delete($rutaAnterior);
            }

            return $rutaNueva;
        } catch (Throwable $exception) {
            Log::warning('Error al generar el pagaré Word privado con PagareGeneratorService.', [
                'id_credito' => $credito->id_credito,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Llena la tabla de 8 columnas mediante `cloneRow` y calcula la fila de totales.
     *
     * @param  \Illuminate\Support\Collection<int, CuotaCredito>  $cuotas
     */
    private function llenarTablaAmortizacion(
        TemplateProcessor $template,
        Credito $credito,
        \Illuminate\Support\Collection $cuotas,
        float $montoFinanciado,
    ): void {
        $totalCuotas = $cuotas->count();
        if ($totalCuotas === 0) {
            $template->setValue('numero_cuota', '-');
            $template->setValue('fecha_vencimiento', '-');
            $template->setValue('calculo_dias', '0');
            $template->setValue('cap_inicial', number_format($montoFinanciado, 2, '.', ','));
            $template->setValue('pago_mensual', '0.00');
            $template->setValue('valor_capital', '0.00');
            $template->setValue('interes', '0.00');
            $template->setValue('capital_final', number_format($montoFinanciado, 2, '.', ','));
            $template->setValue('total_pago_mensual', '0.00');
            $template->setValue('total_valor_capital', '0.00');
            $template->setValue('total_interes', '0.00');

            return;
        }

        $template->cloneRow('numero_cuota', $totalCuotas);

        $fechaAnterior = $credito->fecha_inicio !== null
            ? Carbon::parse($credito->fecha_inicio)->startOfDay()
            : Carbon::now('America/Guayaquil')->startOfDay();

        $capInicial = $montoFinanciado;
        $totalPagoMensual = 0.0;
        $totalValorCapital = 0.0;
        $totalInteres = 0.0;

        foreach ($cuotas as $idx => $cuota) {
            $fila = $idx + 1;
            $esUltima = ($fila === $totalCuotas);

            $fechaVenc = $cuota->fecha_vencimiento !== null
                ? Carbon::parse($cuota->fecha_vencimiento)->startOfDay()
                : $fechaAnterior->copy()->addMonthNoOverflow();

            $dias = (int) abs($fechaAnterior->diffInDays($fechaVenc, false));
            $pagoMensual = round((float) $cuota->valor_cuota, 2);
            $valorCapital = round((float) $cuota->monto_capital, 2);
            $interes = round((float) $cuota->monto_interes, 2);

            if ($esUltima && $capInicial > 0 && abs($capInicial - $valorCapital) <= 0.05) {
                $valorCapital = $capInicial;
            }

            $capitalFinal = $esUltima
                ? 0.0
                : max(0.0, round($capInicial - $valorCapital, 2));

            $template->setValue("numero_cuota#{$fila}", (string) $cuota->numero_cuota);
            $template->setValue("fecha_vencimiento#{$fila}", $fechaVenc->format('d/m/Y'));
            $template->setValue("calculo_dias#{$fila}", (string) $dias);
            $template->setValue("cap_inicial#{$fila}", number_format($capInicial, 2, '.', ','));
            $template->setValue("pago_mensual#{$fila}", number_format($pagoMensual, 2, '.', ','));
            $template->setValue("valor_capital#{$fila}", number_format($valorCapital, 2, '.', ','));
            $template->setValue("interes#{$fila}", number_format($interes, 2, '.', ','));
            $template->setValue("capital_final#{$fila}", number_format($capitalFinal, 2, '.', ','));

            $totalPagoMensual = round($totalPagoMensual + $pagoMensual, 2);
            $totalValorCapital = round($totalValorCapital + $valorCapital, 2);
            $totalInteres = round($totalInteres + $interes, 2);

            $capInicial = $capitalFinal;
            $fechaAnterior = $fechaVenc;
        }

        $template->setValue('total_pago_mensual', number_format($totalPagoMensual, 2, '.', ','));
        $template->setValue('total_valor_capital', number_format($totalValorCapital, 2, '.', ','));
        $template->setValue('total_interes', number_format($totalInteres, 2, '.', ','));
    }

    /**
     * Crea una copia temporal limpia de la plantilla `.docx`:
     * 1) Desfragmenta etiquetas `${...}` y `[...]` partidas por `<w:proofErr>` / `<w:r>` en documento, headers y footers.
     * 2) Reemplaza corchetes fijos (`[VALOR NUMÉRICO]`, `[CORREO]`).
     * 3) Normaliza la fila clonable de cuotas (8 columnas) y la fila de totales (`[TOTAL]`) preservando el formato visual.
     */
    private function prepararPlantillaNormalizada(string $origen, string $destino): void
    {
        if (! copy($origen, $destino)) {
            throw new RuntimeException('No se pudo copiar la plantilla del pagaré a un archivo temporal.');
        }

        $zip = new ZipArchive;
        if ($zip->open($destino) !== true) {
            throw new RuntimeException('No se pudo abrir la copia temporal de la plantilla del pagaré.');
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
        // Quita marcas de corrector ortográfico que Word inserta en medio de ${variable}
        $xml = preg_replace('/<w:proofErr\b[^>]*\/>/', '', $xml) ?? $xml;

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;

        if (! @$dom->loadXML($xml)) {
            return $xml;
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::WORD_NS);

        // 1. Desfragmenta cualquier etiqueta ${...} o [...] dividida en múltiples <w:t> dentro de cada párrafo
        foreach ($xpath->query('//w:p') as $parrafo) {
            if ($parrafo instanceof DOMElement) {
                $this->desfragmentarEtiquetasEnParrafo($xpath, $parrafo);
            }
        }

        // 2. Normaliza corchetes residuales en todo el XML
        foreach ($xpath->query('//w:t') as $tNode) {
            if ($tNode instanceof DOMElement) {
                $val = $tNode->nodeValue;
                if (str_contains($val, '[VALOR NUMÉRICO]') || str_contains($val, '[VALOR NUMERICO]')) {
                    $val = str_replace(['[VALOR NUMÉRICO]', '[VALOR NUMERICO]'], '${monto_financiado}', $val);
                    $tNode->nodeValue = $val;
                }
                if (str_contains($val, '[CORREO]')) {
                    $val = str_replace('[CORREO]', '${correo_beneficiario}', $val);
                    $tNode->nodeValue = $val;
                }
            }
        }

        // 3. En el documento principal, normaliza la fila clonable (8 columnas) y la fila de totales de la tabla de amortización
        if ($esDocumentoPrincipal) {
            $this->normalizarTablaAmortizacionXml($xpath);
        }

        return $dom->saveXML() ?: $xml;
    }

    private function desfragmentarEtiquetasEnParrafo(DOMXPath $xpath, DOMElement $parrafo): void
    {
        $textNodes = [];
        foreach ($xpath->query('.//w:t', $parrafo) as $node) {
            if ($node instanceof DOMElement) {
                $textNodes[] = $node;
            }
        }

        $count = count($textNodes);
        if ($count < 2) {
            return;
        }

        $textoCompleto = '';
        $rangos = [];
        foreach ($textNodes as $idx => $node) {
            $inicio = strlen($textoCompleto);
            $textoCompleto .= $node->nodeValue;
            $fin = strlen($textoCompleto);
            $rangos[$idx] = [$inicio, $fin];
        }

        if (! preg_match_all('/\$\{[^}]+\}|\[[^\]]+\]/u', $textoCompleto, $matches, PREG_OFFSET_CAPTURE)) {
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

            for ($k = 1; $k < count($nodosAfectados); $k++) {
                $textNodes[$nodosAfectados[$k]]->nodeValue = '';
            }

            // Reconstruimos texto y rangos por si hay más etiquetas fragmentadas en el mismo párrafo
            $textoCompleto = '';
            foreach ($textNodes as $idx => $node) {
                $inicio = strlen($textoCompleto);
                $textoCompleto .= $node->nodeValue;
                $rangos[$idx] = [$inicio, strlen($textoCompleto)];
            }
        }
    }

    private function normalizarTablaAmortizacionXml(DOMXPath $xpath): void
    {
        $placeholdersFilaCuota = [
            '${numero_cuota}',
            '${fecha_vencimiento}',
            '${calculo_dias}',
            '${cap_inicial}',
            '${pago_mensual}',
            '${valor_capital}',
            '${interes}',
            '${capital_final}',
        ];

        $placeholdersTotales = [
            4 => '${total_pago_mensual}',
            5 => '${total_valor_capital}',
            6 => '${total_interes}',
        ];

        foreach ($xpath->query('//w:tbl') as $tbl) {
            $filas = $xpath->query('./w:tr', $tbl);
            if ($filas === false || $filas->length < 3) {
                continue;
            }

            $indiceCabecera = null;
            foreach ($filas as $rIdx => $tr) {
                $textoFila = mb_strtoupper(trim($tr->textContent), 'UTF-8');
                if (str_contains($textoFila, 'CUOTA') && str_contains($textoFila, 'VENCE EN') && str_contains($textoFila, 'CAPITAL FINAL')) {
                    $indiceCabecera = $rIdx;
                    break;
                }
            }

            if ($indiceCabecera === null) {
                continue;
            }

            // Fila inmediatamente siguiente a la cabecera = fila clonable de cuotas (8 columnas)
            $filaCuota = $filas->item($indiceCabecera + 1);
            if ($filaCuota instanceof DOMElement) {
                $celdas = $xpath->query('./w:tc', $filaCuota);
                if ($celdas !== false && $celdas->length >= 8) {
                    for ($c = 0; $c < 8; $c++) {
                        $celda = $celdas->item($c);
                        if ($celda instanceof DOMElement) {
                            $this->reemplazarTextoCelda($xpath, $celda, $placeholdersFilaCuota[$c]);
                        }
                    }
                }
            }

            // Fila siguiente a la fila de cuotas = fila de TOTALES
            $filaTotales = $filas->item($indiceCabecera + 2);
            if ($filaTotales instanceof DOMElement) {
                $celdasTotales = $xpath->query('./w:tc', $filaTotales);
                if ($celdasTotales !== false) {
                    foreach ($placeholdersTotales as $colIdx => $placeholder) {
                        $celda = $celdasTotales->item($colIdx);
                        if ($celda instanceof DOMElement) {
                            $this->reemplazarTextoCelda($xpath, $celda, $placeholder);
                        }
                    }
                }
            }

            break;
        }
    }

    private function reemplazarTextoCelda(DOMXPath $xpath, DOMElement $celda, string $nuevoTexto): void
    {
        $nodosT = $xpath->query('.//w:t', $celda);
        if ($nodosT !== false && $nodosT->length > 0) {
            $primero = $nodosT->item(0);
            if ($primero instanceof DOMElement) {
                $primero->nodeValue = $nuevoTexto;
            }
            for ($i = 1; $i < $nodosT->length; $i++) {
                $otro = $nodosT->item($i);
                if ($otro instanceof DOMElement) {
                    $otro->nodeValue = '';
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $datosCedulaVenta
     */
    private function resolverNombreCliente(?string $nombre, ?string $apellido, array $datosCedulaVenta): string
    {
        $combinado = trim(trim((string) $nombre).' '.trim((string) $apellido));
        $combinado = trim(preg_replace('/\s+-\s*$/', '', $combinado) ?? $combinado);

        if ($combinado !== '' && $combinado !== '-') {
            return mb_strtoupper($combinado, 'UTF-8');
        }

        $desdeVenta = trim((string) ($datosCedulaVenta['nombre_completo'] ?? ''));

        return $desdeVenta !== '' ? mb_strtoupper($desdeVenta, 'UTF-8') : 'PENDIENTE DE REGISTRO';
    }

    private function formatearFechaEspanol(Carbon $fecha): string
    {
        $meses = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
            5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ];

        $dia = (int) $fecha->format('j');
        $mes = $meses[(int) $fecha->format('n')] ?? $fecha->format('F');
        $anio = (int) $fecha->format('Y');

        return "{$dia} de {$mes} de {$anio}";
    }

    public function montoALetras(float $monto): string
    {
        $entero = (int) floor(abs($monto));
        $centavos = (int) round((abs($monto) - $entero) * 100);
        if ($centavos >= 100) {
            $entero++;
            $centavos = 0;
        }

        $textoEntero = null;
        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter('es', NumberFormatter::SPELLOUT);
            $formateado = $formatter->format($entero);
            if (is_string($formateado) && trim($formateado) !== '') {
                $textoEntero = mb_strtoupper(trim($formateado), 'UTF-8');
            }
        }

        if ($textoEntero === null) {
            $textoEntero = mb_strtoupper($this->numeroALetrasFallback($entero), 'UTF-8');
        }

        return sprintf('%s CON %02d/100', $textoEntero, $centavos);
    }

    private function numeroALetrasFallback(int $numero): string
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

            return $r === 0 ? $centenas[$c] : "{$centenas[$c]} {$this->numeroALetrasFallback($r)}";
        }

        if ($numero < 1000000) {
            $miles = intdiv($numero, 1000);
            $r = $numero % 1000;
            $prefijo = $miles === 1 ? 'mil' : $this->numeroALetrasFallback($miles).' mil';
            $prefijo = preg_replace('/uno mil$/', 'un mil', $prefijo) ?? $prefijo;

            return $r === 0 ? $prefijo : "{$prefijo} {$this->numeroALetrasFallback($r)}";
        }

        $millones = intdiv($numero, 1000000);
        $r = $numero % 1000000;
        $prefijo = $millones === 1 ? 'un millón' : $this->numeroALetrasFallback($millones).' millones';

        return $r === 0 ? $prefijo : "{$prefijo} {$this->numeroALetrasFallback($r)}";
    }
}
