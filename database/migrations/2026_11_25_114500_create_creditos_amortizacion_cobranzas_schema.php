<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 0. Ajustes de compatibilidad en clientes y transacciones_cajas para vínculos reales sin desbordes
        if (Schema::hasTable('clientes')) {
            Schema::table('clientes', function (Blueprint $table): void {
                $table->string('cedula', 30)->change();
                $table->string('telefono', 30)->nullable()->change();
                $table->string('correo', 120)->nullable()->change();
                $table->string('direccion', 200)->nullable()->change();
                $table->string('apellido', 100)->nullable()->change();
            });
        }

        if (Schema::hasTable('transacciones_cajas')) {
            Schema::table('transacciones_cajas', function (Blueprint $table): void {
                $table->unsignedBigInteger('id_cliente')->nullable()->change();
                $table->decimal('monto', 15, 2)->change();
            });
        }

        // 1. Catálogo financiero: tasas_interes
        if (! Schema::hasTable('tasas_interes')) {
            Schema::create('tasas_interes', function (Blueprint $table): void {
                $table->id('id_tasa_interes');
                $table->string('nombre', 120);
                $table->string('descripcion', 255)->nullable();
                $table->decimal('tasa_anual', 6, 2)->default(0);
                $table->decimal('tasa_mensual', 6, 4)->default(0);
                $table->decimal('tasa_mora', 6, 2)->default(0);
                $table->unsignedSmallInteger('plazo_minimo_meses')->default(1);
                $table->unsignedSmallInteger('plazo_maximo_meses')->default(60);
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });

            DB::table('tasas_interes')->insert([
                [
                    'nombre' => 'Crédito Directo Corto Plazo (0% Interés)',
                    'descripcion' => 'Plan directo personalizado hasta 6 meses sin recargo financiero.',
                    'tasa_anual' => 0.00,
                    'tasa_mensual' => 0.0000,
                    'tasa_mora' => 3.00,
                    'plazo_minimo_meses' => 1,
                    'plazo_maximo_meses' => 6,
                    'activo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'nombre' => 'Financiamiento Optimus Estándar (> 6 meses)',
                    'descripcion' => 'Plan de financiamiento automotriz directo mayor a 6 meses.',
                    'tasa_anual' => 15.60,
                    'tasa_mensual' => 1.3000,
                    'tasa_mora' => 5.00,
                    'plazo_minimo_meses' => 7,
                    'plazo_maximo_meses' => 48,
                    'activo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }

        // 2. Cabecera del financiamiento: creditos (extiende tabla existente preservando compatibilidad)
        if (! Schema::hasTable('creditos')) {
            Schema::create('creditos', function (Blueprint $table): void {
                $table->id('id_credito');
                $table->foreignId('validacion_documento_id')
                    ->nullable()
                    ->constrained('validacion_documentos')
                    ->nullOnDelete();
                $table->unsignedBigInteger('id_cliente')->nullable();
                $table->unsignedBigInteger('id_venta')->nullable();
                $table->string('id_vehiculo', 20)->nullable();
                $table->unsignedBigInteger('id_tasa_interes')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->decimal('monto_total', 15, 2);
                $table->decimal('monto_inicial', 15, 2)->default(0);
                $table->decimal('monto_financiado', 15, 2)->default(0);
                $table->decimal('saldo_pendiente', 15, 2)->default(0);
                $table->unsignedSmallInteger('numero_cuotas')->default(1);
                $table->date('fecha_inicio')->nullable();
                $table->date('fecha_vencimiento')->nullable();
                $table->date('fecha_vencimiento_final')->nullable();
                $table->string('ruta_pagare', 255)->nullable();
                $table->string('estado', 40)->default('pendiente_aprobacion_credito');
                $table->text('observaciones')->nullable();
                $table->foreignId('registrado_por')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamps();

                $table->foreign('id_cliente')
                    ->references('id_cliente')
                    ->on('clientes')
                    ->nullOnDelete();
                $table->foreign('id_venta')
                    ->references('id_venta')
                    ->on('ventas')
                    ->cascadeOnDelete();
                $table->foreign('id_vehiculo')
                    ->references('id_vehiculo')
                    ->on('vehiculos')
                    ->nullOnDelete();
                $table->foreign('id_tasa_interes')
                    ->references('id_tasa_interes')
                    ->on('tasas_interes')
                    ->nullOnDelete();
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
                $table->index(['id_venta', 'estado']);
                $table->index(['id_vehiculo', 'estado']);
            });
        } else {
            Schema::table('creditos', function (Blueprint $table): void {
                $table->decimal('monto_total', 15, 2)->change();
                $table->date('fecha_vencimiento')->nullable()->change();
                $table->string('estado', 40)->default('pendiente')->change();

                if (! Schema::hasColumn('creditos', 'id_cliente')) {
                    $table->unsignedBigInteger('id_cliente')->nullable()->after('validacion_documento_id');
                    $table->foreign('id_cliente')
                        ->references('id_cliente')
                        ->on('clientes')
                        ->nullOnDelete();
                }

                if (! Schema::hasColumn('creditos', 'id_venta')) {
                    $table->unsignedBigInteger('id_venta')->nullable()->after('id_cliente');
                    $table->foreign('id_venta')
                        ->references('id_venta')
                        ->on('ventas')
                        ->cascadeOnDelete();
                }

                if (! Schema::hasColumn('creditos', 'id_tasa_interes')) {
                    $table->unsignedBigInteger('id_tasa_interes')->nullable()->after('id_vehiculo');
                    $table->foreign('id_tasa_interes')
                        ->references('id_tasa_interes')
                        ->on('tasas_interes')
                        ->nullOnDelete();
                }

                if (! Schema::hasColumn('creditos', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable()->after('id_tasa_interes');
                    $table->foreign('user_id')
                        ->references('id')
                        ->on('users')
                        ->nullOnDelete();
                }

                if (! Schema::hasColumn('creditos', 'monto_inicial')) {
                    $table->decimal('monto_inicial', 15, 2)->default(0)->after('monto_total');
                }

                if (! Schema::hasColumn('creditos', 'monto_financiado')) {
                    $table->decimal('monto_financiado', 15, 2)->default(0)->after('monto_inicial');
                }

                if (! Schema::hasColumn('creditos', 'saldo_pendiente')) {
                    $table->decimal('saldo_pendiente', 15, 2)->default(0)->after('monto_financiado');
                }

                if (! Schema::hasColumn('creditos', 'numero_cuotas')) {
                    $table->unsignedSmallInteger('numero_cuotas')->default(1)->after('saldo_pendiente');
                }

                if (! Schema::hasColumn('creditos', 'fecha_inicio')) {
                    $table->date('fecha_inicio')->nullable()->after('numero_cuotas');
                }

                if (! Schema::hasColumn('creditos', 'fecha_vencimiento_final')) {
                    $table->date('fecha_vencimiento_final')->nullable()->after('fecha_vencimiento');
                }

                if (! Schema::hasColumn('creditos', 'ruta_pagare')) {
                    $table->string('ruta_pagare', 255)->nullable()->after('fecha_vencimiento_final');
                }
            });
        }

        // 3. Tabla de amortización detallada: cuotas_credito
        if (! Schema::hasTable('cuotas_credito')) {
            Schema::create('cuotas_credito', function (Blueprint $table): void {
                $table->id('id_cuota_credito');
                $table->unsignedBigInteger('id_credito');
                $table->unsignedSmallInteger('numero_cuota');
                $table->date('fecha_vencimiento');
                $table->decimal('monto_capital', 15, 2)->default(0);
                $table->decimal('monto_interes', 15, 2)->default(0);
                $table->decimal('monto_mora', 15, 2)->default(0);
                $table->decimal('valor_cuota', 15, 2);
                $table->decimal('monto_pagado', 15, 2)->default(0);
                $table->date('fecha_pago_real')->nullable();
                $table->string('estado', 30)->default('pendiente');
                $table->timestamps();

                $table->foreign('id_credito')
                    ->references('id_credito')
                    ->on('creditos')
                    ->onDelete('cascade');

                $table->unique(['id_credito', 'numero_cuota']);
                $table->index(['id_credito', 'estado']);
                $table->index('fecha_vencimiento');
            });
        }

        // 4. Registro de abonos, comprobantes privados y vínculo con Caja: pagos_credito
        if (! Schema::hasTable('pagos_credito')) {
            Schema::create('pagos_credito', function (Blueprint $table): void {
                $table->id('id_pago_credito');
                $table->unsignedBigInteger('id_credito');
                $table->unsignedBigInteger('id_cuota_credito')->nullable();
                $table->unsignedBigInteger('id_transaccion_caja')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->decimal('monto_pagado', 15, 2);
                $table->string('metodo_pago', 30);
                $table->string('numero_referencia', 100)->nullable();
                $table->date('fecha_pago');
                $table->string('ruta_comprobante', 255)->nullable();
                $table->text('observaciones')->nullable();
                $table->timestamps();

                $table->foreign('id_credito')
                    ->references('id_credito')
                    ->on('creditos')
                    ->cascadeOnDelete();
                $table->foreign('id_cuota_credito')
                    ->references('id_cuota_credito')
                    ->on('cuotas_credito')
                    ->nullOnDelete();
                $table->foreign('id_transaccion_caja')
                    ->references('id_transaccion')
                    ->on('transacciones_cajas')
                    ->nullOnDelete();
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();

                $table->index(['id_credito', 'fecha_pago']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_credito');
        Schema::dropIfExists('cuotas_credito');

        if (Schema::hasTable('creditos')) {
            Schema::table('creditos', function (Blueprint $table): void {
                foreach (['id_cliente', 'id_venta', 'id_tasa_interes', 'user_id'] as $fk) {
                    if (Schema::hasColumn('creditos', $fk)) {
                        $table->dropForeign([$fk]);
                    }
                }

                $columnas = [
                    'id_cliente',
                    'id_venta',
                    'id_tasa_interes',
                    'user_id',
                    'monto_inicial',
                    'monto_financiado',
                    'saldo_pendiente',
                    'numero_cuotas',
                    'fecha_inicio',
                    'fecha_vencimiento_final',
                    'ruta_pagare',
                ];

                foreach ($columnas as $col) {
                    if (Schema::hasColumn('creditos', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        Schema::dropIfExists('tasas_interes');
    }
};
