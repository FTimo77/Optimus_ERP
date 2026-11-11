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
        Schema::create('vehiculos', function (Blueprint $table) {
            $table->string('id_vehiculo', 20)->primary();

            $table->unsignedBigInteger('id_marca');
            $table->unsignedBigInteger('id_clase');
            $table->unsignedBigInteger('id_pais');
            $table->unsignedBigInteger('id_color');
            $table->unsignedBigInteger('id_tipo_combustible');
            $table->unsignedBigInteger('id_transmision');
            $table->unsignedBigInteger('id_patio');
            $table->unsignedBigInteger('id_estado');
            $table->unsignedBigInteger('id_proveedor');

            $table->foreign('id_marca')->references('id_marca')->on('marca_vehiculos');
            $table->foreign('id_clase')->references('id_clase')->on('clase_vehiculos');
            $table->foreign('id_pais')->references('id_pais')->on('paises');
            $table->foreign('id_color')->references('id_color')->on('color_vehiculos');
            $table->foreign('id_tipo_combustible')->references('id_tipo_combustible')->on('tipo_combustibles');
            $table->foreign('id_transmision')->references('id_transmision')->on('transmisiones');
            $table->foreign('id_patio')->references('id_patio')->on('patios');
            $table->foreign('id_estado')->references('id_estado')->on('estado_vehiculos');
            $table->foreign('id_proveedor')->references('id_proveedor')->on('proveedores');

            $table->string('modelo', 100);
            $table->integer('anio');
            $table->string('motor', 100);
            $table->string('chasis', 100);
            $table->string('cilindraje', 100);
            $table->integer('kilometraje');
            $table->decimal('costo_adquisicion', 15, 2);
            $table->decimal('precio_venta', 15, 2);
            $table->string('ruta_matricula', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehiculos');
    }
};
