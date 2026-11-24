<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehiculos', function (Blueprint $table): void {
            $table->decimal('precio_final', 15, 2)->nullable()->after('precio_venta');
        });
    }

    public function down(): void
    {
        Schema::table('vehiculos', function (Blueprint $table): void {
            $table->dropColumn('precio_final');
        });
    }
};
