<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('documentos_vehiculos') && ! Schema::hasColumn('documentos_vehiculos', 'ruta_revision')) {
            Schema::table('documentos_vehiculos', function (Blueprint $table) {
                $table->string('ruta_revision', 255)->nullable()->after('ruta_matricula');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('documentos_vehiculos') && Schema::hasColumn('documentos_vehiculos', 'ruta_revision')) {
            Schema::table('documentos_vehiculos', function (Blueprint $table) {
                $table->dropColumn('ruta_revision');
            });
        }
    }
};
