<script setup>
import axios from 'axios';
import { ref } from 'vue';

const props = defineProps({
    endpointUrl: {
        type: String,
        required: true,
    },
    titulo: {
        type: String,
        default: 'Escáner IA de Matrícula + Auditoría Oficial (AxisCloud · SRI · Autority · Propietario)',
    },
    subtitulo: {
        type: String,
        default: 'Sube la foto o PDF de la matrícula para extraer sus datos con IA y validarlos contra las fuentes oficiales en tiempo real.',
    },
    textoBoton: {
        type: String,
        default: 'Auditar Matrícula',
    },
    inputIdPrefix: {
        type: String,
        default: 'escaner-matricula',
    },
});

const emit = defineEmits(['file-selected', 'success', 'error']);

const archivoMatricula = ref(null);
const matriculaInput = ref(null);
const escaneandoIA = ref(false);
const errorAuditoria = ref('');
const erroresValidacionAuditoria = ref([]);

const seleccionarMatricula = (event) => {
    archivoMatricula.value = event.target.files[0] || null;
    errorAuditoria.value = '';
    erroresValidacionAuditoria.value = [];
    emit('file-selected', archivoMatricula.value);
};

const escanearYComparar = async () => {
    if (!archivoMatricula.value) {
        errorAuditoria.value = 'Selecciona primero una foto o PDF de la matrícula.';
        erroresValidacionAuditoria.value = [];
        return;
    }

    escaneandoIA.value = true;
    errorAuditoria.value = '';
    erroresValidacionAuditoria.value = [];

    const formData = new FormData();
    formData.append('documento', archivoMatricula.value);

    const manejarError = (respuesta, mensajeAlternativo) => {
        const message = respuesta?.message || mensajeAlternativo;
        const errors = Object.values(respuesta?.errors || {})
            .flat()
            .filter((mensaje) => typeof mensaje === 'string');

        errorAuditoria.value = message;
        erroresValidacionAuditoria.value = errors;
        emit('error', {
            message,
            errors,
            manualReviewAvailable: respuesta?.manual_review_available === true,
            file: archivoMatricula.value,
        });
    };

    try {
        const res = await axios.post(props.endpointUrl, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (res.data && res.data.success) {
            emit('success', res.data);
        } else {
            manejarError(res.data, 'El servidor no devolvió una respuesta válida.');
        }
    } catch (error) {
        manejarError(error.response?.data, error.message || 'Error al escanear el documento.');
    } finally {
        escaneandoIA.value = false;
    }
};

const reset = () => {
    if (matriculaInput.value) {
        matriculaInput.value.value = '';
    }
    archivoMatricula.value = null;
    errorAuditoria.value = '';
    erroresValidacionAuditoria.value = [];
};

defineExpose({ reset });
</script>

<template>
    <div class="rounded-lg border border-slate-700 bg-slate-900 p-5 text-white shadow-md">
        <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
            <div>
                <h3 class="text-sm font-extrabold uppercase tracking-wider text-yellow-400">
                    {{ titulo }}
                </h3>
                <p class="mt-1 text-xs text-gray-300">
                    {{ subtitulo }}
                </p>
                <p class="mt-1 text-xs text-gray-400">
                    El documento se enviará a Google Gemini y se consultarán simultáneamente Propietario, Multas, Valores SRI y Ficha Técnica.
                </p>
            </div>

            <div class="flex w-full flex-col gap-3 md:w-auto md:flex-row md:items-end">
                <div>
                    <label
                        :for="`${inputIdPrefix}-archivo`"
                        class="mb-1 block text-xs font-semibold text-gray-200"
                    >
                        Matrícula
                    </label>
                    <input
                        :id="`${inputIdPrefix}-archivo`"
                        ref="matriculaInput"
                        type="file"
                        :disabled="escaneandoIA"
                        accept="image/jpeg,image/png,image/webp,.pdf"
                        aria-label="Seleccionar matrícula en PDF o imagen"
                        class="text-xs text-gray-300 file:mr-3 file:rounded file:border-0 file:bg-gray-700 file:px-3 file:py-1.5 file:text-white hover:file:bg-gray-600"
                        @change="seleccionarMatricula"
                    />
                </div>
                <button
                    type="button"
                    :disabled="escaneandoIA"
                    class="whitespace-nowrap rounded bg-yellow-500 px-4 py-2 text-xs font-bold text-black shadow hover:bg-yellow-600 disabled:opacity-50"
                    @click="escanearYComparar"
                >
                    {{ escaneandoIA ? 'Auditando IA + 4 Fuentes Web...' : textoBoton }}
                </button>
                <slot name="actions" />
            </div>
        </div>

        <div
            v-if="escaneandoIA"
            role="progressbar"
            aria-label="Auditando matrícula"
            class="mt-4 h-1.5 overflow-hidden rounded-full bg-gray-700"
        >
            <div class="h-full w-1/3 animate-pulse rounded-full bg-yellow-400" />
        </div>

        <div
            v-if="errorAuditoria"
            role="alert"
            aria-live="assertive"
            class="mt-3 rounded border border-red-500 bg-red-900/50 p-3 text-xs text-red-200"
        >
            <p>{{ errorAuditoria }}</p>
            <ul
                v-if="erroresValidacionAuditoria.length"
                class="mt-2 list-inside list-disc space-y-1"
            >
                <li v-for="(error, index) in erroresValidacionAuditoria" :key="index">
                    {{ error }}
                </li>
            </ul>
            <slot name="error-extra" />
        </div>

        <slot />
    </div>
</template>
