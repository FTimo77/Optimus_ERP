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
        Schema::create('inspeccion_logs', function (Blueprint $table) {
            $table->id('id_inspeccion_log');
            $table->unsignedBigInteger('id_vehiculo');
            $table->unsignedBigInteger('id_user');
            $table->unsignedBigInteger('id_inspeccion_detalle');

            $table->foreign('id_vehiculo')->references('id_vehiculo')->on('vehiculos');
            $table->foreign('id_user')->references('id')->on('users');
            $table->foreign('id_inspeccion_detalle')->references('id_inspeccion_detalle')->on('inspeccion_detalles');

            $table->date('fecha');
            $table->string('observaciones', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inspeccion_logs');
    }
};
