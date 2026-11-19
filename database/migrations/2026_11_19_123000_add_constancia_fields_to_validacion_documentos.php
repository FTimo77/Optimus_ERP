<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validacion_documentos', function (Blueprint $table): void {
            if (! Schema::hasColumn('validacion_documentos', 'ruta_cedula_cliente')) {
                $table->string('ruta_cedula_cliente')->nullable()->after('ruta_comprobante_pago');
            }

            if (! Schema::hasColumn('validacion_documentos', 'datos_cedula_cliente')) {
                $table->text('datos_cedula_cliente')->nullable()->after('datos_ingreso');
            }

            if (! Schema::hasColumn('validacion_documentos', 'ruta_contrato_generado')) {
                $table->string('ruta_contrato_generado')->nullable()->after('ruta_cedula_cliente');
            }
        });
    }

    public function down(): void
    {
        Schema::table('validacion_documentos', function (Blueprint $table): void {
            $columnas = array_values(array_filter(
                ['ruta_cedula_cliente', 'datos_cedula_cliente', 'ruta_contrato_generado'],
                static fn (string $columna): bool => Schema::hasColumn('validacion_documentos', $columna),
            ));

            if ($columnas !== []) {
                $table->dropColumn($columnas);
            }
        });
    }
};
