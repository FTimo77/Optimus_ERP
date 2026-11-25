<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TasaInteresSeeder extends Seeder
{
    public function run(): void
    {
        $planOficial = [
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
        ];

        DB::table('tasas_interes')
            ->where('nombre', '!=', $planOficial['nombre'])
            ->update(['activo' => false, 'updated_at' => now()]);

        $existe = DB::table('tasas_interes')->where('nombre', $planOficial['nombre'])->exists();
        if ($existe) {
            DB::table('tasas_interes')
                ->where('nombre', $planOficial['nombre'])
                ->update([...$planOficial, 'updated_at' => now()]);
        } else {
            DB::table('tasas_interes')->insert([
                ...$planOficial,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
