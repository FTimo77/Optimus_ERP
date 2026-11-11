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
        Schema::create('imagen_vehiculos', function (Blueprint $table) {
            $table->id('id_imagen_vehiculo');
            $table->unsignedBigInteger('id_vehiculo');
            $table->string('ruta_imagen', 255);
            $table->boolean('es_principal')->default(false);
            $table->timestamps();

            $table->foreign('id_vehiculo')->references('id_vehiculo')->on('vehiculos');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imagen_vehiculos');
    }
};
