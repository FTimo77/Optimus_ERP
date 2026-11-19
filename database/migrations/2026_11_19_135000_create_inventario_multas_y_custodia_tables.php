<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'id_patio')) {
                $table->unsignedBigInteger('id_patio')->nullable()->after('email');
                $table->foreign('id_patio')
                    ->references('id_patio')
                    ->on('patios')
                    ->nullOnDelete();
            }
        });

        if (! Schema::hasTable('multas_vehiculos')) {
            Schema::create('multas_vehiculos', function (Blueprint $table): void {
                $table->id('id_multa');
                $table->string('id_vehiculo', 20);
                $table->string('fuente', 60)->nullable();
                $table->string('concepto', 255);
                $table->decimal('valor', 10, 2)->default(0);
                $table->string('fecha_infraccion', 50)->nullable();
                $table->string('estado', 20)->default('pendiente');
                $table->string('ruta_comprobante_pago')->nullable();
                $table->foreignId('pagado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('pagado_en')->nullable();
                $table->timestamps();

                $table->foreign('id_vehiculo')
                    ->references('id_vehiculo')
                    ->on('vehiculos')
                    ->cascadeOnDelete();
                $table->index(['id_vehiculo', 'estado']);
            });
        }

        if (! Schema::hasTable('custodia_documentos_vehiculos')) {
            Schema::create('custodia_documentos_vehiculos', function (Blueprint $table): void {
                $table->id('id_custodia');
                $table->string('id_vehiculo', 20);
                $table->string('item', 40);
                $table->string('estado', 30)->default('pendiente_recepcion');
                $table->foreignId('usuario_entrega_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('usuario_recepcion_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedBigInteger('patio_destino_id')->nullable();
                $table->unsignedBigInteger('patio_actual_id')->nullable();
                $table->string('observacion', 500)->nullable();
                $table->timestamp('solicitado_en')->nullable();
                $table->timestamp('recibido_en')->nullable();
                $table->timestamps();

                $table->foreign('id_vehiculo')
                    ->references('id_vehiculo')
                    ->on('vehiculos')
                    ->cascadeOnDelete();
                $table->foreign('patio_destino_id')
                    ->references('id_patio')
                    ->on('patios')
                    ->nullOnDelete();
                $table->foreign('patio_actual_id')
                    ->references('id_patio')
                    ->on('patios')
                    ->nullOnDelete();
                $table->index(['id_vehiculo', 'item']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('custodia_documentos_vehiculos');
        Schema::dropIfExists('multas_vehiculos');

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'id_patio')) {
                $table->dropForeign(['id_patio']);
                $table->dropColumn('id_patio');
            }
        });
    }
};
