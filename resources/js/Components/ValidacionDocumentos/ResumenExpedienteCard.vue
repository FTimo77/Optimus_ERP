<script setup>
import { fechaTexto } from '@/Composables/useValidacionDocumentoHelpers';

defineProps({
    validacion: {
        type: Object,
        required: true,
    },
    cuvUrlActual: {
        type: String,
        default: null,
    },
    cedulaUrlActual: {
        type: String,
        default: null,
    },
    contratoUrlActual: {
        type: String,
        default: null,
    },
});
</script>

<template>
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
        <div class="flex flex-wrap items-end gap-2">
            <a
                v-if="validacion.documento_url"
                :href="validacion.documento_url"
                class="inline-flex rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700"
            >
                Descargar matrícula
            </a>
            <a
                v-if="cuvUrlActual"
                :href="cuvUrlActual"
                class="inline-flex rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-600"
            >
                Descargar CUV
            </a>
            <a
                v-if="validacion.comprobante_url"
                :href="validacion.comprobante_url"
                class="inline-flex rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-600"
            >
                Descargar comprobante
            </a>
            <a
                v-if="cedulaUrlActual"
                :href="cedulaUrlActual"
                class="inline-flex rounded-md bg-slate-700 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-600"
            >
                Descargar cédula
            </a>
            <a
                v-if="contratoUrlActual"
                :href="contratoUrlActual"
                class="inline-flex rounded-md bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-600"
            >
                Descargar contrato (.docx)
            </a>
        </div>
    </section>
</template>
