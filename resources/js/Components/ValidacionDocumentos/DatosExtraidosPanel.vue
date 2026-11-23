<script setup>
import { valorTexto } from '@/Composables/useValidacionDocumentoHelpers';

defineProps({
    datosDocumento: {
        type: Object,
        default: () => ({}),
    },
    datosOficiales: {
        type: Object,
        default: () => ({}),
    },
});
</script>

<template>
    <section class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <details class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <summary class="cursor-pointer border-b border-gray-200 bg-gray-50 px-5 py-3 text-sm font-semibold text-gray-900">
                Datos leídos de la matrícula
            </summary>
            <dl class="divide-y divide-gray-100 px-5">
                <div v-for="(valor, campo) in datosDocumento" :key="campo" class="grid grid-cols-2 gap-3 py-3 text-sm">
                    <dt class="break-words font-medium text-gray-600">{{ campo.replaceAll('_', ' ') }}</dt>
                    <dd class="break-words text-gray-900">{{ valorTexto(valor) }}</dd>
                </div>
            </dl>
        </details>

        <details class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <summary class="cursor-pointer border-b border-gray-200 bg-gray-50 px-5 py-3 text-sm font-semibold text-gray-900">
                Datos oficiales de AxisCloud
            </summary>
            <dl v-if="Object.keys(datosOficiales || {}).length" class="divide-y divide-gray-100 px-5">
                <div v-for="(valor, campo) in datosOficiales" :key="campo" class="grid grid-cols-2 gap-3 py-3 text-sm">
                    <dt class="break-words font-medium text-gray-600">{{ campo.replaceAll('_', ' ') }}</dt>
                    <dd class="break-words text-gray-900">{{ valorTexto(valor) }}</dd>
                </div>
            </dl>
            <p v-else class="p-5 text-sm text-gray-500">
                AxisCloud no devolvió datos oficiales para esta consulta.
            </p>
        </details>
    </section>
</template>
