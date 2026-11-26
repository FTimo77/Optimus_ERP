<script setup>
import DetalleCustodiaDocumentos from '@/Components/Vehiculos/DetalleCustodiaDocumentos.vue';
import DetalleExpedienteDocumental from '@/Components/Vehiculos/DetalleExpedienteDocumental.vue';
import DetalleInformacionGeneral from '@/Components/Vehiculos/DetalleInformacionGeneral.vue';
import DetalleMultasVehiculo from '@/Components/Vehiculos/DetalleMultasVehiculo.vue';
import DetallePagosVehiculo from '@/Components/Vehiculos/DetallePagosVehiculo.vue';
import DetallePrecioVenta from '@/Components/Vehiculos/DetallePrecioVenta.vue';
import DetalleTransferenciaPatio from '@/Components/Vehiculos/DetalleTransferenciaPatio.vue';
import {
    estadoCaducidadRtv,
    formatearFecha,
    formatearMoneda,
    infoMetodoIngreso,
} from '@/Composables/useInventarioVehiculos';
import { router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

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
const puedeVerDatosFinancieros = computed(() =>
    Boolean(props.usuarioActual?.puede_ver_datos_financieros),
);
const puedeSubirContrato = computed(() =>
    Boolean(props.usuarioActual?.puede_subir_contrato),
);
const puedeTransferirPatio = computed(() => {
    if (props.usuarioActual?.puede_transferir_patio) {
        return true;
    }
    const roles = page.props.auth?.roles || [];
    return roles.includes('Vendedor') || roles.includes('Jefe de Patio') || roles.includes('Administrador');
});

const page = usePage();
const iniciandoVenta = ref(false);
const mostrarConfirmacionVenta = ref(false);
const errorVenta = ref('');

/**
 * Por ahora habilitado cuando el estado actual es 'En inventario'.
 * Preparado para migrarlo al estado 'Disponible' en una futura actualización.
 */
const ESTADOS_HABILITADOS_PARA_VENTA = ['en inventario'];

const puedeUsarVentas = computed(() => {
    const roles = page.props.auth?.roles || [];
    const permisos = page.props.auth?.permissions || [];

    return Boolean(props.usuarioActual?.es_admin)
        || roles.includes('Vendedor')
        || roles.includes('Jefe de Patio')
        || roles.includes('Administrador')
        || roles.includes('Administracion')
        || permisos.includes('vender vehiculo')
        || permisos.includes('reservar vehiculo');
});

const estadoVehiculoNormalizado = computed(() =>
    String(props.vehiculo.estado?.nombre || '').trim().toLowerCase(),
);

const puedeIniciarVenta = computed(() =>
    puedeUsarVentas.value
    && ESTADOS_HABILITADOS_PARA_VENTA.includes(estadoVehiculoNormalizado.value)
    && !iniciandoVenta.value,
);

const tienePrecioLista = computed(() => Number(props.vehiculo.precio_venta || 0) > 0);
const tienePrecioFinal = computed(() => Number(props.vehiculo.precio_final || 0) > 0);
const tienePreciosConfigurados = computed(() => tienePrecioLista.value && tienePrecioFinal.value);

watch(
    () => [props.vehiculo.id_vehiculo, props.vehiculo.precio_venta, props.vehiculo.precio_final],
    () => {
        errorVenta.value = '';
    },
);

const solicitarConfirmacionVenta = () => {
    if (!puedeIniciarVenta.value) {
        return;
    }

    if (!tienePreciosConfigurados.value) {
        const faltantes = [];
        if (!tienePrecioLista.value) faltantes.push('Precio de Lista');
        if (!tienePrecioFinal.value) faltantes.push('Precio Final (mínimo)');

        errorVenta.value = `No se puede iniciar la venta: falta configurar ${faltantes.join(' y ')} del vehículo por parte del Administrador.`;
        mostrarConfirmacionVenta.value = false;
        return;
    }

    errorVenta.value = '';
    mostrarConfirmacionVenta.value = true;
};

const iniciarProcesoVenta = () => {
    if (!puedeIniciarVenta.value || !tienePreciosConfigurados.value) {
        return;
    }

    iniciandoVenta.value = true;
    errorVenta.value = '';
    router.post(
        route('mis-ventas.iniciar', props.vehiculo.id_vehiculo),
        {},
        {
            onError: (errors) => {
                const mensajes = Object.values(errors || {}).flat();
                errorVenta.value = mensajes[0] || 'No se pudo iniciar la venta del vehículo.';
            },
            onFinish: () => {
                iniciandoVenta.value = false;
                mostrarConfirmacionVenta.value = false;
            },
        },
    );
};

const manejarTecla = (e) => {
    if (e.key === 'Escape') {
        if (mostrarConfirmacionVenta.value) {
            mostrarConfirmacionVenta.value = false;
            return;
        }
        emit('close');
    }
};

onMounted(() => window.addEventListener('keydown', manejarTecla));
onUnmounted(() => window.removeEventListener('keydown', manejarTecla));
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/75 p-2 backdrop-blur-xs sm:p-4"
        @click.self="emit('close')"
    >
        <div class="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl">
            <!-- Cabecera fija -->
            <div class="flex shrink-0 items-start justify-between gap-4 border-b-4 border-yellow-500 bg-gray-900 px-4 py-4 text-white sm:px-6 sm:py-5">
                <div class="min-w-0 flex-1">
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
                        <template v-if="puedeVerDatosFinancieros">
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
                        </template>
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
                    <h3 class="mt-2 truncate text-xl font-extrabold sm:text-2xl">
                        {{ vehiculo.marca?.nombre }} {{ vehiculo.modelo }}
                        <span class="font-normal text-yellow-400">({{ vehiculo.anio }})</span>
                    </h3>
                    <p class="mt-0.5 text-xs text-gray-400">
                        Ingresado al sistema: {{ formatearFecha(vehiculo.created_at) }}
                    </p>
                </div>
                <button
                    type="button"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-800 text-xl font-bold text-gray-300 transition hover:bg-gray-700 hover:text-white"
                    title="Cerrar (Esc)"
                    @click="emit('close')"
                >
                    &times;
                </button>
            </div>

            <!-- Cuerpo modularizado por secciones con scroll interno -->
            <div class="flex-1 space-y-5 overflow-y-auto p-4 sm:p-6">
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

                <DetalleInformacionGeneral
                    :vehiculo="vehiculo"
                    :puede-ver-datos-financieros="puedeVerDatosFinancieros"
                    :puede-subir-contrato="puedeSubirContrato"
                />

                <!-- DATOS DEL CLIENTE VENDEDOR (SOLO ROLES CON PERMISO FINANCIERO / ADQUISICIÓN) -->
                <section
                    v-if="puedeVerDatosFinancieros && vehiculo.cliente_vendedor"
                    class="overflow-hidden rounded-lg border border-indigo-200 bg-white"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-indigo-200 bg-indigo-50 px-4 py-3">
                        <h4 class="text-sm font-bold uppercase tracking-wide text-indigo-950">
                            Cliente Vendedor (Origen de Adquisición)
                        </h4>
                        <span class="rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-bold text-indigo-800">
                            Tipo: {{ vehiculo.cliente_vendedor.tipo_cliente || 'Vendedor' }}
                        </span>
                    </div>
                    <div class="grid grid-cols-1 gap-4 p-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <span class="block text-xs font-semibold uppercase text-gray-500">Nombre completo</span>
                            <span class="font-bold text-gray-900">{{ vehiculo.cliente_vendedor.nombre_completo }}</span>
                        </div>
                        <div>
                            <span class="block text-xs font-semibold uppercase text-gray-500">Cédula / RUC</span>
                            <span class="font-mono font-bold text-gray-900">{{ vehiculo.cliente_vendedor.cedula }}</span>
                        </div>
                        <div>
                            <span class="block text-xs font-semibold uppercase text-gray-500">Teléfono</span>
                            <span class="text-gray-800">{{ vehiculo.cliente_vendedor.telefono || 'No registrado' }}</span>
                        </div>
                        <div>
                            <span class="block text-xs font-semibold uppercase text-gray-500">Dirección</span>
                            <span class="text-gray-800">{{ vehiculo.cliente_vendedor.direccion || 'No registrada' }}</span>
                        </div>
                    </div>
                </section>

                <DetalleExpedienteDocumental
                    :vehiculo="vehiculo"
                />

                <DetallePrecioVenta
                    :vehiculo="vehiculo"
                    :es-admin="Boolean(usuarioActual?.es_admin)"
                />

                <DetalleMultasVehiculo
                    v-if="puedeVerDatosFinancieros"
                    :vehiculo="vehiculo"
                />

                <DetalleCustodiaDocumentos
                    :vehiculo="vehiculo"
                    :patios="patios"
                    :usuarios="usuarios"
                    :usuario-actual="usuarioActual"
                />

                <DetalleTransferenciaPatio
                    v-if="puedeTransferirPatio"
                    :vehiculo="vehiculo"
                    :patios="patios"
                    :usuarios="usuarios"
                />

                <DetallePagosVehiculo
                    v-if="puedeVerDatosFinancieros"
                    :vehiculo="vehiculo"
                />
            </div>

            <!-- Pie fijo -->
            <div class="shrink-0 space-y-2.5 border-t border-gray-200 bg-gray-50 px-4 py-3 sm:px-6 sm:py-4">
                <div
                    v-if="errorVenta"
                    role="alert"
                    class="flex items-start justify-between gap-2 rounded-md border border-red-300 bg-red-50 px-3.5 py-2.5 text-xs font-semibold text-red-800"
                >
                    <span>⚠️ {{ errorVenta }}</span>
                    <button
                        type="button"
                        class="shrink-0 text-red-600 hover:text-red-900"
                        @click="errorVenta = ''"
                    >
                        ✕
                    </button>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-3">
                    <button
                        v-if="puedeUsarVentas"
                        type="button"
                        :disabled="!puedeIniciarVenta"
                        :title="puedeIniciarVenta
                            ? 'Iniciar proceso de venta o reserva'
                            : `Solo disponible cuando el estado del vehículo es «En Inventario» (actual: ${vehiculo.estado?.nombre || 'N/D'})`"
                        class="rounded-md bg-yellow-500 px-5 py-2 text-sm font-bold text-black shadow-xs transition hover:bg-yellow-400 disabled:cursor-not-allowed disabled:opacity-40"
                        @click="solicitarConfirmacionVenta"
                    >
                        Vender
                    </button>
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

        <!-- Cuadro emergente de confirmación de venta -->
        <div
            v-if="mostrarConfirmacionVenta"
            class="fixed inset-0 z-60 flex items-center justify-center bg-black/60 p-4"
            @click.self="mostrarConfirmacionVenta = false"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="titulo-confirmar-venta"
                class="w-full max-w-md overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl"
            >
                <div class="border-b-2 border-yellow-500 bg-gray-900 px-5 py-3.5 text-white">
                    <h4 id="titulo-confirmar-venta" class="text-base font-extrabold text-yellow-400">
                        ¿Confirmar venta?
                    </h4>
                </div>
                <div class="space-y-2 px-5 py-4 text-sm text-gray-700">
                    <p>
                        Se iniciará el proceso comercial para el vehículo
                        <strong class="text-gray-900">{{ vehiculo.marca?.nombre }} {{ vehiculo.modelo }} ({{ vehiculo.anio }})</strong>
                        con placa <strong class="font-mono text-gray-900">{{ vehiculo.id_vehiculo }}</strong>.
                    </p>
                    <p class="text-xs text-gray-500">
                        El vehículo cambiará a estado <strong>En proceso de venta</strong> y serás redirigido al módulo <strong>Mis Ventas</strong>.
                    </p>
                </div>
                <div class="flex items-center justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3">
                    <button
                        type="button"
                        :disabled="iniciandoVenta"
                        class="rounded-md border border-gray-300 bg-white px-4 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-100 disabled:opacity-50"
                        @click="mostrarConfirmacionVenta = false"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        :disabled="iniciandoVenta"
                        class="rounded-md bg-yellow-500 px-4 py-1.5 text-xs font-bold text-black shadow-xs transition hover:bg-yellow-400 disabled:opacity-50"
                        @click="iniciarProcesoVenta"
                    >
                        {{ iniciandoVenta ? 'Iniciando...' : 'Confirmar venta' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
