<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    ventas: {
        type: Object,
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
    pendientesAprobacion: {
        type: Number,
        default: 0,
    },
    success: {
        type: String,
        default: null,
    },
});

const formatearMoneda = (valor) =>
    `$${Number(valor || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const estadoVisual = (venta) => {
    if (venta.estado_aprobacion_descuento === 'pendiente') {
        return 'pendiente_aprobacion';
    }
    if (venta.estado_aprobacion_descuento === 'rechazado') {
        return 'descuento_rechazado';
    }
    if (venta.estado === 'pagada' && !venta.tiene_constancia) {
        return 'pendiente_constancia';
    }
    if (venta.estado === 'aprobada' && venta.tiene_constancia) {
        return 'aprobada';
    }

    return venta.estado || 'en_proceso';
};

const estadoTexto = (estado) => ({
    en_proceso: 'En proceso comercial',
    pendiente_aprobacion: 'Pendiente aprobación de descuento',
    descuento_rechazado: 'Descuento rechazado',
    pagada: 'Pago registrado',
    pendiente_constancia: 'Pendiente generar constancia',
    aprobada: 'Completada · Constancia emitida',
}[estado] || estado);

const estadoClases = (estado) => ({
    en_proceso: 'bg-amber-100 text-amber-800',
    pendiente_aprobacion: 'bg-yellow-100 text-yellow-900',
    descuento_rechazado: 'bg-red-100 text-red-800',
    pagada: 'bg-blue-100 text-blue-800',
    pendiente_constancia: 'bg-indigo-100 text-indigo-900',
    aprobada: 'bg-green-100 text-green-800',
}[estado] || 'bg-gray-100 text-gray-800');

const fechaTexto = (fecha) => (
    fecha ? new Date(fecha).toLocaleString('es-EC') : 'No disponible'
);
</script>

<template>
    <Head title="Mis Ventas" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">
                        Mis Ventas y Reservas
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">
                        Gestiona las ventas y reservas iniciadas desde el inventario, comprobantes de pago y constancias legales.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div
                        v-if="pendientesAprobacion > 0"
                        class="rounded-md bg-yellow-100 px-4 py-2 text-sm font-semibold text-yellow-900"
                    >
                        {{ pendientesAprobacion }} descuento{{ pendientesAprobacion === 1 ? '' : 's' }} por aprobar
                    </div>
                    <div class="rounded-md bg-amber-100 px-4 py-2 text-sm font-semibold text-amber-900">
                        {{ totalActivas }} en proceso
                    </div>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6 lg:px-8">
                <div
                    v-if="success"
                    role="status"
                    class="rounded-md border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-800"
                >
                    {{ success }}
                </div>

                <!-- PESTAÑAS: EN PROCESO / HISTORIAL COMPLETADAS -->
                <div class="border-b border-gray-200">
                    <nav class="-mb-px flex flex-wrap gap-6" aria-label="Secciones de Mis Ventas">
                        <Link
                            :href="route('mis-ventas.index')"
                            class="inline-flex items-center gap-2 border-b-2 px-1 py-3 text-sm font-semibold transition"
                            :class="tabActiva === 'activas'
                                ? 'border-yellow-500 text-gray-900'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
                        >
                            <span>En proceso</span>
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
                            :href="route('mis-ventas.index', { tab: 'historial' })"
                            class="inline-flex items-center gap-2 border-b-2 px-1 py-3 text-sm font-semibold transition"
                            :class="tabActiva === 'historial'
                                ? 'border-emerald-600 text-emerald-900'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
                        >
                            <span>Historial completadas</span>
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
                    <div v-if="ventas.data.length === 0" class="p-8 text-center text-sm text-gray-500">
                        {{ tabActiva === 'historial'
                            ? 'Todavía no hay ventas o reservas completadas en el historial.'
                            : 'No tienes vehículos en proceso de venta o reserva. Inicia uno desde el botón «Vender» en Inventario.' }}
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Placa</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Vehículo</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Operación</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Precio Base</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Precio Acordado</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Estado</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Vendedor</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Fecha</th>
                                    <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Expediente</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr v-for="venta in ventas.data" :key="venta.id_venta" class="hover:bg-gray-50">
                                    <td class="whitespace-nowrap px-5 py-4 font-mono text-sm font-semibold text-gray-900">
                                        {{ venta.placa }}
                                    </td>
                                    <td class="px-5 py-4 text-sm text-gray-700">
                                        <div class="font-semibold text-gray-900">
                                            {{ venta.marca }} {{ venta.modelo }}
                                            <span v-if="venta.anio" class="text-xs text-gray-500">({{ venta.anio }})</span>
                                        </div>
                                        <div class="text-xs text-gray-500">{{ venta.estado_vehiculo }}</div>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4">
                                        <span
                                            class="rounded-full px-2.5 py-1 text-xs font-semibold uppercase"
                                            :class="venta.tipo_operacion === 'reserva'
                                                ? 'bg-indigo-100 text-indigo-800'
                                                : 'bg-slate-800 text-yellow-400'"
                                        >
                                            {{ venta.tipo_operacion === 'reserva' ? 'Reserva' : 'Venta' }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 font-mono text-sm text-gray-600">
                                        {{ venta.precio_base > 0 ? formatearMoneda(venta.precio_base) : 'Sin fijar' }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 font-mono text-sm font-bold text-gray-900">
                                        {{ venta.precio_acordado > 0 ? formatearMoneda(venta.precio_acordado) : 'Por definir' }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4">
                                        <span :class="estadoClases(estadoVisual(venta))" class="rounded-full px-2.5 py-1 text-xs font-semibold">
                                            {{ estadoTexto(estadoVisual(venta)) }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">
                                        {{ venta.vendedor }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">
                                        {{ fechaTexto(venta.updated_at || venta.created_at) }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-right text-sm">
                                        <Link
                                            :href="route('mis-ventas.show', venta.id_venta)"
                                            class="font-semibold text-indigo-700 hover:text-indigo-900"
                                        >
                                            {{ tabActiva === 'historial' ? 'Ver expediente' : 'Gestionar' }}
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div
                        v-if="ventas.prev_page_url || ventas.next_page_url"
                        class="flex items-center justify-between border-t border-gray-200 px-5 py-4"
                    >
                        <Link
                            v-if="ventas.prev_page_url"
                            :href="ventas.prev_page_url"
                            class="text-sm font-medium text-indigo-700 hover:text-indigo-900"
                        >
                            Anterior
                        </Link>
                        <span v-else />
                        <span class="text-sm text-gray-500">
                            Página {{ ventas.current_page }} de {{ ventas.last_page }}
                        </span>
                        <Link
                            v-if="ventas.next_page_url"
                            :href="ventas.next_page_url"
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
