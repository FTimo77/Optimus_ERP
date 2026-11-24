<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    vehiculo: {
        type: Object,
        required: true,
    },
});

const esVehiculoNuevo = computed(
    () => String(props.vehiculo.metodo_ingreso || '').trim().toLowerCase() === 'nuevos',
);

const documentosExpediente = computed(() =>
    Array.isArray(props.vehiculo.documentos_expediente)
        ? props.vehiculo.documentos_expediente
        : [],
);

const documentosNuevo = computed(() => {
    if (Array.isArray(props.vehiculo.documentos_nuevo) && props.vehiculo.documentos_nuevo.length > 0) {
        return props.vehiculo.documentos_nuevo;
    }

    return [
        { clave: 'matricula', nombre: 'Matrícula', disponible: false, ver_url: null, descargar_url: null },
        { clave: 'revision', nombre: 'Revisión (RTV / Inspección)', disponible: false, ver_url: null, descargar_url: null },
        { clave: 'factura', nombre: 'Factura', disponible: false, ver_url: null, descargar_url: null },
    ];
});

const archivosSeleccionados = reactive({
    matricula: null,
    revision: null,
    factura: null,
});

const inputsArchivo = reactive({
    matricula: null,
    revision: null,
    factura: null,
});

const tipoSubiendo = ref('');
const errorPorTipo = reactive({
    matricula: '',
    revision: '',
    factura: '',
});

const subirForm = useForm({
    tipo: '',
    archivo: null,
});

const seleccionarArchivo = (clave, event) => {
    archivosSeleccionados[clave] = event.target.files?.[0] || null;
    errorPorTipo[clave] = '';
};

const subirDocumentoNuevo = (clave) => {
    const archivo = archivosSeleccionados[clave];
    if (!archivo || subirForm.processing) {
        return;
    }

    errorPorTipo[clave] = '';
    tipoSubiendo.value = clave;
    subirForm.tipo = clave;
    subirForm.archivo = archivo;

    subirForm.post(route('vehiculos.documentos.nuevo.store', props.vehiculo.id_vehiculo), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            archivosSeleccionados[clave] = null;
            if (inputsArchivo[clave]) {
                inputsArchivo[clave].value = '';
            }
            subirForm.reset();
        },
        onError: (errors) => {
            errorPorTipo[clave] = errors.archivo || errors.tipo || 'No se pudo subir el archivo.';
        },
        onFinish: () => {
            tipoSubiendo.value = '';
        },
    });
};

