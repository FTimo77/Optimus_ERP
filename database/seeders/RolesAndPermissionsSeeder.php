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
            // A. Vehículos y Patio (Comercial)
            'aprobar o rechazar solicitudes de compra',
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
            
        ]);

        // 3. Administración (Verifica papeles tras compra aprobada, custodia carpeta física y matrículas)
        $documentacion = Role::firstOrCreate(['name' => 'Administracion']);
        $documentacion->syncPermissions([
            'aprobar o rechazar solicitudes de compra',
            
        ]);

        // 4. Jefe de Alistamiento (Recibe el auto físico, lo repara y lo entrega al Jefe de Patio)
        $alistamiento = Role::firstOrCreate(['name' => 'Jefe de Alistamiento']);
        $alistamiento->syncPermissions([
            
        ]);

        // 5. Bodega (Administra exclusivamente el almacén de repuestos e insumos)
        $bodega = Role::firstOrCreate(['name' => 'Bodega']);
        $bodega->syncPermissions([
            
        ]);

        // 6. Jefe de Patio (Vendedor Senior: recibe autos listos, administra el patio y vende)
        $jefePatio = Role::firstOrCreate(['name' => 'Jefe de Patio']);
        $jefePatio->syncPermissions([
            
        ]);

        // 7. Vendedor (Asesor Comercial estándar)
        $vendedor = Role::firstOrCreate(['name' => 'Vendedor']);
        $vendedor->syncPermissions([
           
        ]);

        // 8. Contabilidad
        $contabilidad = Role::firstOrCreate(['name' => 'Contabilidad']);
        $contabilidad->syncPermissions([

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