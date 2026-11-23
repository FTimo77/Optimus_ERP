<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Limpiar caché de permisos de Spatie
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ==========================================
        // 2. CREACIÓN DE PERMISOS POR DEPARTAMENTO
        // ==========================================
        $permisos = [
            // Comercial / Inventario
            'ver inventario',
            'vender vehiculo',
            'reservar vehiculo',
            'generar cotizaciones',

            // Administración / Compras / Finanzas
            'aprobar o rechazar solicitudes de compra',
            'editar costo adquisicion',
            'ver reportes financieros',
            'ver multas',
            'ver precio de adquisicion',
            'ver pagos y comprobantes',
        ];

        foreach ($permisos as $permiso) {
            Permission::firstOrCreate(['name' => $permiso]);
        }

        // ==========================================
        // 3. CREACIÓN DE ROLES Y ASIGNACIÓN
        // ==========================================

        // 1. Administrador / Gerencia (Acceso Total)
        $admin = Role::firstOrCreate(['name' => 'Administrador']);
        $admin->syncPermissions(Permission::all());

        // 2. Compras (Negocia y registra la compra de autos y repuestos)
        $compras = Role::firstOrCreate(['name' => 'Compras']);
        $compras->syncPermissions([
            'ver inventario',
            'editar costo adquisicion',
            'ver precio de adquisicion',
        ]);

        // 3. Administración (Verifica papeles tras compra aprobada, custodia carpeta física y matrículas)
        $administracion = Role::firstOrCreate(['name' => 'Administracion']);
        $administracion->syncPermissions([
            'ver inventario',
            'aprobar o rechazar solicitudes de compra',
            'editar costo adquisicion',
            'ver reportes financieros',
            'ver multas',
            'ver precio de adquisicion',
            'ver pagos y comprobantes',
        ]);

        // 4. Jefe de Alistamiento (Recibe el auto físico, lo repara y lo entrega al Jefe de Patio)
        $alistamiento = Role::firstOrCreate(['name' => 'Jefe de Alistamiento']);
        $alistamiento->syncPermissions([
            'ver inventario',
        ]);

        // 5. Bodega (Administra exclusivamente el almacén de repuestos e insumos)
        $bodega = Role::firstOrCreate(['name' => 'Bodega']);
        $bodega->syncPermissions([
            'ver inventario',
        ]);

        // 6. Jefe de Patio (Vendedor Senior: recibe autos listos, administra el patio y vende)
        $jefePatio = Role::firstOrCreate(['name' => 'Jefe de Patio']);
        $jefePatio->syncPermissions([
            'ver inventario',
            'vender vehiculo',
            'reservar vehiculo',
            'generar cotizaciones',
        ]);

        // 7. Vendedor (Asesor Comercial estándar)
        $vendedor = Role::firstOrCreate(['name' => 'Vendedor']);
        $vendedor->syncPermissions([
            'ver inventario',
            'vender vehiculo',
            'reservar vehiculo',
            'generar cotizaciones',
        ]);

        // 8. Contabilidad
        $contabilidad = Role::firstOrCreate(['name' => 'Contabilidad']);
        $contabilidad->syncPermissions([
            'ver inventario',
            'ver reportes financieros',
            'ver pagos y comprobantes',
        ]);

        // ==========================================
        // 4. USUARIO ADMINISTRADOR POR DEFECTO
        // ==========================================
        $adminUser = User::updateOrCreate(
            ['email' => '2005maldonado@gmail.com'],
            [
                'name' => 'Administrador',
                'password' => Hash::make('Optimus123'),
                'role_id' => $admin->id,
                'email_verified_at' => now(),
            ],
        );

        $adminUser->syncRoles([$admin->name]);
    }
}
