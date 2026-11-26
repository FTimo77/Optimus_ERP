<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

defineProps({
    validaciones: {
        type: Object,
        required: true,
    },
    pendientes: {
        type: Number,
        required: true,
    },
    tabActiva: {
        type: String,
        default: 'activas',
    },
    totalActivas: {
        type: Number,
        default: 0,
    },
    totalHistorial: {
        type: Number,
        default: 0,
    },
});

const estadoVisual = (validacion) => {
    if (validacion.estado_revision === 'aprobada' && !validacion.tiene_contrato) {
        return 'pendiente_constancia';
    }

    return validacion.estado_revision;
};

const estadoTexto = (estado) => ({
    pendiente: 'Pendiente',
    pendiente_comprobante: 'Pendiente subir comprobante de pago',
    pendiente_constancia: 'Pendiente generar constancia',
    aprobada: 'Aprobado',
    rechazada: 'Rechazada',
    registrada: 'Registrada en inventario',
}[estado] || estado);

const estadoClases = (estado) => ({
    pendiente: 'bg-amber-100 text-amber-800',
    pendiente_comprobante: 'bg-yellow-100 text-yellow-900',
    pendiente_constancia: 'bg-indigo-100 text-indigo-900',
    aprobada: 'bg-green-100 text-green-800',
    rechazada: 'bg-red-100 text-red-800',
    registrada: 'bg-blue-100 text-blue-800',
}[estado] || 'bg-gray-100 text-gray-800');

const fechaTexto = (fecha) => (
    fecha ? new Date(fecha).toLocaleString('es-EC') : 'No disponible'
);

const abrirExpediente = (id) => {
    router.visit(route('validacion-documentos.show', id));
};
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
                        Revisa los expedientes en proceso y consulta el historial de solicitudes aprobadas.
                    </p>
                </div>
                <div class="rounded-md bg-amber-100 px-4 py-2 text-sm font-semibold text-amber-900">
                    {{ pendientes }} pendiente{{ pendientes === 1 ? '' : 's' }}
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6 lg:px-8">
                <!-- PESTAÑAS: BANDEJA ACTIVA / HISTORIAL DE APROBACIONES -->
                <div class="border-b border-gray-200">
                    <nav class="-mb-px flex flex-wrap gap-6" aria-label="Secciones de validación">
                        <Link
                            :href="route('validacion-documentos.index')"
                            class="inline-flex items-center gap-2 border-b-2 px-1 py-3 text-sm font-semibold transition"
                            :class="tabActiva === 'activas'
                                ? 'border-yellow-500 text-gray-900'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
                        >
                            <span>En proceso / Por revisar</span>
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-bold"
                                :class="tabActiva === 'activas'
                                    ? 'bg-amber-100 text-amber-900'
                                    : 'bg-gray-100 text-gray-600'"
                            >
                                {{ totalActivas }}
                            </span>
                        </Link>

                        <Link
                            :href="route('validacion-documentos.index', { tab: 'historial' })"
                            class="inline-flex items-center gap-2 border-b-2 px-1 py-3 text-sm font-semibold transition"
                            :class="tabActiva === 'historial'
                                ? 'border-emerald-600 text-emerald-900'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
                        >
                            <span>Historial de aprobaciones</span>
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-bold"
                                :class="tabActiva === 'historial'
                                    ? 'bg-emerald-100 text-emerald-800'
                                    : 'bg-gray-100 text-gray-600'"
                            >
                                {{ totalHistorial }}
                            </span>
                        </Link>
                    </nav>
                </div>

                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div v-if="validaciones.data.length === 0" class="p-8 text-center text-sm text-gray-500">
                        {{ tabActiva === 'historial'
                            ? 'Todavía no hay solicitudes aprobadas en el historial.'
                            : 'No hay documentos pendientes o en proceso por revisar.' }}
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Placa</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Vehículo</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Categoría</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Alertas críticas</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Estado</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        {{ tabActiva === 'historial' ? 'Aprobado por' : 'Subido por' }}
                                    </th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        {{ tabActiva === 'historial' ? 'Fecha de aprobación' : 'Fecha' }}
                                    </th>
                                    <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Expediente</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr
                                    v-for="validacion in validaciones.data"
                                    :key="validacion.id"
                                    tabindex="0"
                                    role="button"
                                    class="cursor-pointer transition hover:bg-amber-50/70 focus:bg-amber-50 focus:outline-none"
                                    @click="abrirExpediente(validacion.id)"
                                    @keydown.enter="abrirExpediente(validacion.id)"
                                >
                                    <td class="whitespace-nowrap px-5 py-4 font-mono text-sm font-semibold text-gray-900">
                                        {{ validacion.placa }}
                                    </td>
                                    <td class="px-5 py-4 text-sm text-gray-700">
                                        {{ validacion.marca }} {{ validacion.modelo }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4">
                                        <span
                                            class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                            :class="validacion.categoria_ingreso === 'empresa'
                                                ? 'bg-slate-800 text-yellow-400'
                                                : 'bg-gray-100 text-gray-700'"
                                        >
                                            {{ validacion.categoria_ingreso === 'empresa' ? 'Empresa' : 'Particular' }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-sm">
                                        <span :class="validacion.alertas_criticas > 0 ? 'font-semibold text-red-700' : 'text-gray-600'">
                                            {{ validacion.alertas_criticas }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4">
                                        <span :class="estadoClases(estadoVisual(validacion))" class="rounded-full px-2.5 py-1 text-xs font-semibold">
                                            {{ estadoTexto(estadoVisual(validacion)) }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">
                                        <template v-if="tabActiva === 'historial'">
                                            <div class="font-medium text-gray-800">{{ validacion.revisado_por || 'No registrado' }}</div>
                                            <div class="text-xs text-gray-400">Subido por: {{ validacion.subido_por }}</div>
                                        </template>
                                        <template v-else>
                                            {{ validacion.subido_por }}
                                        </template>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">
                                        {{ fechaTexto(tabActiva === 'historial' ? (validacion.revisado_en || validacion.created_at) : validacion.created_at) }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-right text-sm">
                                        <Link
                                            :href="route('validacion-documentos.show', validacion.id)"
                                            class="font-semibold text-indigo-700 hover:text-indigo-900"
                                            @click.stop
                                        >
                                            {{ tabActiva === 'historial' ? 'Ver expediente' : 'Revisar' }}
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
