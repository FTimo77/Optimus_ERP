<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

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
            'ver inventario vehiculos',
            'ver historico vehiculos',
            'ver kardex vehiculos',
            'recibir vehiculo en patio',      // Exclusivo Jefe de Patio (recibe de Alistamiento)
            'cambiar ubicacion patio',        // Jefe de Patio (mover entre sucursales/patios)
            'editar informacion vehiculo',    // Admin / Jefe de Patio
            'cambiar estado vehiculo',        // Admin

            // B. Compras (Adquisición de Autos y Repuestos)
            'ver compras',
            'registrar compra vehiculo',
            'editar costo adquisicion',       // Sensible (Solo Compras / Admin)
            'gestionar ordenes compra repuestos',
            'gestionar proveedores',

            // C. Documentación, Matrículas y Archivo Físico (NUEVO DEPARTAMENTO)
            'verificar checklist documentos', // Revisar qué papeles llegaron y cuáles faltan tras aprobar compra
            'gestionar archivo fisico',       // Asignar código de carpeta/gaveta y controlar préstamos de carpetas
            'gestionar matriculas y tramites',// Seguimiento de trámites legales (ANT, notarías, multas)
            'registrar gasto tramite',        // Cargar costos legales/notariales al kardex del auto
            'validar carpeta para venta',     // Dar el "visto bueno" legal para que el auto pueda ser entregado

            // D. Alistamiento / Taller (Recon)
            'recibir vehiculo alistamiento',  // Jefe de Alistamiento (Acta de recepción inicial)
            'preparar vehiculo',              // Crear/cerrar órdenes de trabajo (mecánica, pintura, etc.)
            'solicitar repuestos bodega',     // Pedir insumos para un auto
            'registrar gasto preparacion',    // Mano de obra o talleres externos

            // E. Bodega (Exclusivo Repuestos e Insumos)
            'ver inventario repuestos',
            'recibir compra repuestos',       // Ingresar stock que compró el área de Compras
            'despachar repuestos alistamiento', // Entregar repuesto e imputar el costo al auto
            'ajustar stock repuestos',        // Auditoría de bodega

            // F. Ventas y CRM (Vendedores y Jefe de Patio)
            'gestionar clientes',
            'generar cotizaciones',
            'reservar vehiculo',
            'vender vehiculo',

            // G. Contabilidad y Finanzas
            'facturar y liquidar',
            'gestionar caja y pagos',
            'liquidar comisiones',
            'ver reportes financieros',

            // H. Aprobaciones y Gerencia
            'aprobar compras',
            'aprobar presupuesto alistamiento',
            'aprobar descuentos venta',
            'gestionar usuarios y roles',
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
            'ver inventario vehiculos',
            'ver inventario repuestos',
            'ver compras',
            'registrar compra vehiculo',
            'editar costo adquisicion',
            'gestionar ordenes compra repuestos',
            'gestionar proveedores',
        ]);

        // 3. Documentación (Verifica papeles tras compra aprobada, custodia carpeta física y matrículas)
        $documentacion = Role::firstOrCreate(['name' => 'Documentacion']);
        $documentacion->syncPermissions([
            'ver inventario vehiculos',
            'ver historico vehiculos',
            'ver compras',                    // Necesita ver las compras aprobadas para recibir los papeles
            'verificar checklist documentos',
            'gestionar archivo fisico',
            'gestionar matriculas y tramites',
            'registrar gasto tramite',
            'validar carpeta para venta',
        ]);

        // 4. Jefe de Alistamiento (Recibe el auto físico, lo repara y lo entrega al Jefe de Patio)
        $alistamiento = Role::firstOrCreate(['name' => 'Jefe de Alistamiento']);
        $alistamiento->syncPermissions([
            'ver inventario vehiculos',
            'ver historico vehiculos',
            'recibir vehiculo alistamiento',
            'preparar vehiculo',
            'solicitar repuestos bodega',
            'registrar gasto preparacion',
            'ver inventario repuestos',
        ]);

        // 5. Bodega (Administra exclusivamente el almacén de repuestos e insumos)
        $bodega = Role::firstOrCreate(['name' => 'Bodega']);
        $bodega->syncPermissions([
            'ver inventario repuestos',
            'recibir compra repuestos',
            'despachar repuestos alistamiento',
            'ajustar stock repuestos',
            'ver inventario vehiculos',
        ]);

        // 6. Jefe de Patio (Vendedor Senior: recibe autos listos, administra el patio y vende)
        $jefePatio = Role::firstOrCreate(['name' => 'Jefe de Patio']);
        $jefePatio->syncPermissions([
            'ver inventario vehiculos',
            'ver historico vehiculos',
            'recibir vehiculo en patio',
            'cambiar ubicacion patio',
            'editar informacion vehiculo',
            'gestionar clientes',
            'generar cotizaciones',
            'reservar vehiculo',
            'vender vehiculo',
            'aprobar descuentos venta',
        ]);

        // 7. Vendedor (Asesor Comercial estándar)
        $vendedor = Role::firstOrCreate(['name' => 'Vendedor']);
        $vendedor->syncPermissions([
            'ver inventario vehiculos',
            'gestionar clientes',
            'generar cotizaciones',
            'reservar vehiculo',
            'vender vehiculo',
        ]);

        // 8. Contabilidad
        $contabilidad = Role::firstOrCreate(['name' => 'Contabilidad']);
        $contabilidad->syncPermissions([
            'ver inventario vehiculos',
            'ver kardex vehiculos',
            'ver inventario repuestos',
            'ver compras',
            'facturar y liquidar',
            'gestionar caja y pagos',
            'liquidar comisiones',
            'ver reportes financieros',
        ]);
    }
}