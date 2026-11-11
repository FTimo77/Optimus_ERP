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
        Schema::create('transacciones_cajas', function (Blueprint $table) {
            $table->id('id_transaccion');
            $table->unsignedBigInteger('id_vehiculo');
            $table->unsignedBigInteger('id_user');
            $table->unsignedBigInteger('id_cliente');
            $table->unsignedBigInteger('id_tipo_transaccion');
            $table->unsignedBigInteger('id_metodo_pago');

            $table->foreign('id_vehiculo')->references('id_vehiculo')->on('vehiculos');
            $table->foreign('id_user')->references('id')->on('users');
            $table->foreign('id_cliente')->references('id_cliente')->on('clientes');
            $table->foreign('id_tipo_transaccion')->references('id_tipo_transaccion')->on('tipo_transacciones');
            $table->foreign('id_metodo_pago')->references('id_metodo_pago')->on('metodo_pagos');

            $table->date('fecha_transaccion');
            $table->decimal('monto', 10, 2);
            $table->string('descripcion', 255)->nullable();
            $table->string('ruta_comprobante', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transacciones_cajas');
    }
};
