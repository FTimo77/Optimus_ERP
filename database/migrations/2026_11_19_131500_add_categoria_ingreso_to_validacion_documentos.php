<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validacion_documentos', function (Blueprint $table): void {
            if (! Schema::hasColumn('validacion_documentos', 'categoria_ingreso')) {
                $table->string('categoria_ingreso', 20)->default('particular')->after('placa');
            }

            $table->string('ruta_documento')->nullable()->change();
            $table->string('mime_type', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('validacion_documentos', function (Blueprint $table): void {
            if (Schema::hasColumn('validacion_documentos', 'categoria_ingreso')) {
                $table->dropColumn('categoria_ingreso');
            }
        });
    }
};
