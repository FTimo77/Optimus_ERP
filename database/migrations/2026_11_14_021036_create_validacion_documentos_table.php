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
        Schema::create('validacion_documentos', function (Blueprint $table) {
            $table->id();
            $table->string('placa', 10)->index();
            $table->string('ruta_documento');
            $table->string('mime_type', 100);
            $table->text('datos_documento');
            $table->text('datos_oficiales');
            $table->text('comparacion');
            $table->unsignedSmallInteger('alertas_criticas')->default(0);
            $table->string('estado_revision', 20)->default('pendiente');
            $table->foreignId('subido_por')->constrained('users');
            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comentario_revision')->nullable();
            $table->timestamp('revisado_en')->nullable();
            $table->timestamps();

            $table->index(['estado_revision', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('validacion_documentos');
    }
};
