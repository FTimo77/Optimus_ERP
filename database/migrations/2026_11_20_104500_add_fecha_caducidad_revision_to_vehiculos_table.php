<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehiculos', function (Blueprint $table): void {
            if (! Schema::hasColumn('vehiculos', 'fecha_caducidad_revision')) {
                $table->date('fecha_caducidad_revision')->nullable()->after('fecha_revision');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vehiculos', function (Blueprint $table): void {
            if (Schema::hasColumn('vehiculos', 'fecha_caducidad_revision')) {
                $table->dropColumn('fecha_caducidad_revision');
            }
        });
    }
};