const totalCargadosNuevo = computed(
    () => documentosNuevo.value.filter((doc) => doc.disponible).length,
);
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-gray-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 px-4 py-3">
            <div>
                <h4 class="text-sm font-bold uppercase tracking-wide text-gray-800">
                    {{
                        esVehiculoNuevo
                            ? 'B. Documentación de Vehículo Nuevo'
                            : 'B. Expediente Documental del Vehículo'
                    }}
                </h4>
                <p class="mt-0.5 text-xs text-gray-500">
                    {{
                        esVehiculoNuevo
                            ? 'Sube, visualiza o descarga los 3 documentos privados correspondientes a un vehículo nuevo.'
                            : 'Visualiza o descarga de forma segura los documentos privados aprobados en el expediente.'
                    }}
                </p>
            </div>
            <span
                v-if="esVehiculoNuevo"
                :class="totalCargadosNuevo === 3 ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-900'"
                class="rounded-full px-2.5 py-0.5 text-xs font-bold"
            >
                {{ totalCargadosNuevo }}/3 cargados
            </span>
            <span
                v-else
                class="rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-bold text-indigo-800"
            >
                {{ documentosExpediente.length }} {{ documentosExpediente.length === 1 ? 'documento' : 'documentos' }}
            </span>
        </div>

        <!-- MODO VEHÍCULO NUEVO: Subida y descarga de Matrícula, Revisión (RTV) y Factura -->
        <div v-if="esVehiculoNuevo" class="grid grid-cols-1 gap-3 p-4 md:grid-cols-3">
            <div
                v-for="doc in documentosNuevo"
                :key="doc.clave"
                class="flex flex-col justify-between gap-3 rounded-lg border p-3.5"
                :class="doc.disponible ? 'border-green-200 bg-green-50/40' : 'border-amber-200 bg-amber-50/40'"
            >
                <div class="space-y-2">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-sm font-bold text-gray-900">
                            {{ doc.nombre }}
                        </p>
                        <span
                            :class="doc.disponible ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-900'"
                            class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold"
                        >
                            {{ doc.disponible ? '✅ Cargado' : '📄 Pendiente' }}
                        </span>
                    </div>

                    <div v-if="doc.disponible" class="flex flex-wrap items-center gap-2">
                        <a
                            :href="doc.ver_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center rounded-md bg-gray-900 px-2.5 py-1 text-xs font-semibold text-yellow-400 transition hover:bg-gray-800"
                        >
                            Ver
                        </a>
                        <a
                            :href="doc.descargar_url"
                            class="inline-flex items-center rounded-md bg-indigo-700 px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-indigo-800"
                        >
                            Descargar
                        </a>
                    </div>
                </div>

                <form class="space-y-2 border-t border-gray-200/80 pt-2.5" @submit.prevent="subirDocumentoNuevo(doc.clave)">
                    <input
                        :ref="(el) => { inputsArchivo[doc.clave] = el; }"
                        type="file"
                        accept="image/jpeg,image/png,image/webp,.pdf"
                        class="block w-full text-[11px] text-gray-700 file:mr-2 file:rounded file:border-0 file:bg-gray-900 file:px-2.5 file:py-1 file:text-[11px] file:font-semibold file:text-white hover:file:bg-gray-700"
                        @change="seleccionarArchivo(doc.clave, $event)"
                    />
                    <button
                        type="submit"
                        :disabled="subirForm.processing || !archivosSeleccionados[doc.clave]"
                        class="w-full rounded-md bg-emerald-700 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{
                            tipoSubiendo === doc.clave
                                ? 'Subiendo...'
                                : doc.disponible
                                    ? `Reemplazar ${doc.nombre.split(' ')[0]}`
                                    : `Subir ${doc.nombre.split(' ')[0]}`
                        }}
                    </button>
                    <p
                        v-if="errorPorTipo[doc.clave]"
                        role="alert"
                        class="text-[11px] font-medium text-red-600"
                    >
                        {{ errorPorTipo[doc.clave] }}
                    </p>
                </form>
            </div>
        </div>

        <!-- MODO VEHÍCULO APROBADO DESDE VALIDACIÓN: Visualización y descarga de respaldos existentes -->
        <div v-else class="p-4">
            <div v-if="documentosExpediente.length > 0" class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="doc in documentosExpediente"
                    :key="doc.clave"
                    class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 bg-gray-50/70 px-3 py-2.5"
                >
                    <div class="min-w-0">
                        <p class="truncate text-xs font-bold text-gray-900" :title="doc.nombre">
                            {{ doc.nombre }}
                        </p>
                        <p class="text-[11px] text-emerald-700">
                            Disponible (privado)
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1.5">
                        <a
                            :href="doc.ver_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="rounded bg-gray-900 px-2.5 py-1 text-[11px] font-semibold text-yellow-400 transition hover:bg-gray-800"
                        >
                            Ver
                        </a>
                        <a
                            :href="doc.descargar_url"
                            class="rounded bg-indigo-700 px-2.5 py-1 text-[11px] font-semibold text-white transition hover:bg-indigo-800"
                        >
                            Descargar
                        </a>
                    </div>
                </div>
            </div>
            <p v-else class="text-xs italic text-gray-500">
                Este vehículo aún no registra documentos adjuntos en el almacenamiento privado.
            </p>
        </div>
    </section>
</template>
