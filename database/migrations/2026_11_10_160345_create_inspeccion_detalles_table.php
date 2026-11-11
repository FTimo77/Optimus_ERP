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
        Schema::create('inspeccion_detalles', function (Blueprint $table) {
            $table->id('id_inspeccion_detalle');
            $table->unsignedBigInteger('id_item_inspeccion');
            $table->foreign('id_item_inspeccion')->references('id_item_inspeccion')->on('item_inspecciones');
            $table->boolean('cumple')->default(false);
            $table->string('observaciones', 255);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inspeccion_detalles');
    }
};
