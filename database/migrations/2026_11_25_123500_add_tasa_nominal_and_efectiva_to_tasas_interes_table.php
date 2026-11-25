<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasas_interes')) {
            return;
        }

        Schema::table('tasas_interes', function (Blueprint $table): void {
            if (! Schema::hasColumn('tasas_interes', 'tasa_nominal_anual')) {
                $table->decimal('tasa_nominal_anual', 6, 2)->default(0)->after('tasa_anual');
            }
            if (! Schema::hasColumn('tasas_interes', 'tasa_efectiva_anual')) {
                $table->decimal('tasa_efectiva_anual', 6, 2)->default(0)->after('tasa_nominal_anual');
            }
        });

        // Sincroniza TNA y TEA en registros existentes a partir de tasa_mensual / tasa_anual
        $tasas = DB::table('tasas_interes')->get();
        foreach ($tasas as $tasa) {
            $tasaMensual = (float) ($tasa->tasa_mensual ?? 0);
            $tasaAnual = (float) ($tasa->tasa_anual ?? 0);

            if ($tasaMensual <= 0.0 && $tasaAnual > 0.0) {
                $tasaMensual = round($tasaAnual / 12, 4);
            }

            $tasaNominal = $tasaAnual > 0.0 ? round($tasaAnual, 2) : round($tasaMensual * 12, 2);
            $tasaEfectiva = $tasaMensual > 0.0
                ? round((pow(1 + ($tasaMensual / 100), 12) - 1) * 100, 2)
                : 0.0;

            DB::table('tasas_interes')
                ->where('id_tasa_interes', $tasa->id_tasa_interes)
                ->update([
                    'tasa_nominal_anual' => $tasaNominal,
                    'tasa_efectiva_anual' => $tasaEfectiva,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tasas_interes')) {
            return;
        }

        Schema::table('tasas_interes', function (Blueprint $table): void {
            if (Schema::hasColumn('tasas_interes', 'tasa_efectiva_anual')) {
                $table->dropColumn('tasa_efectiva_anual');
            }
            if (Schema::hasColumn('tasas_interes', 'tasa_nominal_anual')) {
                $table->dropColumn('tasa_nominal_anual');
            }
        });
    }
};
