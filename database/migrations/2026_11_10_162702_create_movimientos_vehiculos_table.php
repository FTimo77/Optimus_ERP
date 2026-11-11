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
        Schema::create('movimientos_vehiculos', function (Blueprint $table) {
            $table->id('id_movimiento');
            $table->string('id_vehiculo', 20);
            $table->unsignedBigInteger('id_tipo_movimiento');
            $table->unsignedBigInteger('id_usuario');
            $table->unsignedBigInteger('id_cliente')->nullable();

            $table->foreign('id_vehiculo')->references('id_vehiculo')->on('vehiculos');
            $table->foreign('id_tipo_movimiento')->references('id_tipo_movimiento')->on('tipo_movimientos');
            $table->foreign('id_usuario')->references('id')->on('users');
            $table->foreign('id_cliente')->references('id_cliente')->on('clientes');

            $table->text('descripcion')->nullable();
            $table->dateTime('fecha_movimiento');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movimientos_vehiculos');
    }
};
