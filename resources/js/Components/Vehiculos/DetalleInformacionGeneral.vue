<script setup>
import {
    estadoCaducidadRtv,
    formatearFechaSolo,
    formatearMoneda,
    infoMetodoIngreso,
} from '@/Composables/useInventarioVehiculos';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    vehiculo: {
        type: Object,
        required: true,
    },
    puedeVerDatosFinancieros: {
        type: Boolean,
        default: false,
    },
    puedeSubirContrato: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();

const ROLES_SUBIDA_CONTRATO = ['administracion', 'administrativo', 'administrador'];

const normalizarRol = (rol) =>
    String(rol || '')
        .trim()
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '');

const usuarioPuedeSubirContrato = computed(() => {
    if (props.puedeSubirContrato) {
        return true;
    }
    const roles = (page.props.auth?.roles || []).map(normalizarRol);
    return roles.some((rol) => ROLES_SUBIDA_CONTRATO.includes(rol));
});

const formatearKilometraje = (km) =>
    km !== null && km !== undefined && km !== ''
        ? `${Number(km).toLocaleString('es-EC')} km`
        : 'No registrado';

const contratoForm = useForm({
    contrato: null,
    contrato_2: null,
});
const contratoFileInput = ref(null);
const contrato2FileInput = ref(null);

const rtvInfo = computed(() => estadoCaducidadRtv(props.vehiculo.fecha_caducidad_revision));
const metodoIngresoInfo = computed(() => infoMetodoIngreso(props.vehiculo.metodo_ingreso));
const esDobleContrato = computed(() => Boolean(props.vehiculo.es_doble_contrato));
const tieneArchivoSeleccionado = computed(() =>
    esDobleContrato.value
        ? Boolean(contratoForm.contrato || contratoForm.contrato_2)
        : Boolean(contratoForm.contrato),
);

