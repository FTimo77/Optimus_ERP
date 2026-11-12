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
            $table->unsignedBigInteger('id_patio')->nullable()->change();
            $table->unsignedBigInteger('id_proveedor')->nullable()->change();
            $table->integer('kilometraje')->nullable()->change();
            $table->decimal('precio_venta', 15, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehiculos', function (Blueprint $table) {
            $table->unsignedBigInteger('id_patio')->nullable(false)->change();
            $table->unsignedBigInteger('id_proveedor')->nullable(false)->change();
            $table->integer('kilometraje')->nullable(false)->change();
            $table->decimal('precio_venta', 15, 2)->nullable(false)->change();
        });
    }
};
