<?php

namespace Tests\Feature;

use App\Models\Credito;
use App\Models\CuotaCredito;
use App\Models\Estado_vehiculo;
use App\Models\PagoCredito;
use App\Models\Transacciones_caja;
use App\Models\User;
use App\Models\Vehiculo;
use App\Models\Venta;
use App\Services\ConstanciaVentaGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VentaContadoCuotasCreditoTest extends TestCase
{
    use RefreshDatabase;

    private function crearUsuarioVendedor(): User
    {
        Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('Vendedor');

        return $user;
    }

    private function crearVentaActiva(User $vendedor, float $precio = 12500.00): Venta
    {
        $estadoProceso = Estado_vehiculo::firstOrCreate(['nombre' => 'En proceso de venta']);
        $ahora = now();

        $catalogos = [
            'marca_vehiculos' => ['id_marca', 'Toyota'],
            'clase_vehiculos' => ['id_clase', 'Sedan'],
            'paises' => ['id_pais', 'Japon'],
            'color_vehiculos' => ['id_color', 'Blanco'],
            'tipo_combustibles' => ['id_tipo_combustible', 'Gasolina'],
            'transmisiones' => ['id_transmision', 'Automatica'],
            'patios' => ['id_patio', 'Patio Principal'],
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
            'nombre' => 'Proveedor Demo',
            'cedula' => '1712345678',
            'correo' => 'proveedor@optimus.test',
            'direccion' => 'Quito, Ecuador',
            'ruta_cedula' => 'documentos/cedulas/demo.pdf',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ], 'id_proveedor');

        $vehiculo = Vehiculo::create([
            'id_vehiculo' => 'PBA1234',
            'id_marca' => $ids['id_marca'],
            'id_clase' => $ids['id_clase'],
            'id_pais' => $ids['id_pais'],
            'id_color' => $ids['id_color'],
            'id_tipo_combustible' => $ids['id_tipo_combustible'],
            'id_transmision' => $ids['id_transmision'],
            'id_patio' => $ids['id_patio'],
            'id_estado' => $estadoProceso->id_estado,
            'id_proveedor' => $idProveedor,
            'modelo' => 'Corolla',
            'anio' => 2022,
            'motor' => 'MOT-123456',
            'chasis' => 'CHS-123456',
            'cilindraje' => '1800',
            'kilometraje' => 45000,
            'costo_adquisicion' => max(1000.0, $precio - 2000.0),
            'precio_venta' => $precio,
            'precio_final' => $precio,
        ]);

        return Venta::create([
            'id_vehiculo' => $vehiculo->id_vehiculo,
            'id_user' => $vendedor->id,
            'tipo_operacion' => 'venta',
            'precio_base' => $precio,
            'precio_acordado' => $precio,
            'precio_venta' => $precio,
            'fecha_venta' => now()->toDateString(),
            'estado' => 'en_proceso',
            'requiere_aprobacion_descuento' => false,
        ]);
    }

    public function test_venta_contado_pago_unico_se_unifica_en_cuotas_credito_pagos_credito_y_caja_sin_pagare(): void
    {
        Storage::fake('local');
        $vendedor = $this->crearUsuarioVendedor();
        $venta = $this->crearVentaActiva($vendedor, 15000.00);

        $comprobante = UploadedFile::fake()->create('transferencia_total.pdf', 120, 'application/pdf');

        $response = $this->actingAs($vendedor)
            ->post(route('mis-ventas.pago.store', $venta->id_venta), [
                'forma_pago' => 'contado',
                'documento_comprobante' => $comprobante,
                'fecha_pago' => '2026-11-25',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $venta->refresh();
        $this->assertSame('contado', $venta->forma_pago);
        $this->assertSame('pagada', $venta->estado);
        $this->assertFalse((bool) $venta->requiere_aprobacion_credito);
        $this->assertNull($venta->estado_aprobacion_credito);

        $credito = Credito::query()->where('id_venta', $venta->id_venta)->first();
        $this->assertNotNull($credito);
        $this->assertSame('contado', $credito->modalidad);
        $this->assertSame('pagado', $credito->estado);
        $this->assertEquals(0.0, (float) $credito->monto_financiado);
        $this->assertEquals(0.0, (float) $credito->saldo_pendiente);
        $this->assertEquals(15000.0, (float) $credito->monto_inicial);
        $this->assertSame(1, (int) $credito->numero_cuotas);
        $this->assertNull($credito->ruta_pagare);

        $cuotas = CuotaCredito::query()->where('id_venta', $venta->id_venta)->get();
        $this->assertCount(1, $cuotas);
        $cuota = $cuotas->first();
        $this->assertSame(1, $cuota->numero_cuota);
        $this->assertEquals(15000.0, (float) $cuota->monto_capital);
        $this->assertEquals(0.0, (float) $cuota->monto_interes);
        $this->assertEquals(0.0, (float) $cuota->monto_mora);
        $this->assertEquals(15000.0, (float) $cuota->valor_cuota);
        $this->assertEquals(15000.0, (float) $cuota->monto_pagado);
        $this->assertSame('pagada', $cuota->estado);

        $pagoCredito = PagoCredito::query()->where('id_cuota_credito', $cuota->id_cuota_credito)->first();
        $this->assertNotNull($pagoCredito);
        $this->assertNotNull($pagoCredito->id_transaccion_caja);
        $this->assertTrue(Storage::disk('local')->exists($pagoCredito->ruta_comprobante));

        $asientoCaja = Transacciones_caja::query()->find($pagoCredito->id_transaccion_caja);
        $this->assertNotNull($asientoCaja);
        $this->assertEquals(15000.0, (float) $asientoCaja->monto);

        // El servicio de constancias puede leer directamente las cuotas sin distinguir modalidad
        $servicioConstancia = app(ConstanciaVentaGeneratorService::class);
        $this->assertCount(1, $servicioConstancia->obtenerCuotasVenta($venta));
    }

    public function test_venta_contado_con_multiples_comprobantes_crea_multiples_filas_en_cuotas_credito(): void
    {
        Storage::fake('local');
        $vendedor = $this->crearUsuarioVendedor();
        $venta = $this->crearVentaActiva($vendedor, 10000.00);

        $response = $this->actingAs($vendedor)
            ->post(route('mis-ventas.pago.store', $venta->id_venta), [
                'forma_pago' => 'contado',
                'pagos_contado' => [
                    [
                        'comprobante' => UploadedFile::fake()->create('trf1.pdf', 80, 'application/pdf'),
                        'monto' => 6000.00,
                        'fecha_pago' => '2026-11-24',
                    ],
                    [
                        'comprobante' => UploadedFile::fake()->create('trf2.pdf', 80, 'application/pdf'),
                        'monto' => 4000.00,
                        'fecha_pago' => '2026-11-25',
                    ],
                ],
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $cuotas = CuotaCredito::query()->where('id_venta', $venta->id_venta)->orderBy('numero_cuota')->get();
        $this->assertCount(2, $cuotas);
        $this->assertSame([1, 2], $cuotas->pluck('numero_cuota')->all());
        $this->assertEquals([6000.0, 4000.0], $cuotas->pluck('monto_pagado')->map(fn ($v) => (float) $v)->all());
        $this->assertSame(['pagada', 'pagada'], $cuotas->pluck('estado')->all());
        $this->assertSame(2, PagoCredito::query()->count());
        $this->assertSame(2, Transacciones_caja::query()->count());
    }
}
