<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    vehiculos: {
        type: Array,
        required: true,
    },
});
</script>

<template>
    <Head title="Inventario de Vehículos" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Inventario General de Vehículos
                </h2>
                <Link
                    :href="route('vehiculos.create')"
                    class="rounded-md bg-yellow-500 px-4 py-2 text-sm font-bold text-black shadow hover:bg-yellow-600"
                >
                    + Nuevo Ingreso
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-800 text-white">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-bold uppercase">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-bold uppercase">Vehículo</th>
                                <th class="px-6 py-3 text-left text-xs font-bold uppercase">Año / Color</th>
                                <th class="px-6 py-3 text-left text-xs font-bold uppercase">Combustible</th>
                                <th class="px-6 py-3 text-left text-xs font-bold uppercase">Chasis</th>
                                <th class="px-6 py-3 text-left text-xs font-bold uppercase">Patio</th>
                                <th class="px-6 py-3 text-left text-xs font-bold uppercase">Precio</th>
                                <th class="px-6 py-3 text-left text-xs font-bold uppercase">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <tr
                                v-for="vehiculo in vehiculos"
                                :key="vehiculo.id_vehiculo"
                                class="hover:bg-gray-50"
                            >
                                <td class="px-6 py-4 text-sm font-bold text-gray-900">
                                    #{{ vehiculo.id_vehiculo }}
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <div class="font-bold">
                                        {{ vehiculo.marca?.nombre }} {{ vehiculo.modelo }}
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        {{ vehiculo.clase?.nombre }}
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700">
                                    {{ vehiculo.anio }} - {{ vehiculo.color?.nombre }}
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700">
                                    {{ vehiculo.tipo_combustible?.nombre }}
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-gray-600">
                                    {{ vehiculo.chasis }}
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700">
                                    {{ vehiculo.patio?.nombre }}
                                </td>
                                <td class="px-6 py-4 text-sm font-bold text-green-700">
                                    ${{ Number(vehiculo.precio_venta).toLocaleString() }}
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700">
                                    {{ vehiculo.estado?.nombre }}
                                </td>
                            </tr>
                            <tr v-if="vehiculos.length === 0">
                                <td colspan="8" class="px-6 py-8 text-center text-gray-500">
                                    No hay vehículos registrados en el inventario todavía.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
