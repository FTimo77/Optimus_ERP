<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tipo_contrato')) {
            return;
        }

        $tieneCantidad = Schema::hasColumn('tipo_contrato', 'cantidad_documentos');
        $datos = ['requiere_documento' => false, 'updated_at' => now()];
        if ($tieneCantidad) {
            $datos['cantidad_documentos'] = 0;
        }

        $existente = DB::table('tipo_contrato')
            ->where('nombre', 'Propietario Optimus')
            ->first();

        if ($existente !== null) {
            DB::table('tipo_contrato')
                ->where('id_tipo_contrato', $existente->id_tipo_contrato)
                ->update($datos);
            $idTipoContrato = $existente->id_tipo_contrato;
        } else {
            $idTipoContrato = DB::table('tipo_contrato')->insertGetId([
                'nombre' => 'Propietario Optimus',
                'requiere_documento' => false,
                ...($tieneCantidad ? ['cantidad_documentos' => 0] : []),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Asigna automáticamente "Propietario Optimus" a vehículos nuevos existentes sin tipo de contrato
        if (Schema::hasTable('vehiculos') && Schema::hasColumn('vehiculos', 'id_tipo_contrato')) {
            $placasNuevos = DB::table('vehiculos')
                ->where('metodo_ingreso', 'nuevos')
                ->whereNull('id_tipo_contrato')
                ->pluck('id_vehiculo')
                ->all();

            if ($placasNuevos !== []) {
                DB::table('vehiculos')
                    ->whereIn('id_vehiculo', $placasNuevos)
                    ->update(['id_tipo_contrato' => $idTipoContrato]);

                if (Schema::hasTable('documentos_vehiculos') && Schema::hasColumn('documentos_vehiculos', 'id_tipo_contrato')) {
                    DB::table('documentos_vehiculos')
                        ->whereIn('id_vehiculo', $placasNuevos)
                        ->whereNull('id_tipo_contrato')
                        ->update(['id_tipo_contrato' => $idTipoContrato]);
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tipo_contrato')) {
            return;
        }

        $tipo = DB::table('tipo_contrato')
            ->where('nombre', 'Propietario Optimus')
            ->first();

        if ($tipo === null) {
            return;
        }

        if (Schema::hasTable('vehiculos') && Schema::hasColumn('vehiculos', 'id_tipo_contrato')) {
            DB::table('vehiculos')
                ->where('id_tipo_contrato', $tipo->id_tipo_contrato)
                ->update(['id_tipo_contrato' => null]);
        }

        if (Schema::hasTable('documentos_vehiculos') && Schema::hasColumn('documentos_vehiculos', 'id_tipo_contrato')) {
            DB::table('documentos_vehiculos')
                ->where('id_tipo_contrato', $tipo->id_tipo_contrato)
                ->update(['id_tipo_contrato' => null]);
        }

        DB::table('tipo_contrato')
            ->where('id_tipo_contrato', $tipo->id_tipo_contrato)
            ->delete();
    }
};
