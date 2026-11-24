<script setup>
import Modal from '@/Components/Modal.vue';
import axios from 'axios';
import { reactive, ref } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    validacion: {
        type: Object,
        required: true,
    },
    cedulaUrlActual: {
        type: String,
        default: null,
    },
});

const emit = defineEmits(['close', 'cedula-actualizada', 'constancia-generada']);

const archivoCedula = ref(null);
const escaneandoCedula = ref(false);
const errorCedula = ref('');
const avisoCedula = ref('');
const exitoCedula = ref('');
const generandoConstancia = ref(false);
const errorConstancia = ref('');

const datosCedula = reactive({
    nombre_completo: props.validacion.datos_cedula_cliente?.nombre_completo || '',
    cedula: props.validacion.datos_cedula_cliente?.cedula || '',
    estado_civil: props.validacion.datos_cedula_cliente?.estado_civil || '',
    es_casado: Boolean(props.validacion.datos_cedula_cliente?.es_casado),
    nombre_conyuge: props.validacion.datos_cedula_cliente?.nombre_conyuge || '',
    cedula_conyuge: props.validacion.datos_cedula_cliente?.cedula_conyuge || '',
});

const seleccionarCedula = (event) => {
    archivoCedula.value = event.target.files[0] || null;
    errorCedula.value = '';
    avisoCedula.value = '';
    exitoCedula.value = '';
};

const escanearCedulaConGemini = async () => {
    if (!archivoCedula.value) {
        errorCedula.value = 'Selecciona primero una foto o PDF de la cédula del cliente.';
        return;
    }

    escaneandoCedula.value = true;
    errorCedula.value = '';
    avisoCedula.value = '';
    exitoCedula.value = '';

    const formData = new FormData();
    formData.append('documento_cedula', archivoCedula.value);

    try {
        const res = await axios.post(
            route('validacion-documentos.escanear.cedula', props.validacion.id),
            formData,
            {
                headers: {
                    'Content-Type': 'multipart/form-data',
                    Accept: 'application/json',
                },
                timeout: 65000,
            },
        );

        if (res.data?.cedula_url) {
            emit('cedula-actualizada', res.data.cedula_url);
        }

        if (res.data?.ocr_fallo) {
            avisoCedula.value = res.data.warning
                || 'Gemini no respondió o no pudo analizar la cédula, pero el archivo sí se almacenó correctamente en el sistema. Completa los datos manualmente abajo.';
        } else if (res.data?.success) {
            const d = res.data.datos_cedula_cliente || {};
            datosCedula.nombre_completo = d.nombre_completo || '';
            datosCedula.cedula = d.cedula || '';
            datosCedula.estado_civil = d.estado_civil || '';
            datosCedula.es_casado = Boolean(d.es_casado);
            datosCedula.nombre_conyuge = d.nombre_conyuge || '';
            if (d.cedula_conyuge !== undefined) {
                datosCedula.cedula_conyuge = d.cedula_conyuge || '';
            }
            exitoCedula.value = 'Cédula almacenada y datos extraídos correctamente con Gemini.';
        } else {
            errorCedula.value = res.data?.message || 'No se pudo procesar la cédula con Gemini.';
        }
    } catch (error) {
        const data = typeof error.response?.data === 'object' && error.response?.data !== null
            ? error.response.data
            : null;

        if (data?.cedula_url) {
            emit('cedula-actualizada', data.cedula_url);
            avisoCedula.value = data.warning
                || 'Gemini no pudo analizar la cédula, pero el archivo sí se almacenó correctamente en el sistema. Completa los datos manualmente.';
        } else if (error.code === 'ECONNABORTED') {
            errorCedula.value = 'Se agotó el tiempo de espera con Gemini. Si el archivo ya aparece arriba en «Ver cédula guardada», puedes llenar los datos manualmente.';
        } else {
            const errores = Object.values(data?.errors || {}).flat();
            errorCedula.value = errores[0]
                || data?.message
                || `Error de conexión con el servidor (${error.response?.status || 'sin respuesta'}) al escanear la cédula con Gemini.`;
        }
    } finally {
        escaneandoCedula.value = false;
    }
};

