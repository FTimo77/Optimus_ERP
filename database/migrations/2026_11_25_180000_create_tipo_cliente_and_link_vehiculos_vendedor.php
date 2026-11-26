<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tipo_cliente')) {
            Schema::create('tipo_cliente', function (Blueprint $table): void {
                $table->id('id_tipo_cliente');
                $table->string('nombre', 60)->unique();
                $table->string('descripcion', 255)->nullable();
                $table->timestamps();
            });
        }

        $ahora = now();
        $tipos = [
            [
                'nombre' => 'Comprador',
                'descripcion' => 'Cliente que compra o reserva vehículos en el concesionario.',
            ],
            [
                'nombre' => 'Vendedor',
                'descripcion' => 'Persona natural o jurídica que vende un vehículo al concesionario.',
            ],
            [
                'nombre' => 'Ambos',
                'descripcion' => 'Cliente que ha vendido y comprado vehículos en el concesionario.',
            ],
        ];

        foreach ($tipos as $tipo) {
            DB::table('tipo_cliente')->updateOrInsert(
                ['nombre' => $tipo['nombre']],
                [
                    'descripcion' => $tipo['descripcion'],
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ],
            );
        }

        $idComprador = DB::table('tipo_cliente')
            ->where('nombre', 'Comprador')
            ->value('id_tipo_cliente');

        if (Schema::hasTable('clientes') && ! Schema::hasColumn('clientes', 'id_tipo_cliente')) {
            Schema::table('clientes', function (Blueprint $table): void {
                $table->unsignedBigInteger('id_tipo_cliente')->nullable()->after('id_cliente');
                $table->foreign('id_tipo_cliente')
                    ->references('id_tipo_cliente')
                    ->on('tipo_cliente')
                    ->nullOnDelete();
            });

            if ($idComprador !== null) {
                DB::table('clientes')
                    ->whereNull('id_tipo_cliente')
                    ->update(['id_tipo_cliente' => $idComprador]);
            }
        }

        if (Schema::hasTable('vehiculos') && ! Schema::hasColumn('vehiculos', 'id_cliente_vendedor')) {
            Schema::table('vehiculos', function (Blueprint $table): void {
                $table->unsignedBigInteger('id_cliente_vendedor')->nullable()->after('id_proveedor');
                $table->foreign('id_cliente_vendedor')
                    ->references('id_cliente')
                    ->on('clientes')
                    ->nullOnDelete();
                $table->index('id_cliente_vendedor');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vehiculos') && Schema::hasColumn('vehiculos', 'id_cliente_vendedor')) {
            Schema::table('vehiculos', function (Blueprint $table): void {
                $table->dropForeign(['id_cliente_vendedor']);
                $table->dropIndex(['id_cliente_vendedor']);
                $table->dropColumn('id_cliente_vendedor');
            });
        }

        if (Schema::hasTable('clientes') && Schema::hasColumn('clientes', 'id_tipo_cliente')) {
            Schema::table('clientes', function (Blueprint $table): void {
                $table->dropForeign(['id_tipo_cliente']);
                $table->dropColumn('id_tipo_cliente');
            });
        }

        Schema::dropIfExists('tipo_cliente');
    }
};