const subirContratoVehiculo = () => {
    if (!tieneArchivoSeleccionado.value) {
        return;
    }

    contratoForm.post(route('vehiculos.contrato.store', props.vehiculo.id_vehiculo), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            contratoForm.reset();
            if (contratoFileInput.value) {
                contratoFileInput.value.value = '';
            }
            if (contrato2FileInput.value) {
                contrato2FileInput.value.value = '';
            }
        },
    });
};
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-gray-200 bg-white">
        <div class="border-b border-gray-200 bg-gray-50 px-4 py-3">
            <h4 class="text-sm font-bold uppercase tracking-wide text-gray-800">
                A. Información General del Vehículo
            </h4>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <tbody class="divide-y divide-gray-100">
                    <tr>
                        <th class="w-1/4 bg-gray-50 px-4 py-2.5 text-left font-semibold text-gray-600">Placa</th>
                        <td class="w-1/4 px-4 py-2.5 font-mono font-bold text-gray-900">{{ vehiculo.id_vehiculo }}</td>
                        <th class="w-1/4 bg-gray-50 px-4 py-2.5 text-left font-semibold text-gray-600">Marca</th>
                        <td class="w-1/4 px-4 py-2.5 text-gray-900">{{ vehiculo.marca?.nombre || 'No registrada' }}</td>
                    </tr>
                    <tr>
                        <th class="bg-gray-50 px-4 py-2.5 text-left font-semibold text-gray-600">Modelo</th>
                        <td class="px-4 py-2.5 text-gray-900">{{ vehiculo.modelo || 'No registrado' }}</td>
                        <th class="bg-gray-50 px-4 py-2.5 text-left font-semibold text-gray-600">Color</th>
                        <td class="px-4 py-2.5 text-gray-900">{{ vehiculo.color?.nombre || 'No registrado' }}</td>
                    </tr>
                    <tr>
                        <th class="bg-gray-50 px-4 py-2.5 text-left font-semibold text-gray-600">Año</th>
                        <td class="px-4 py-2.5 text-gray-900">{{ vehiculo.anio }}</td>
                        <th class="bg-gray-50 px-4 py-2.5 text-left font-semibold text-gray-600">Kilometraje</th>
                        <td class="px-4 py-2.5 text-gray-900">{{ formatearKilometraje(vehiculo.kilometraje) }}</td>
                    </tr>
                    <tr v-if="puedeVerDatosFinancieros">
                        <th class="bg-gray-50 px-4 py-2.5 text-left font-semibold text-gray-600">Precio de adquisición</th>
                        <td colspan="3" class="px-4 py-2.5 font-bold text-gray-900">{{ formatearMoneda(vehiculo.costo_adquisicion) }}</td>
                    </tr>
                    <tr>
                        <th class="bg-gray-50 px-4 py-2.5 text-left font-semibold text-gray-600">Estado del sistema</th>
                        <td class="px-4 py-2.5">
                            <span class="rounded-full bg-yellow-100 px-2.5 py-0.5 text-xs font-bold text-yellow-800">
                                {{ vehiculo.estado?.nombre || 'Sin estado' }}
                            </span>
                        </td>
                        <th class="bg-gray-50 px-4 py-2.5 text-left font-semibold text-gray-600">Método de Ingreso</th>
                        <td class="px-4 py-2.5">
                            <span
                                :class="metodoIngresoInfo.clasesBadge"
                                class="inline-flex rounded-full border px-2.5 py-0.5 text-xs font-bold"
                            >
                                {{ metodoIngresoInfo.etiqueta }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th class="bg-gray-50 px-4 py-2.5 text-left font-semibold text-gray-600">Proveedor</th>
                        <td colspan="3" class="px-4 py-2.5 text-gray-900">{{ vehiculo.proveedor_nombre || 'No registrado' }}</td>
                    </tr>
                    <tr>
                        <th class="bg-gray-50 px-4 py-2.5 text-left font-semibold text-gray-600">Caducidad RTV</th>
                        <td colspan="3" class="px-4 py-2.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-gray-900">
                                    {{ formatearFechaSolo(vehiculo.fecha_caducidad_revision) }}
                                </span>
                                <span
                                    :class="rtvInfo.clasesTabla"
                                    class="rounded-full border px-2.5 py-0.5 text-xs font-bold"
                                >
                                    {{ rtvInfo.etiqueta }}
                                </span>
                                <span class="text-xs text-gray-500">
                                    {{ rtvInfo.detalle }}
                                </span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th class="bg-gray-50 px-4 py-2.5 text-left font-semibold text-gray-600">Tipo de Contrato</th>
                        <td colspan="3" class="px-4 py-2.5">
                            <div class="space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold text-gray-900">
                                        {{ vehiculo.tipo_contrato_nombre || 'No asignado' }}
                                    </span>
                                    <span
                                        v-if="vehiculo.tipo_contrato_nombre && !vehiculo.requiere_documento_contrato"
                                        class="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-semibold text-green-800"
                                    >
                                        No requiere archivo
                                    </span>
                                    <span
                                        v-else-if="vehiculo.contratos_completos && vehiculo.requiere_documento_contrato"
                                        class="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-semibold text-green-800"
                                    >
                                        {{ esDobleContrato ? '✅ Doble contrato cargado (2/2)' : '✅ Contrato cargado' }}
                                    </span>
                                    <span
                                        v-else-if="vehiculo.contrato_pendiente"
                                        class="rounded-full border border-amber-300 bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-900"
                                    >
                                        {{
                                            esDobleContrato
                                                ? `📄 Doble contrato pendiente (${(vehiculo.tiene_contrato_cargado ? 1 : 0) + (vehiculo.tiene_contrato_2_cargado ? 1 : 0)}/2)`
                                                : '📄 Contrato pendiente de subir'
                                        }}
                                    </span>

                                    <!-- Enlaces de descarga para contrato único -->
                                    <template v-if="!esDobleContrato">
                                        <span
                                            v-if="vehiculo.tiene_contrato_cargado && vehiculo.contrato_cantidad_hojas"
                                            class="rounded-full border border-gray-200 bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700"
                                        >
                                            {{ vehiculo.contrato_cantidad_hojas }} {{ vehiculo.contrato_cantidad_hojas === 1 ? 'hoja' : 'hojas' }}
                                        </span>
                                        <a
                                            v-if="vehiculo.contrato_archivo_url"
                                            :href="vehiculo.contrato_archivo_url"
                                            class="text-xs font-semibold text-indigo-700 hover:text-indigo-900"
                                        >
                                            Descargar contrato
                                        </a>
                                    </template>
                                </div>

                                <!-- Resumen de los 2 documentos cuando es Doble Contrato -->
                                <div
                                    v-if="esDobleContrato && (vehiculo.tiene_contrato_cargado || vehiculo.tiene_contrato_2_cargado)"
                                    class="flex flex-wrap gap-2"
                                >
                                    <div
                                        v-if="vehiculo.tiene_contrato_cargado"
                                        class="inline-flex items-center gap-2 rounded-md border border-green-200 bg-green-50 px-2.5 py-1 text-xs"
                                    >
                                        <span class="font-semibold text-green-900">Contrato 1:</span>
                                        <span
                                            v-if="vehiculo.contrato_cantidad_hojas"
                                            class="rounded bg-white px-1.5 py-0.5 text-[11px] font-semibold text-gray-700"
                                        >
                                            {{ vehiculo.contrato_cantidad_hojas }} {{ vehiculo.contrato_cantidad_hojas === 1 ? 'hoja' : 'hojas' }}
                                        </span>
                                        <a
                                            v-if="vehiculo.contrato_archivo_url"
                                            :href="vehiculo.contrato_archivo_url"
                                            class="font-semibold text-indigo-700 hover:text-indigo-900"
                                        >
                                            Descargar
                                        </a>
                                    </div>
                                    <div
                                        v-if="vehiculo.tiene_contrato_2_cargado"
                                        class="inline-flex items-center gap-2 rounded-md border border-green-200 bg-green-50 px-2.5 py-1 text-xs"
                                    >
                                        <span class="font-semibold text-green-900">Contrato 2:</span>
                                        <span
                                            v-if="vehiculo.contrato_2_cantidad_hojas"
                                            class="rounded bg-white px-1.5 py-0.5 text-[11px] font-semibold text-gray-700"
                                        >
                                            {{ vehiculo.contrato_2_cantidad_hojas }} {{ vehiculo.contrato_2_cantidad_hojas === 1 ? 'hoja' : 'hojas' }}
                                        </span>
                                        <a
                                            v-if="vehiculo.contrato_2_archivo_url"
                                            :href="vehiculo.contrato_2_archivo_url"
                                            class="font-semibold text-indigo-700 hover:text-indigo-900"
                                        >
                                            Descargar
                                        </a>
                                    </div>
                                </div>

                                <!-- Formulario de subida: 1 archivo (contrato normal) o 2 archivos (Doble Contrato) -->
                                <form
                                    v-if="vehiculo.contrato_pendiente && usuarioPuedeSubirContrato"
                                    class="space-y-2.5 rounded-md border border-amber-200 bg-amber-50/70 p-3"
                                    @submit.prevent="subirContratoVehiculo"
                                >
                                    <div v-if="esDobleContrato" class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                                        <div>
                                            <label class="mb-1 block text-xs font-semibold text-gray-800">
                                                Documento de Contrato 1
                                                <span v-if="vehiculo.tiene_contrato_cargado" class="font-normal text-green-700">(ya cargado · opcional reemplazar)</span>
                                                <span v-else class="text-amber-800">(pendiente)</span>
                                            </label>
                                            <input
                                                ref="contratoFileInput"
                                                type="file"
                                                accept="image/jpeg,image/png,image/webp,.pdf"
                                                class="block w-full text-xs text-gray-700 file:mr-2 file:rounded file:border-0 file:bg-gray-900 file:px-3 file:py-1.5 file:text-white hover:file:bg-gray-700"
                                                @change="contratoForm.contrato = $event.target.files[0] || null"
                                            />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-xs font-semibold text-gray-800">
                                                Documento de Contrato 2
                                                <span v-if="vehiculo.tiene_contrato_2_cargado" class="font-normal text-green-700">(ya cargado · opcional reemplazar)</span>
                                                <span v-else class="text-amber-800">(pendiente)</span>
                                            </label>
                                            <input
                                                ref="contrato2FileInput"
                                                type="file"
                                                accept="image/jpeg,image/png,image/webp,.pdf"
                                                class="block w-full text-xs text-gray-700 file:mr-2 file:rounded file:border-0 file:bg-gray-900 file:px-3 file:py-1.5 file:text-white hover:file:bg-gray-700"
                                                @change="contratoForm.contrato_2 = $event.target.files[0] || null"
                                            />
                                        </div>
                                    </div>
                                    <div v-else class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                        <input
                                            ref="contratoFileInput"
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp,.pdf"
                                            required
                                            class="block w-full text-xs text-gray-700 file:mr-2 file:rounded file:border-0 file:bg-gray-900 file:px-3 file:py-1.5 file:text-white hover:file:bg-gray-700"
                                            @change="contratoForm.contrato = $event.target.files[0] || null"
                                        />
                                        <button
                                            type="submit"
                                            :disabled="contratoForm.processing || !tieneArchivoSeleccionado"
                                            class="whitespace-nowrap rounded-md bg-emerald-700 px-4 py-1.5 text-xs font-bold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {{ contratoForm.processing ? 'Subiendo...' : 'Subir contrato' }}
                                        </button>
                                    </div>

                                    <div v-if="esDobleContrato" class="flex justify-end">
                                        <button
                                            type="submit"
                                            :disabled="contratoForm.processing || !tieneArchivoSeleccionado"
                                            class="whitespace-nowrap rounded-md bg-emerald-700 px-4 py-1.5 text-xs font-bold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {{ contratoForm.processing ? 'Subiendo...' : 'Subir documento(s) de contrato' }}
                                        </button>
                                    </div>
                                </form>
                                <p
                                    v-else-if="vehiculo.contrato_pendiente"
                                    class="text-xs italic text-gray-500"
                                >
                                    La carga de contratos está reservada exclusivamente para el área Administrativa y el Administrador.
                                </p>
                                <p
                                    v-if="contratoForm.errors.contrato || contratoForm.errors.contrato_2"
                                    role="alert"
                                    class="text-xs font-medium text-red-600"
                                >
                                    {{ contratoForm.errors.contrato || contratoForm.errors.contrato_2 }}
                                </p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
