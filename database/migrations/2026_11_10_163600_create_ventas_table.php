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
        Schema::create('ventas', function (Blueprint $table) {
            $table->id('id_venta');
            $table->unsignedBigInteger('id_cliente');
            $table->unsignedBigInteger('id_vehiculo');
            $table->unsignedBigInteger('id_user');
            $table->unsignedBigInteger('id_metodo_pago');

            $table->foreign('id_cliente')->references('id_cliente')->on('clientes');
            $table->foreign('id_vehiculo')->references('id_vehiculo')->on('vehiculos');
            $table->foreign('id_user')->references('id')->on('users');
            $table->foreign('id_metodo_pago')->references('id_metodo_pago')->on('metodo_pagos');

            $table->date('fecha_venta');
            $table->decimal('precio_venta', 10, 2);
            $table->string('estado', 100);
            $table->decimal('utilidad_neta', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ventas');
    }
};
