<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ventas')) {
            return;
        }

        Schema::table('ventas', function (Blueprint $table): void {
            if (! Schema::hasColumn('ventas', 'modalidad_credito')) {
                $table->string('modalidad_credito', 30)->nullable()->after('forma_pago');
            }
            if (! Schema::hasColumn('ventas', 'cuota_mensual_credito')) {
                $table->decimal('cuota_mensual_credito', 15, 2)->nullable()->after('modalidad_credito');
            }
            if (! Schema::hasColumn('ventas', 'meses_credito')) {
                $table->unsignedSmallInteger('meses_credito')->nullable()->after('cuota_mensual_credito');
            }
            if (! Schema::hasColumn('ventas', 'fecha_siguiente_pago_credito')) {
                $table->date('fecha_siguiente_pago_credito')->nullable()->after('meses_credito');
            }
            if (! Schema::hasColumn('ventas', 'plan_cuotas_credito')) {
                $table->json('plan_cuotas_credito')->nullable()->after('fecha_siguiente_pago_credito');
            }
            if (! Schema::hasColumn('ventas', 'requiere_aprobacion_credito')) {
                $table->boolean('requiere_aprobacion_credito')->default(false)->after('plan_cuotas_credito');
            }
            if (! Schema::hasColumn('ventas', 'estado_aprobacion_credito')) {
                $table->string('estado_aprobacion_credito', 30)->nullable()->after('requiere_aprobacion_credito');
            }
            if (! Schema::hasColumn('ventas', 'comentario_aprobacion_credito')) {
                $table->text('comentario_aprobacion_credito')->nullable()->after('estado_aprobacion_credito');
            }
            if (! Schema::hasColumn('ventas', 'aprobado_credito_por')) {
                $table->foreignId('aprobado_credito_por')
                    ->nullable()
                    ->after('comentario_aprobacion_credito')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('ventas', 'aprobado_credito_en')) {
                $table->timestamp('aprobado_credito_en')->nullable()->after('aprobado_credito_por');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ventas')) {
            return;
        }

        Schema::table('ventas', function (Blueprint $table): void {
            if (Schema::hasColumn('ventas', 'aprobado_credito_por')) {
                $table->dropConstrainedForeignId('aprobado_credito_por');
            }

            $columnas = [
                'aprobado_credito_en',
                'comentario_aprobacion_credito',
                'estado_aprobacion_credito',
                'requiere_aprobacion_credito',
                'plan_cuotas_credito',
                'fecha_siguiente_pago_credito',
                'meses_credito',
                'cuota_mensual_credito',
                'modalidad_credito',
            ];

            foreach ($columnas as $columna) {
                if (Schema::hasColumn('ventas', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
