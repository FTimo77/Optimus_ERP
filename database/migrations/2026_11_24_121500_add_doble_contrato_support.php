<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tipo_contrato') && ! Schema::hasColumn('tipo_contrato', 'cantidad_documentos')) {
            Schema::table('tipo_contrato', function (Blueprint $table) {
                $table->unsignedTinyInteger('cantidad_documentos')->default(1)->after('requiere_documento');
            });
        }

        if (Schema::hasTable('tipo_contrato')) {
            DB::table('tipo_contrato')
                ->where('requiere_documento', false)
                ->update(['cantidad_documentos' => 0]);

            $existeDoble = DB::table('tipo_contrato')
                ->where('nombre', 'Doble Contrato')
                ->exists();

            if ($existeDoble) {
                DB::table('tipo_contrato')
                    ->where('nombre', 'Doble Contrato')
                    ->update([
                        'requiere_documento' => true,
                        'cantidad_documentos' => 2,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('tipo_contrato')->insert([
                    'nombre' => 'Doble Contrato',
                    'requiere_documento' => true,
                    'cantidad_documentos' => 2,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasTable('documentos_vehiculos') && ! Schema::hasColumn('documentos_vehiculos', 'ruta_contrato_2')) {
            Schema::table('documentos_vehiculos', function (Blueprint $table) {
                $table->string('ruta_contrato_2')->nullable()->after('ruta_contrato');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('documentos_vehiculos') && Schema::hasColumn('documentos_vehiculos', 'ruta_contrato_2')) {
            Schema::table('documentos_vehiculos', function (Blueprint $table) {
                $table->dropColumn('ruta_contrato_2');
            });
        }

        if (Schema::hasTable('tipo_contrato')) {
            DB::table('tipo_contrato')->where('nombre', 'Doble Contrato')->delete();

            if (Schema::hasColumn('tipo_contrato', 'cantidad_documentos')) {
                Schema::table('tipo_contrato', function (Blueprint $table) {
                    $table->dropColumn('cantidad_documentos');
                });
            }
        }
    }
};
