<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('validacion_documentos', function (Blueprint $table) {
            $table->text('resumen_auditoria')->nullable();
            $table->text('datos_ingreso')->nullable();
            $table->text('comentario_solicitud')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('validacion_documentos', function (Blueprint $table) {
            $table->dropColumn(['resumen_auditoria', 'datos_ingreso', 'comentario_solicitud']);
        });
    }
};
