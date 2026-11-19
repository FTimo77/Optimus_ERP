<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validacion_documentos', function (Blueprint $table) {
            $table->string('estado_revision', 40)->default('pendiente')->change();
            $table->string('ruta_comprobante_pago')->nullable()->after('ruta_cuv');
        });

        Schema::table('documentos_vehiculos', function (Blueprint $table) {
            $table->string('ruta_comprobante_pago')->nullable()->after('ruta_cuv');
        });
    }

    public function down(): void
    {
        Schema::table('validacion_documentos', function (Blueprint $table) {
            $table->dropColumn('ruta_comprobante_pago');
            $table->string('estado_revision', 20)->default('pendiente')->change();
        });

        Schema::table('documentos_vehiculos', function (Blueprint $table) {
            $table->dropColumn('ruta_comprobante_pago');
        });
    }
};
