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
        Schema::create('documentos_vehiculos', function (Blueprint $table) {
            $table->id('id_documento_vehiculo');
            $table->string('id_vehiculo', 20);
            $table->string('ruta_contrato', 255)->nullable();
            $table->string('ruta_matricula', 255)->nullable();
            $table->string('ruta_cuv', 255)->nullable();
            $table->string('ruta_impronta', 255)->nullable();
            $table->string('ruta_constancia', 255)->nullable();
            $table->string('ruta_acta_entrega', 255)->nullable();
            $table->string('ruta_factura', 255)->nullable();
            $table->timestamps();

            $table->foreign('id_vehiculo')->references('id_vehiculo')->on('vehiculos');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documentos_vehiculos');
    }
};
