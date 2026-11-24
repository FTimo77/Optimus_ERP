<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table): void {
            $table->unsignedBigInteger('id_cliente')->nullable()->change();
            $table->unsignedBigInteger('id_metodo_pago')->nullable()->change();
            $table->date('fecha_venta')->nullable()->change();
            $table->decimal('precio_venta', 15, 2)->nullable()->change();
            $table->decimal('utilidad_neta', 15, 2)->nullable()->change();

            $table->string('tipo_operacion', 30)->default('venta')->after('id_metodo_pago');
            $table->decimal('precio_base', 15, 2)->nullable()->after('tipo_operacion');
            $table->decimal('precio_acordado', 15, 2)->nullable()->after('precio_base');
            $table->boolean('requiere_aprobacion_descuento')->default(false)->after('precio_acordado');
            $table->string('estado_aprobacion_descuento', 40)->nullable()->after('requiere_aprobacion_descuento');
            $table->text('motivo_descuento')->nullable()->after('estado_aprobacion_descuento');
            $table->text('comentario_aprobacion_descuento')->nullable()->after('motivo_descuento');
            $table->unsignedBigInteger('aprobado_descuento_por')->nullable()->after('comentario_aprobacion_descuento');
            $table->timestamp('aprobado_descuento_en')->nullable()->after('aprobado_descuento_por');

            $table->string('forma_pago', 30)->nullable()->after('estado');
            $table->date('fecha_vencimiento_credito')->nullable()->after('forma_pago');
            $table->text('observaciones_credito')->nullable()->after('fecha_vencimiento_credito');

            $table->string('ruta_cedula_comprador', 255)->nullable()->after('observaciones_credito');
            $table->text('datos_cedula_comprador')->nullable()->after('ruta_cedula_comprador');
            $table->string('ruta_constancia_generada', 255)->nullable()->after('datos_cedula_comprador');

            $table->foreign('aprobado_descuento_por')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        Schema::create('pagos_ventas', function (Blueprint $table): void {
            $table->id('id_pago_venta');
            $table->unsignedBigInteger('id_venta');
            $table->string('id_vehiculo', 20);
            $table->string('ruta_comprobante', 255);
            $table->decimal('monto', 15, 2);
            $table->string('tipo_pago', 30)->default('contado');
            $table->date('fecha_pago')->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->timestamps();

            $table->foreign('id_venta')
                ->references('id_venta')
                ->on('ventas')
                ->cascadeOnDelete();
            $table->foreign('id_vehiculo')
                ->references('id_vehiculo')
                ->on('vehiculos')
                ->cascadeOnDelete();
            $table->foreign('registrado_por')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_ventas');

        Schema::table('ventas', function (Blueprint $table): void {
            $table->dropForeign(['aprobado_descuento_por']);
            $table->dropColumn([
                'tipo_operacion',
                'precio_base',
                'precio_acordado',
                'requiere_aprobacion_descuento',
                'estado_aprobacion_descuento',
                'motivo_descuento',
                'comentario_aprobacion_descuento',
                'aprobado_descuento_por',
                'aprobado_descuento_en',
                'forma_pago',
                'fecha_vencimiento_credito',
                'observaciones_credito',
                'ruta_cedula_comprador',
                'datos_cedula_comprador',
                'ruta_constancia_generada',
            ]);
        });
    }
};
