<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    validacion: {
        type: Object,
        required: true,
    },
    success: String,
});

const form = useForm({
    decision: '',
    comentario_revision: '',
});

const estadoTexto = (estado) => ({
    pendiente: 'Pendiente de revisión',
    aprobada: 'Aprobada',
    rechazada: 'Rechazada',
}[estado] || estado);

const estadoClases = (estado) => ({
    pendiente: 'bg-amber-100 text-amber-900',
    aprobada: 'bg-green-100 text-green-900',
    rechazada: 'bg-red-100 text-red-900',
}[estado] || 'bg-gray-100 text-gray-800');

const fechaTexto = (fecha) => fecha
    ? new Date(fecha).toLocaleString('es-EC')
    : 'No disponible';

const valorTexto = (valor) => {
    if (valor === null || valor === undefined || valor === '') {
        return 'No disponible';
    }

    return typeof valor === 'object' ? JSON.stringify(valor) : String(valor);
};

const decidir = (decision) => {
    form.decision = decision;
    form.patch(route('validacion-documentos.decidir', props.validacion.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="`Expediente ${validacion.placa}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                <div>
                    <Link
                        :href="route('validacion-documentos.index')"
                        class="text-sm font-medium text-indigo-700 hover:text-indigo-900"
                    >
                        ← Volver a la bandeja
                    </Link>
                    <h2 class="mt-2 text-xl font-semibold leading-tight text-gray-800">
                        Revisión de matrícula: {{ validacion.placa }}
                    </h2>
                </div>
                <span :class="estadoClases(validacion.estado_revision)" class="w-fit rounded-full px-3 py-1 text-sm font-semibold">
                    {{ estadoTexto(validacion.estado_revision) }}
                </span>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div v-if="success" role="status" class="rounded-md border border-green-200 bg-green-50 p-4 text-sm text-green-800">
                    {{ success }}
                </div>

                <section class="grid grid-cols-1 gap-4 rounded-lg border border-gray-200 bg-white p-5 shadow-sm sm:grid-cols-2">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Subido por</p>
                        <p class="mt-1 text-sm text-gray-900">{{ validacion.subido_por }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Fecha de escaneo</p>
                        <p class="mt-1 text-sm text-gray-900">{{ fechaTexto(validacion.created_at) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Alertas críticas de IA</p>
                        <p class="mt-1 text-sm font-semibold" :class="validacion.alertas_criticas > 0 ? 'text-red-700' : 'text-green-700'">
                            {{ validacion.alertas_criticas }}
                        </p>
                    </div>
                    <div class="flex items-end">
                        <a
                            :href="validacion.documento_url"
                            class="inline-flex rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700"
                        >
                            Descargar matrícula
                        </a>
                    </div>
                </section>

                <section class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                        <h3 class="border-b border-gray-200 bg-gray-50 px-5 py-3 text-sm font-semibold text-gray-900">
                            Datos leídos de la matrícula
                        </h3>
                        <dl class="divide-y divide-gray-100 px-5">
                            <div v-for="(valor, campo) in validacion.datos_documento" :key="campo" class="grid grid-cols-2 gap-3 py-3 text-sm">
                                <dt class="break-words font-medium text-gray-600">{{ campo.replaceAll('_', ' ') }}</dt>
                                <dd class="break-words text-gray-900">{{ valorTexto(valor) }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                        <h3 class="border-b border-gray-200 bg-gray-50 px-5 py-3 text-sm font-semibold text-gray-900">
                            Datos oficiales de AxisCloud
                        </h3>
                        <dl v-if="Object.keys(validacion.datos_oficiales).length" class="divide-y divide-gray-100 px-5">
                            <div v-for="(valor, campo) in validacion.datos_oficiales" :key="campo" class="grid grid-cols-2 gap-3 py-3 text-sm">
                                <dt class="break-words font-medium text-gray-600">{{ campo.replaceAll('_', ' ') }}</dt>
                                <dd class="break-words text-gray-900">{{ valorTexto(valor) }}</dd>
                            </div>
                        </dl>
                        <p v-else class="p-5 text-sm text-gray-500">
                            AxisCloud no devolvió datos oficiales para esta consulta.
                        </p>
                    </div>
                </section>

                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <h3 class="border-b border-gray-200 bg-gray-50 px-5 py-3 text-sm font-semibold text-gray-900">
                        Comparación automática
                    </h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-white text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-5 py-3">Campo</th>
                                    <th class="px-5 py-3">Matrícula</th>
                                    <th class="px-5 py-3">AxisCloud</th>
                                    <th class="px-5 py-3">Estado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="(item, index) in validacion.comparacion" :key="`${item.clave}-${index}`">
                                    <td class="px-5 py-3 font-medium text-gray-800">{{ item.campo }}</td>
                                    <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ item.documento }}</td>
                                    <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ item.sistema_web }}</td>
                                    <td class="px-5 py-3">
                                        <span
                                            :class="{
                                                'bg-green-100 text-green-800': item.estado === 'coincide',
                                                'bg-amber-100 text-amber-800': item.estado === 'casi_coincide',
                                                'bg-gray-100 text-gray-700': item.estado === 'no_verificable',
                                                'bg-red-100 text-red-800': item.estado === 'diferente',
                                            }"
                                            class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                        >
                                            {{ item.estado.replaceAll('_', ' ') }}
                                        </span>
                                        <p v-if="item.nota" class="mt-1 max-w-xs text-xs text-gray-500">
                                            {{ item.nota }}
                                        </p>
                                    </td>
                                </tr>
                                <tr v-if="validacion.comparacion.length === 0">
                                    <td colspan="4" class="px-5 py-6 text-center text-gray-500">
                                        No hay campos comparables para mostrar.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h3 class="text-sm font-semibold text-gray-900">Decisión humana</h3>
                    <div v-if="validacion.estado_revision === 'pendiente'" class="mt-4 space-y-4">
                        <div>
                            <label for="comentario-revision" class="block text-sm font-medium text-gray-700">
                                Comentario (opcional)
                            </label>
                            <textarea
                                id="comentario-revision"
                                v-model="form.comentario_revision"
                                rows="3"
                                maxlength="2000"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Agrega una observación para el expediente..."
                            />
                        </div>
                        <p v-if="form.errors.decision || form.errors.comentario_revision" class="text-sm text-red-700">
                            {{ form.errors.decision || form.errors.comentario_revision }}
                        </p>
                        <div class="flex flex-col gap-3 sm:flex-row">
                            <button
                                type="button"
                                :disabled="form.processing"
                                class="rounded-md bg-green-700 px-5 py-2 text-sm font-semibold text-white hover:bg-green-800 disabled:opacity-50"
                                @click="decidir('aprobada')"
                            >
                                Aprobar documento
                            </button>
                            <button
                                type="button"
                                :disabled="form.processing"
                                class="rounded-md bg-red-700 px-5 py-2 text-sm font-semibold text-white hover:bg-red-800 disabled:opacity-50"
                                @click="decidir('rechazada')"
                            >
                                Rechazar documento
                            </button>
                        </div>
                    </div>
                    <div v-else class="mt-3 space-y-2 text-sm text-gray-700">
                        <p>
                            Revisado por {{ validacion.revisado_por || 'Usuario no disponible' }}
                            el {{ fechaTexto(validacion.revisado_en) }}.
                        </p>
                        <p v-if="validacion.comentario_revision" class="whitespace-pre-wrap rounded-md bg-gray-50 p-3">
                            {{ validacion.comentario_revision }}
                        </p>
                        <p v-else class="text-gray-500">No se dejó un comentario.</p>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
