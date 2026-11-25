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
            if (! Schema::hasColumn('tasas_interes', 'tasa_mensual_desde_mes_13')) {
                $table->decimal('tasa_mensual_desde_mes_13', 6, 4)
                    ->nullable()
                    ->after('tasa_mensual');
            }
        });

        // Desactiva planes anteriores de ejemplo para dejar activa la tasa fija oficial (1.7% / 1.5% desde mes 13)
        DB::table('tasas_interes')
            ->where('nombre', '!=', 'Financiamiento Optimus (1.7% mes 1-12 · 1.5% desde mes 13)')
            ->update(['activo' => false, 'updated_at' => now()]);

        $datosPlan = [
            'nombre' => 'Financiamiento Optimus (1.7% mes 1-12 · 1.5% desde mes 13)',
            'descripcion' => 'Tasa fija 1.70% mensual sobre el monto financiado durante los primeros 12 meses y 1.50% mensual a partir del mes 13.',
            'tasa_anual' => 20.40,
            'tasa_nominal_anual' => 20.40,
            'tasa_efectiva_anual' => 22.42,
            'tasa_mensual' => 1.7000,
            'tasa_mensual_desde_mes_13' => 1.5000,
            'tasa_mora' => 5.00,
            'plazo_minimo_meses' => 1,
            'plazo_maximo_meses' => 60,
            'activo' => true,
            'updated_at' => now(),
        ];

        $existe = DB::table('tasas_interes')->where('nombre', $datosPlan['nombre'])->exists();
        if ($existe) {
            DB::table('tasas_interes')->where('nombre', $datosPlan['nombre'])->update($datosPlan);
        } else {
            DB::table('tasas_interes')->insert([...$datosPlan, 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tasas_interes')) {
            return;
        }

        DB::table('tasas_interes')
            ->where('nombre', 'Financiamiento Optimus (1.7% mes 1-12 · 1.5% desde mes 13)')
            ->delete();

        if (Schema::hasColumn('tasas_interes', 'tasa_mensual_desde_mes_13')) {
            Schema::table('tasas_interes', function (Blueprint $table): void {
                $table->dropColumn('tasa_mensual_desde_mes_13');
            });
        }
    }
};