const generarYDescargarConstancia = async () => {
    generandoConstancia.value = true;
    errorConstancia.value = '';

    try {
        const res = await axios.post(
            route('validacion-documentos.generar.constancia', props.validacion.id),
            {
                nombre_completo: datosCedula.nombre_completo,
                cedula: datosCedula.cedula,
                estado_civil: datosCedula.estado_civil,
                es_casado: datosCedula.es_casado,
                nombre_conyuge: datosCedula.es_casado ? datosCedula.nombre_conyuge : '',
                cedula_conyuge: datosCedula.es_casado ? datosCedula.cedula_conyuge : '',
            },
        );

        if (res.data?.success && res.data.contrato_url) {
            emit('constancia-generada', res.data.contrato_url);
            emit('close');

            const enlace = document.createElement('a');
            enlace.href = res.data.contrato_url;
            enlace.setAttribute('download', '');
            document.body.appendChild(enlace);
            enlace.click();
            document.body.removeChild(enlace);
        } else {
            errorConstancia.value = res.data?.message || 'No se pudo generar el contrato.';
        }
    } catch (error) {
        const errores = Object.values(error.response?.data?.errors || {}).flat();
        errorConstancia.value = errores[0] || error.response?.data?.message || 'Error al generar la constancia.';
    } finally {
        generandoConstancia.value = false;
    }
};
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="emit('close')">
        <div class="p-6">
            <div class="flex items-start justify-between gap-4 border-b border-gray-200 pb-4">
                <div>
                    <h3 class="text-lg font-bold text-gray-900">
                        Generar Constancia · Contrato de Compra-Venta
                    </h3>
                    <p class="mt-1 text-xs text-gray-600">
                        Sube la cédula del vendedor para extraer sus datos con Gemini, revísalos y genera el documento Word listo para imprimir y firmar.
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded-md px-2 py-1 text-sm font-semibold text-gray-500 hover:bg-gray-100 hover:text-gray-800"
                    @click="emit('close')"
                >
                    ✕
                </button>
            </div>

            <div class="mt-5 space-y-5">
                <!-- Paso 1: Subir y escanear cédula -->
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <label for="documento-cedula-cliente" class="text-sm font-semibold text-gray-900">
                            1. Cédula escaneada del cliente (vendedor)
                        </label>
                        <a
                            v-if="cedulaUrlActual"
                            :href="cedulaUrlActual"
                            class="text-xs font-semibold text-indigo-700 hover:text-indigo-900"
                        >
                            Ver cédula guardada
                        </a>
                    </div>
                    <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center">
                        <input
                            id="documento-cedula-cliente"
                            type="file"
                            :disabled="escaneandoCedula"
                            accept="image/jpeg,image/png,image/webp,.pdf"
                            class="block w-full text-xs text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-900 file:px-3 py-2 file:text-white hover:file:bg-gray-700"
                            @change="seleccionarCedula"
                        />
                        <button
                            type="button"
                            :disabled="escaneandoCedula || !archivoCedula"
                            class="whitespace-nowrap rounded-md bg-yellow-500 px-4 py-2 text-xs font-bold text-gray-900 shadow-sm hover:bg-yellow-400 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="escanearCedulaConGemini"
                        >
                            {{ escaneandoCedula ? 'Escaneando con Gemini...' : 'Escanear cédula con IA' }}
                        </button>
                    </div>
                    <p class="mt-1 text-[11px] text-gray-500">
                        Formatos permitidos: JPG, PNG, WEBP o PDF (máximo 5 MB).
                    </p>
                    <div
                        v-if="escaneandoCedula"
                        role="progressbar"
                        class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-200"
                    >
                        <div class="h-full w-1/3 animate-pulse rounded-full bg-yellow-500" />
                    </div>
                    <div
                        v-if="exitoCedula"
                        role="status"
                        class="mt-2 rounded-md border border-emerald-300 bg-emerald-50 p-2.5 text-xs font-medium text-emerald-900"
                    >
                        ✅ {{ exitoCedula }}
                    </div>
                    <div
                        v-if="avisoCedula"
                        role="alert"
                        class="mt-2 rounded-md border-2 border-amber-400 bg-amber-50 p-3 text-xs text-amber-950"
                    >
                        <p class="font-bold text-amber-900">
                            ⚠️ Gemini no pudo analizar la cédula (archivo guardado correctamente)
                        </p>
                        <p class="mt-1">{{ avisoCedula }}</p>
                    </div>
                    <div
                        v-if="errorCedula"
                        role="alert"
                        class="mt-2 rounded-md border-2 border-red-300 bg-red-50 p-3 text-xs text-red-900"
                    >
                        <p class="font-bold text-red-800">❌ Error al escanear la cédula</p>
                        <p class="mt-1">{{ errorCedula }}</p>
                    </div>
                </div>

                <!-- Paso 2: Datos editables del vendedor -->
                <form class="space-y-4" @submit.prevent="generarYDescargarConstancia">
                    <p class="text-sm font-semibold text-gray-900">
                        2. Datos del vendedor para el contrato
                    </p>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="constancia-nombre" class="block text-xs font-medium text-gray-700">
                                Nombre completo del vendedor *
                            </label>
                            <input
                                id="constancia-nombre"
                                v-model="datosCedula.nombre_completo"
                                type="text"
                                required
                                maxlength="200"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="EJ. JUAN CARLOS PÉREZ GÓMEZ"
                            />
                        </div>
                        <div>
                            <label for="constancia-cedula" class="block text-xs font-medium text-gray-700">
                                Número de cédula *
                            </label>
                            <input
                                id="constancia-cedula"
                                v-model="datosCedula.cedula"
                                type="text"
                                required
                                maxlength="30"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="1801234567"
                            />
                        </div>
                        <div>
                            <label for="constancia-estado-civil" class="block text-xs font-medium text-gray-700">
                                Estado civil
                            </label>
                            <input
                                id="constancia-estado-civil"
                                v-model="datosCedula.estado_civil"
                                type="text"
                                maxlength="60"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="SOLTERO / CASADO"
                            />
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <input
                            id="constancia-es-casado"
                            v-model="datosCedula.es_casado"
                            type="checkbox"
                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                        />
                        <label for="constancia-es-casado" class="text-xs font-medium text-gray-800">
                            El vendedor es casado / unión de hecho (incluir cónyuge en el contrato)
                        </label>
                    </div>

                    <div v-if="datosCedula.es_casado" class="grid grid-cols-1 gap-4 rounded-md border border-indigo-200 bg-indigo-50/50 p-3 sm:grid-cols-2">
                        <div>
                            <label for="constancia-conyuge" class="block text-xs font-medium text-gray-700">
                                Nombre completo del cónyuge *
                            </label>
                            <input
                                id="constancia-conyuge"
                                v-model="datosCedula.nombre_conyuge"
                                type="text"
                                :required="datosCedula.es_casado"
                                maxlength="200"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                        </div>
                        <div>
                            <label for="constancia-ci-conyuge" class="block text-xs font-medium text-gray-700">
                                Cédula del cónyuge (opcional)
                            </label>
                            <input
                                id="constancia-ci-conyuge"
                                v-model="datosCedula.cedula_conyuge"
                                type="text"
                                maxlength="30"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                        </div>
                    </div>

                    <!-- Resumen del vehículo -->
                    <div v-if="validacion.vehiculo_contrato" class="rounded-md border border-gray-200 bg-gray-50 p-3 text-xs text-gray-700">
                        <p class="font-semibold text-gray-900">Datos del vehículo que se insertarán en el contrato</p>
                        <div class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4">
                            <span><strong>Placa:</strong> {{ validacion.vehiculo_contrato.id_vehiculo }}</span>
                            <span><strong>Marca:</strong> {{ validacion.vehiculo_contrato.marca || 'N/D' }}</span>
                            <span><strong>Modelo:</strong> {{ validacion.vehiculo_contrato.modelo || 'N/D' }}</span>
                            <span><strong>Año:</strong> {{ validacion.vehiculo_contrato.anio || 'N/D' }}</span>
                            <span><strong>Color:</strong> {{ validacion.vehiculo_contrato.color || 'N/D' }}</span>
                            <span><strong>Chasis:</strong> {{ validacion.vehiculo_contrato.chasis || 'N/D' }}</span>
                            <span><strong>Motor:</strong> {{ validacion.vehiculo_contrato.motor || 'N/D' }}</span>
                            <span><strong>Valor:</strong> ${{ Number(validacion.vehiculo_contrato.costo_adquisicion || 0).toFixed(2) }}</span>
                        </div>
                    </div>

                    <p v-if="errorConstancia" role="alert" class="rounded-md border border-red-200 bg-red-50 p-3 text-xs font-medium text-red-700">
                        {{ errorConstancia }}
                    </p>

                    <div class="flex flex-col-reverse justify-end gap-3 border-t border-gray-200 pt-4 sm:flex-row">
                        <button
                            type="button"
                            class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            @click="emit('close')"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="generandoConstancia || !datosCedula.nombre_completo.trim() || !datosCedula.cedula.trim()"
                            class="rounded-md bg-gray-900 px-5 py-2 text-sm font-semibold text-yellow-400 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ generandoConstancia ? 'Generando contrato...' : 'Generar y descargar contrato (.docx)' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Modal>
</template>
