<script setup>
import DetalleCustodiaDocumentos from '@/Components/Vehiculos/DetalleCustodiaDocumentos.vue';
import DetalleInformacionGeneral from '@/Components/Vehiculos/DetalleInformacionGeneral.vue';
import DetalleMultasVehiculo from '@/Components/Vehiculos/DetalleMultasVehiculo.vue';
import DetallePagosVehiculo from '@/Components/Vehiculos/DetallePagosVehiculo.vue';
import DetallePrecioVenta from '@/Components/Vehiculos/DetallePrecioVenta.vue';
import {
    estadoCaducidadRtv,
    formatearFecha,
    formatearMoneda,
    infoMetodoIngreso,
} from '@/Composables/useInventarioVehiculos';
import { computed, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    vehiculo: {
        type: Object,
        required: true,
    },
    patios: {
        type: Array,
        default: () => [],
    },
    usuarios: {
        type: Array,
        default: () => [],
    },
    usuarioActual: {
        type: Object,
        default: () => ({}),
    },
    success: {
        type: String,
        default: null,
    },
});

const emit = defineEmits(['close']);

const rtvInfo = computed(() => estadoCaducidadRtv(props.vehiculo.fecha_caducidad_revision));
const metodoIngresoInfo = computed(() => infoMetodoIngreso(props.vehiculo.metodo_ingreso));

const manejarTecla = (e) => {
    if (e.key === 'Escape') {
        emit('close');
    }
};

onMounted(() => window.addEventListener('keydown', manejarTecla));
onUnmounted(() => window.removeEventListener('keydown', manejarTecla));
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-xs"
        @click.self="emit('close')"
    >
        <div class="w-full max-w-5xl overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl">
            <!-- Cabecera -->
            <div class="flex items-start justify-between border-b-4 border-yellow-500 bg-gray-900 px-6 py-5 text-white">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded bg-yellow-500 px-2.5 py-0.5 font-mono text-xs font-extrabold uppercase text-black">
                            Placa {{ vehiculo.id_vehiculo }}
                        </span>
                        <span class="rounded-full bg-gray-700 px-2.5 py-0.5 text-xs font-semibold text-yellow-300">
                            Estado: {{ vehiculo.estado?.nombre }}
                        </span>
                        <span
                            v-if="vehiculo.metodo_ingreso"
                            :class="metodoIngresoInfo.clasesHeader"
                            class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                        >
                            Ingreso: {{ metodoIngresoInfo.etiqueta }}
                        </span>
                        <span
                            v-if="vehiculo.multas_pendientes_count > 0 && vehiculo.multas_requieren_revision_manual && Number(vehiculo.multas_pendientes_total || 0) <= 0"
                            class="rounded-full bg-amber-500 px-2.5 py-0.5 text-xs font-bold text-gray-900"
                        >
                            ⚠️ {{ vehiculo.multas_pendientes_count }} multa(s) · Revisión manual ($0.00)
                        </span>
                        <span
                            v-else-if="vehiculo.multas_pendientes_count > 0"
                            class="rounded-full bg-red-600 px-2.5 py-0.5 text-xs font-bold text-white"
                        >
                            ⚠️ {{ vehiculo.multas_pendientes_count }} multa(s) pendiente(s) · {{ formatearMoneda(vehiculo.multas_pendientes_total) }}{{ vehiculo.multas_requieren_revision_manual ? ' · Rev. manual' : '' }}
                        </span>
                        <span
                            v-else
                            class="rounded-full bg-emerald-700 px-2.5 py-0.5 text-xs font-semibold text-emerald-100"
                        >
                            ✅ Sin multas pendientes
                        </span>
                        <span
                            :class="rtvInfo.clasesHeader"
                            class="rounded-full px-2.5 py-0.5 text-xs font-bold"
                        >
                            {{ rtvInfo.etiqueta }}
                        </span>
                        <span
                            v-if="vehiculo.tipo_contrato_nombre"
                            class="rounded-full bg-indigo-700 px-2.5 py-0.5 text-xs font-semibold text-indigo-100"
                        >
                            Contrato: {{ vehiculo.tipo_contrato_nombre }}
                        </span>
                        <span
                            v-if="vehiculo.contrato_pendiente"
                            class="rounded-full bg-amber-500 px-2.5 py-0.5 text-xs font-bold text-gray-900"
                        >
                            📄 Contrato pendiente de subir
                        </span>
                    </div>
                    <h3 class="mt-2 text-2xl font-extrabold">
                        {{ vehiculo.marca?.nombre }} {{ vehiculo.modelo }}
                        <span class="font-normal text-yellow-400">({{ vehiculo.anio }})</span>
                    </h3>
                    <p class="mt-1 text-xs text-gray-400">
                        Ingresado al sistema: {{ formatearFecha(vehiculo.created_at) }}
                    </p>
                </div>
                <button
                    type="button"
                    class="flex h-9 w-9 items-center justify-center rounded-lg bg-gray-800 text-xl font-bold text-gray-400 transition hover:bg-gray-700 hover:text-white"
                    title="Cerrar (Esc)"
                    @click="emit('close')"
                >
                    &times;
                </button>
            </div>

            <!-- Cuerpo modularizado por secciones -->
            <div class="max-h-[78vh] space-y-6 overflow-y-auto p-6">
                <div
                    v-if="success"
                    role="status"
                    class="rounded-md border border-green-200 bg-green-50 p-3 text-xs font-semibold text-green-800"
                >
                    {{ success }}
                </div>

                <div v-if="vehiculo.imagen_url" class="overflow-hidden rounded-lg border border-gray-200 bg-gray-50">
                    <img
                        :src="vehiculo.imagen_url"
                        :alt="`Imagen de ${vehiculo.marca?.nombre ?? ''} ${vehiculo.modelo}`"
                        class="max-h-64 w-full object-contain"
                    />
                </div>

                <DetalleInformacionGeneral :vehiculo="vehiculo" />

                <DetallePrecioVenta
                    :vehiculo="vehiculo"
                    :es-admin="Boolean(usuarioActual?.es_admin)"
                />

                <DetalleMultasVehiculo :vehiculo="vehiculo" />

                <DetalleCustodiaDocumentos
                    :vehiculo="vehiculo"
                    :patios="patios"
                    :usuarios="usuarios"
                    :usuario-actual="usuarioActual"
                />

                <DetallePagosVehiculo :vehiculo="vehiculo" />
            </div>

            <!-- Pie -->
            <div class="flex justify-end border-t border-gray-200 bg-gray-50 px-6 py-4">
                <button
                    type="button"
                    class="rounded-md bg-gray-800 px-5 py-2 text-sm font-semibold text-white transition hover:bg-gray-900"
                    @click="emit('close')"
                >
                    Cerrar Expediente
                </button>
            </div>
        </div>
    </div>
</template>
