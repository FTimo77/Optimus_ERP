<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    validaciones: {
        type: Object,
        required: true,
    },
    pendientes: {
        type: Number,
        required: true,
    },
});

const estadoTexto = (estado) => ({
    pendiente: 'Pendiente',
    aprobada: 'Aprobada',
    rechazada: 'Rechazada',
}[estado] || estado);

const estadoClases = (estado) => ({
    pendiente: 'bg-amber-100 text-amber-800',
    aprobada: 'bg-green-100 text-green-800',
    rechazada: 'bg-red-100 text-red-800',
}[estado] || 'bg-gray-100 text-gray-800');

const fechaTexto = (fecha) => fecha
    ? new Date(fecha).toLocaleString('es-EC')
    : 'No disponible';
</script>

<template>
    <Head title="Validación de documentos" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">
                        Validación de documentos
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">
                        Revisa los expedientes creados después del análisis automático.
                    </p>
                </div>
                <div class="rounded-md bg-amber-100 px-4 py-2 text-sm font-semibold text-amber-900">
                    {{ pendientes }} pendiente{{ pendientes === 1 ? '' : 's' }}
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6 lg:px-8">
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div v-if="validaciones.data.length === 0" class="p-8 text-center text-sm text-gray-500">
                        Todavía no hay documentos para revisar.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Placa</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Vehículo</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Alertas críticas</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Estado</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Subido por</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Fecha</th>
                                    <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Expediente</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr v-for="validacion in validaciones.data" :key="validacion.id">
                                    <td class="whitespace-nowrap px-5 py-4 font-mono text-sm font-semibold text-gray-900">
                                        {{ validacion.placa }}
                                    </td>
                                    <td class="px-5 py-4 text-sm text-gray-700">
                                        {{ validacion.marca }} {{ validacion.modelo }}
                                    </td>
                                    <td class="px-5 py-4 text-sm">
                                        <span :class="validacion.alertas_criticas > 0 ? 'font-semibold text-red-700' : 'text-gray-600'">
                                            {{ validacion.alertas_criticas }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4">
                                        <span :class="estadoClases(validacion.estado_revision)" class="rounded-full px-2.5 py-1 text-xs font-semibold">
                                            {{ estadoTexto(validacion.estado_revision) }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">
                                        {{ validacion.subido_por }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">
                                        {{ fechaTexto(validacion.created_at) }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-right text-sm">
                                        <Link
                                            :href="route('validacion-documentos.show', validacion.id)"
                                            class="font-semibold text-indigo-700 hover:text-indigo-900"
                                        >
                                            Revisar
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div
                        v-if="validaciones.prev_page_url || validaciones.next_page_url"
                        class="flex items-center justify-between border-t border-gray-200 px-5 py-4"
                    >
                        <Link
                            v-if="validaciones.prev_page_url"
                            :href="validaciones.prev_page_url"
                            class="text-sm font-medium text-indigo-700 hover:text-indigo-900"
                        >
                            Anterior
                        </Link>
                        <span v-else />
                        <span class="text-sm text-gray-500">
                            Página {{ validaciones.current_page }} de {{ validaciones.last_page }}
                        </span>
                        <Link
                            v-if="validaciones.next_page_url"
                            :href="validaciones.next_page_url"
                            class="text-sm font-medium text-indigo-700 hover:text-indigo-900"
                        >
                            Siguiente
                        </Link>
                        <span v-else />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
