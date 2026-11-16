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
        Schema::table('validacion_documentos', function (Blueprint $table) {
            $table->text('comentario_reenvio')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('validacion_documentos', function (Blueprint $table) {
            $table->dropColumn('comentario_reenvio');
        });
    }
};
