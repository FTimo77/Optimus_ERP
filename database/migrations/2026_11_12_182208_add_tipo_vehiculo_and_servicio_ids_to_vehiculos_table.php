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
        Schema::table('vehiculos', function (Blueprint $table) {
            $table->unsignedBigInteger('id_tipo_vehiculo')->nullable();
            $table->unsignedBigInteger('id_servicio')->nullable();

            $table->foreign('id_tipo_vehiculo')
                ->references('id_tipo_vehiculo')
                ->on('tipo_vehiculos')
                ->nullOnDelete();
            $table->foreign('id_servicio')
                ->references('id_servicio')
                ->on('servicios')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehiculos', function (Blueprint $table) {
            $table->dropForeign(['id_tipo_vehiculo']);
            $table->dropForeign(['id_servicio']);
            $table->dropColumn(['id_tipo_vehiculo', 'id_servicio']);
        });
    }
};
