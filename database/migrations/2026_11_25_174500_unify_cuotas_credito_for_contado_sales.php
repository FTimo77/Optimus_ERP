<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('creditos') && ! Schema::hasColumn('creditos', 'modalidad')) {
            Schema::table('creditos', function (Blueprint $table): void {
                $table->string('modalidad', 30)->default('credito')->after('id_vehiculo');
                $table->index(['id_venta', 'modalidad']);
            });
        }

        if (Schema::hasTable('cuotas_credito') && ! Schema::hasColumn('cuotas_credito', 'id_venta')) {
            Schema::table('cuotas_credito', function (Blueprint $table): void {
                $table->unsignedBigInteger('id_venta')->nullable()->after('id_credito');
                $table->foreign('id_venta')
                    ->references('id_venta')
                    ->on('ventas')
                    ->cascadeOnDelete();
                $table->index(['id_venta', 'numero_cuota']);
            });

            // Backfill de id_venta en cuotas existentes desde la cabecera creditos
            if (Schema::hasTable('creditos') && Schema::hasColumn('creditos', 'id_venta')) {
                $creditosConVenta = DB::table('creditos')
                    ->whereNotNull('id_venta')
                    ->pluck('id_venta', 'id_credito');

                foreach ($creditosConVenta as $idCredito => $idVenta) {
                    DB::table('cuotas_credito')
                        ->where('id_credito', $idCredito)
                        ->whereNull('id_venta')
                        ->update(['id_venta' => $idVenta]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cuotas_credito') && Schema::hasColumn('cuotas_credito', 'id_venta')) {
            Schema::table('cuotas_credito', function (Blueprint $table): void {
                $table->dropForeign(['id_venta']);
                $table->dropIndex(['id_venta', 'numero_cuota']);
                $table->dropColumn('id_venta');
            });
        }

        if (Schema::hasTable('creditos') && Schema::hasColumn('creditos', 'modalidad')) {
            Schema::table('creditos', function (Blueprint $table): void {
                $table->dropIndex(['id_venta', 'modalidad']);
                $table->dropColumn('modalidad');
            });
        }
    }
};
