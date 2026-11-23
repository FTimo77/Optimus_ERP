<script setup>
import {
    estadoCaducidadRtv,
    formatearFechaSolo,
    formatearMoneda,
    infoMetodoIngreso,
} from '@/Composables/useInventarioVehiculos';
import { useForm } from '@inertiajs/vue3';
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
});

const formatearKilometraje = (km) =>
    km !== null && km !== undefined && km !== ''
        ? `${Number(km).toLocaleString('es-EC')} km`
        : 'No registrado';

const contratoForm = useForm({
    contrato: null,
});
const contratoFileInput = ref(null);

const rtvInfo = computed(() => estadoCaducidadRtv(props.vehiculo.fecha_caducidad_revision));
const metodoIngresoInfo = computed(() => infoMetodoIngreso(props.vehiculo.metodo_ingreso));

const subirContratoVehiculo = () => {
    if (!contratoForm.contrato) {
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
                                        v-else-if="vehiculo.tiene_contrato_cargado"
                                        class="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-semibold text-green-800"
                                    >
                                        ✅ Contrato cargado
                                    </span>
                                    <span
                                        v-if="vehiculo.tiene_contrato_cargado && vehiculo.contrato_cantidad_hojas"
                                        class="rounded-full border border-gray-200 bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700"
                                    >
                                        {{ vehiculo.contrato_cantidad_hojas }} {{ vehiculo.contrato_cantidad_hojas === 1 ? 'hoja' : 'hojas' }}
                                    </span>
                                    <span
                                        v-else-if="vehiculo.contrato_pendiente"
                                        class="rounded-full border border-amber-300 bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-900"
                                    >
                                        📄 Contrato pendiente de subir
                                    </span>
                                    <a
                                        v-if="vehiculo.contrato_archivo_url"
                                        :href="vehiculo.contrato_archivo_url"
                                        class="text-xs font-semibold text-indigo-700 hover:text-indigo-900"
                                    >
                                        Descargar contrato
                                    </a>
                                </div>

                                <form
                                    v-if="vehiculo.contrato_pendiente"
                                    class="flex flex-col gap-2 rounded-md border border-amber-200 bg-amber-50/70 p-3 sm:flex-row sm:items-center"
                                    @submit.prevent="subirContratoVehiculo"
                                >
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
                                        :disabled="contratoForm.processing || !contratoForm.contrato"
                                        class="whitespace-nowrap rounded-md bg-emerald-700 px-4 py-1.5 text-xs font-bold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {{ contratoForm.processing ? 'Subiendo...' : 'Subir contrato' }}
                                    </button>
                                </form>
                                <p
                                    v-if="contratoForm.errors.contrato"
                                    role="alert"
                                    class="text-xs font-medium text-red-600"
                                >
                                    {{ contratoForm.errors.contrato }}
                                </p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
