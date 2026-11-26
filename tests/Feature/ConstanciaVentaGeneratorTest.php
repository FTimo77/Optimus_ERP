<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Credito;
use App\Models\CuotaCredito;
use App\Models\Estado_vehiculo;
use App\Models\PagoCredito;
use App\Models\TipoCliente;
use App\Models\TipoContrato;
use App\Models\User;
use App\Models\Vehiculo;
use App\Models\Venta;
use App\Services\ConstanciaVentaGeneratorService;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ConstanciaVentaGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function crearVehiculoBase(string $placa, string $nombreTipoContrato, ?Cliente $vendedor = null): Vehiculo
    {
        $ahora = now();
        $estado = Estado_vehiculo::firstOrCreate(['nombre' => 'Vendido']);
        $tipoContrato = TipoContrato::firstOrCreate(
            ['nombre' => $nombreTipoContrato],
            ['requiere_documento' => true, 'cantidad_documentos' => 1],
        );

        $catalogos = [
            'marca_vehiculos' => ['id_marca', 'Toyota'],
            'clase_vehiculos' => ['id_clase', 'Camioneta'],
            'paises' => ['id_pais', 'Japon'],
            'color_vehiculos' => ['id_color', 'Plata'],
            'tipo_combustibles' => ['id_tipo_combustible', 'Diesel'],
            'transmisiones' => ['id_transmision', 'Manual'],
            'patios' => ['id_patio', 'Patio Matriz'],
        ];
        $ids = [];
        foreach ($catalogos as $tabla => [$pk, $nombre]) {
            $ids[$pk] = DB::table($tabla)->insertGetId([
                'nombre' => $nombre,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ], $pk);
        }

        $idProveedor = DB::table('proveedores')->insertGetId([
            'nombre' => 'Proveedor Base',
            'cedula' => '18000000'.random_int(10, 99),
            'correo' => 'prov_'.strtolower($placa).'@optimus.test',
            'direccion' => 'Ambato',
            'ruta_cedula' => 'documentos/cedulas/prov.pdf',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ], 'id_proveedor');

        return Vehiculo::create([
            'id_vehiculo' => $placa,
            'id_marca' => $ids['id_marca'],
            'id_clase' => $ids['id_clase'],
            'id_pais' => $ids['id_pais'],
            'id_color' => $ids['id_color'],
            'id_tipo_combustible' => $ids['id_tipo_combustible'],
            'id_transmision' => $ids['id_transmision'],
            'id_patio' => $ids['id_patio'],
            'id_estado' => $estado->id_estado,
            'id_proveedor' => $idProveedor,
            'id_tipo_contrato' => $tipoContrato->id_tipo_contrato,
            'id_cliente_vendedor' => $vendedor?->id_cliente,
            'modelo' => 'Hilux CD 4x4',
            'anio' => 2023,
            'motor' => 'MOT-'.$placa,
            'chasis' => 'CHS-'.$placa,
            'cilindraje' => '2800',
            'kilometraje' => 24000,
            'costo_adquisicion' => 12000.00,
            'precio_venta' => 14000.00,
            'precio_final' => 13500.00,
        ]);
    }

    public function test_genera_los_cuatro_modelos_de_constancia_sin_etiquetas_pendientes(): void
    {
        Storage::fake('local');
        $usuario = User::factory()->create();

        $comprador = Cliente::sincronizarConRol('1804455667', [
            'nombre_completo' => 'MARIA FERNANDA LOPEZ TORRES',
            'direccion' => 'Ambato, Av. Cevallos y Lalama',
            'telefono' => '0998765432',
            'correo' => 'maria.lopez@correo.ec',
        ], TipoCliente::COMPRADOR);

        $vendedorOriginal = Cliente::sincronizarConRol('1711223344', [
            'nombre_completo' => 'CARLOS ANDRES PEREZ GOMEZ',
            'telefono' => '0981122334',
            'direccion' => 'Quito',
        ], TipoCliente::VENDEDOR);

        $escenarios = [
            ['placa' => 'TBA1001', 'tipo_contrato' => 'Doble Contrato', 'forma_pago' => 'contado', 'esperado' => 'Contado_propio.docx'],
            ['placa' => 'TBA1002', 'tipo_contrato' => 'Propietario Optimus', 'forma_pago' => 'credito', 'esperado' => 'Credito-Propio.docx'],
            ['placa' => 'TBA1003', 'tipo_contrato' => 'Poder', 'forma_pago' => 'contado', 'esperado' => 'Contado_poder.docx'],
            ['placa' => 'TBA1004', 'tipo_contrato' => 'Contrato de mandato', 'forma_pago' => 'credito', 'esperado' => 'credito_poder.docx'],
        ];

        $servicio = app(ConstanciaVentaGeneratorService::class);

        foreach ($escenarios as $cfg) {
            $vehiculo = $this->crearVehiculoBase($cfg['placa'], $cfg['tipo_contrato'], $vendedorOriginal);

            $venta = Venta::create([
                'id_cliente' => $comprador->id_cliente,
                'id_vehiculo' => $vehiculo->id_vehiculo,
                'id_user' => $usuario->id,
                'tipo_operacion' => 'venta',
                'forma_pago' => $cfg['forma_pago'],
                'precio_base' => 14000.00,
                'precio_acordado' => 14000.00,
                'precio_venta' => 14000.00,
                'fecha_venta' => '2026-11-25',
                'estado' => 'pagada',
                'datos_cedula_comprador' => [
                    'nombre_completo' => 'MARIA FERNANDA LOPEZ TORRES',
                    'cedula' => '1804455667',
                    'direccion' => 'Ambato, Av. Cevallos y Lalama',
                    'telefono' => '0998765432',
                    'correo' => 'maria.lopez@correo.ec',
                    'estado_civil' => 'CASADO',
                    'es_casado' => true,
                ],
            ]);

            if ($cfg['forma_pago'] === 'contado') {
                $cabecera = Credito::create([
                    'id_cliente' => $comprador->id_cliente,
                    'id_venta' => $venta->id_venta,
                    'id_vehiculo' => $vehiculo->id_vehiculo,
                    'modalidad' => 'contado',
                    'monto_total' => 14000.00,
                    'monto_inicial' => 14000.00,
                    'monto_financiado' => 0.00,
                    'saldo_pendiente' => 0.00,
                    'numero_cuotas' => 2,
                    'estado' => 'pagado',
                ]);

                $c1 = CuotaCredito::create([
                    'id_credito' => $cabecera->id_credito,
                    'id_venta' => $venta->id_venta,
                    'numero_cuota' => 1,
                    'fecha_vencimiento' => '2026-11-25',
                    'monto_capital' => 10000.00,
                    'valor_cuota' => 10000.00,
                    'monto_pagado' => 10000.00,
                    'fecha_pago_real' => '2026-11-25',
                    'estado' => 'pagada',
                ]);
                PagoCredito::create([
                    'id_credito' => $cabecera->id_credito,
                    'id_cuota_credito' => $c1->id_cuota_credito,
                    'monto_pagado' => 10000.00,
                    'metodo_pago' => 'transferencia',
                    'numero_referencia' => 'TRF-778899',
                    'fecha_pago' => '2026-11-25',
                ]);

                $c2 = CuotaCredito::create([
                    'id_credito' => $cabecera->id_credito,
                    'id_venta' => $venta->id_venta,
                    'numero_cuota' => 2,
                    'fecha_vencimiento' => '2026-11-25',
                    'monto_capital' => 4000.00,
                    'valor_cuota' => 4000.00,
                    'monto_pagado' => 4000.00,
                    'fecha_pago_real' => '2026-11-25',
                    'estado' => 'pagada',
                ]);
                PagoCredito::create([
                    'id_credito' => $cabecera->id_credito,
                    'id_cuota_credito' => $c2->id_cuota_credito,
                    'monto_pagado' => 4000.00,
                    'metodo_pago' => 'efectivo',
                    'fecha_pago' => '2026-11-25',
                ]);
            } else {
                $credito = Credito::create([
                    'id_cliente' => $comprador->id_cliente,
                    'id_venta' => $venta->id_venta,
                    'id_vehiculo' => $vehiculo->id_vehiculo,
                    'modalidad' => 'credito',
                    'monto_total' => 14000.00,
                    'monto_inicial' => 4000.00,
                    'monto_financiado' => 10000.00,
                    'saldo_pendiente' => 10340.00,
                    'numero_cuotas' => 2,
                    'estado' => 'activo',
                ]);

                CuotaCredito::create([
                    'id_credito' => $credito->id_credito,
                    'id_venta' => $venta->id_venta,
                    'numero_cuota' => 1,
                    'fecha_vencimiento' => '2026-12-25',
                    'monto_capital' => 5000.00,
                    'monto_interes' => 170.00,
                    'valor_cuota' => 5170.00,
                    'monto_pagado' => 0.00,
                    'estado' => 'pendiente',
                ]);
                CuotaCredito::create([
                    'id_credito' => $credito->id_credito,
                    'id_venta' => $venta->id_venta,
                    'numero_cuota' => 2,
                    'fecha_vencimiento' => '2027-01-25',
                    'monto_capital' => 5000.00,
                    'monto_interes' => 170.00,
                    'valor_cuota' => 5170.00,
                    'monto_pagado' => 0.00,
                    'estado' => 'pendiente',
                ]);
            }

            $estrategia = $servicio->resolverEstrategia($venta);
            $this->assertSame($cfg['esperado'], $estrategia['archivo_plantilla']);

            $ruta = $servicio->generar($venta, $venta->datos_cedula_comprador);
            $this->assertStringStartsWith("ventas/{$venta->id_venta}/constancias/constancia_", $ruta);
            $this->assertTrue(Storage::disk('local')->exists($ruta));

            $abs = Storage::disk('local')->path($ruta);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($abs) === true);
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            $this->assertIsString($xml);
            $dom = new DOMDocument;
            $this->assertTrue($dom->loadXML($xml));

            $textoPlano = strip_tags($xml);
            $this->assertDoesNotMatchRegularExpression('/\$\{[^}]+\}/u', $textoPlano, "Quedaron etiquetas sin reemplazar en {$cfg['esperado']}");
            $this->assertStringNotContainsString('QUINIENTOS DOLARES', $textoPlano);
            $this->assertStringContainsString('MARIA FERNANDA LOPEZ TORRES', $textoPlano);
            $this->assertStringContainsString(', casado,', $textoPlano);
            $this->assertStringContainsString('Uno. - La suma de', $textoPlano);
            $this->assertStringContainsString('Dos. - La suma de', $textoPlano);
        }
    }
}
