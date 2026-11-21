<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tipo_contrato')) {
            Schema::create('tipo_contrato', function (Blueprint $table): void {
                $table->id('id_tipo_contrato');
                $table->string('nombre', 100)->unique();
                $table->boolean('requiere_documento')->default(true);
                $table->timestamps();
            });
        }

        $ahora = now();
        $tiposPorDefecto = [
            ['nombre' => 'Contrato de compra y venta', 'requiere_documento' => true],
            ['nombre' => 'Contrato de mandato', 'requiere_documento' => true],
            ['nombre' => 'Poder', 'requiere_documento' => true],
            ['nombre' => 'Firma directa', 'requiere_documento' => false],
        ];

        foreach ($tiposPorDefecto as $tipo) {
            DB::table('tipo_contrato')->updateOrInsert(
                ['nombre' => $tipo['nombre']],
                [
                    'requiere_documento' => $tipo['requiere_documento'],
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ],
            );
        }

        Schema::table('validacion_documentos', function (Blueprint $table): void {
            if (! Schema::hasColumn('validacion_documentos', 'id_tipo_contrato')) {
                $table->unsignedBigInteger('id_tipo_contrato')->nullable()->after('forma_pago');
                $table->foreign('id_tipo_contrato')
                    ->references('id_tipo_contrato')
                    ->on('tipo_contrato')
                    ->nullOnDelete();
            }
        });

        Schema::table('vehiculos', function (Blueprint $table): void {
            if (! Schema::hasColumn('vehiculos', 'id_tipo_contrato')) {
                $table->unsignedBigInteger('id_tipo_contrato')->nullable()->after('id_servicio');
                $table->foreign('id_tipo_contrato')
                    ->references('id_tipo_contrato')
                    ->on('tipo_contrato')
                    ->nullOnDelete();
            }
        });

        Schema::table('documentos_vehiculos', function (Blueprint $table): void {
            if (! Schema::hasColumn('documentos_vehiculos', 'id_tipo_contrato')) {
                $table->unsignedBigInteger('id_tipo_contrato')->nullable()->after('id_vehiculo');
                $table->foreign('id_tipo_contrato')
                    ->references('id_tipo_contrato')
                    ->on('tipo_contrato')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('documentos_vehiculos', 'ruta_contrato')) {
                $table->string('ruta_contrato')->nullable()->after('id_tipo_contrato');
            }
        });
    }

    public function down(): void
    {
        Schema::table('documentos_vehiculos', function (Blueprint $table): void {
            if (Schema::hasColumn('documentos_vehiculos', 'id_tipo_contrato')) {
                $table->dropForeign(['id_tipo_contrato']);
                $table->dropColumn('id_tipo_contrato');
            }
        });

        Schema::table('vehiculos', function (Blueprint $table): void {
            if (Schema::hasColumn('vehiculos', 'id_tipo_contrato')) {
                $table->dropForeign(['id_tipo_contrato']);
                $table->dropColumn('id_tipo_contrato');
            }
        });

        Schema::table('validacion_documentos', function (Blueprint $table): void {
            if (Schema::hasColumn('validacion_documentos', 'id_tipo_contrato')) {
                $table->dropForeign(['id_tipo_contrato']);
                $table->dropColumn('id_tipo_contrato');
            }
        });

        Schema::dropIfExists('tipo_contrato');
    }
};
