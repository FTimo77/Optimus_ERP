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
        Schema::create('clientes', function (Blueprint $table) {
            $table->id('id_cliente');
            $table->string('nombre', 100);
            $table->string('apellido', 100);
            $table->integer('telefono')->unique();
            $table->string('correo', 100)->unique();
            $table->string('direccion', 100);
            $table->integer('cedula')->unique();
            $table->string('ruta_uafe', 255)->nullable();
            $table->string('ruta_cedula', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
