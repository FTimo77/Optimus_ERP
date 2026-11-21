<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validacion_documentos', function (Blueprint $table): void {
            if (! Schema::hasColumn('validacion_documentos', 'forma_pago')) {
                $table->string('forma_pago', 20)->nullable()->after('estado_revision');
            }
        });

        if (! Schema::hasTable('creditos')) {
            Schema::create('creditos', function (Blueprint $table): void {
                $table->id('id_credito');
                $table->foreignId('validacion_documento_id')
                    ->nullable()
                    ->constrained('validacion_documentos')
                    ->nullOnDelete();
                $table->string('id_vehiculo', 20)->nullable();
                $table->decimal('monto_total', 12, 2);
                $table->date('fecha_vencimiento');
                $table->string('estado', 20)->default('pendiente');
                $table->text('observaciones')->nullable();
                $table->foreignId('registrado_por')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamps();

                $table->foreign('id_vehiculo')
                    ->references('id_vehiculo')
                    ->on('vehiculos')
                    ->nullOnDelete();
                $table->index(['id_vehiculo', 'estado']);
            });
        }

        if (! Schema::hasTable('pagos_vehiculos')) {
            Schema::create('pagos_vehiculos', function (Blueprint $table): void {
                $table->id('id_pago');
                $table->foreignId('validacion_documento_id')
                    ->nullable()
                    ->constrained('validacion_documentos')
                    ->nullOnDelete();
                $table->string('id_vehiculo', 20)->nullable();
                $table->string('ruta_comprobante');
                $table->decimal('monto', 12, 2);
                $table->string('tipo_pago', 20);
                $table->date('fecha_pago');
                $table->foreignId('registrado_por')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamps();

                $table->foreign('id_vehiculo')
                    ->references('id_vehiculo')
                    ->on('vehiculos')
                    ->nullOnDelete();
                $table->index(['id_vehiculo', 'tipo_pago']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_vehiculos');
        Schema::dropIfExists('creditos');

        Schema::table('validacion_documentos', function (Blueprint $table): void {
            if (Schema::hasColumn('validacion_documentos', 'forma_pago')) {
                $table->dropColumn('forma_pago');
            }
        });
    }
};
