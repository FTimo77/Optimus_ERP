<script setup>
import { campoIngresoTexto, valorTexto } from '@/Composables/useValidacionDocumentoHelpers';

defineProps({
    validacion: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <section
        v-if="validacion.comentario_solicitud || validacion.comentario_reenvio || validacion.datos_ingreso"
        class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm"
    >
        <h3 class="text-sm font-semibold text-gray-900">Solicitud de ingreso al inventario</h3>
        <div v-if="validacion.comentario_solicitud" class="mt-3 rounded-md bg-blue-50 p-3 text-sm text-blue-900">
            <p class="font-semibold">Comentario del solicitante</p>
            <p class="mt-1 whitespace-pre-wrap">{{ validacion.comentario_solicitud }}</p>
        </div>
        <div v-if="validacion.comentario_reenvio" class="mt-3 rounded-md bg-indigo-50 p-3 text-sm text-indigo-900">
            <p class="font-semibold">Comentario del reenvío</p>
            <p class="mt-1 whitespace-pre-wrap">{{ validacion.comentario_reenvio }}</p>
        </div>
        <details v-if="validacion.datos_ingreso" class="mt-3 rounded-md border border-gray-200">
            <summary class="cursor-pointer bg-gray-50 px-4 py-3 text-sm font-semibold text-gray-800">
                Ver datos de adquisición
            </summary>
            <dl class="grid grid-cols-1 gap-x-6 px-4 sm:grid-cols-2">
                <div
                    v-for="(valor, campo) in validacion.datos_ingreso"
                    :key="campo"
                    class="grid grid-cols-2 gap-3 border-b border-gray-100 py-2 text-sm"
                >
                    <dt class="font-medium text-gray-600">{{ campoIngresoTexto(campo) }}</dt>
                    <dd class="break-words text-gray-900">{{ valorTexto(valor) }}</dd>
                </div>
            </dl>
        </details>
    </section>
</template>
