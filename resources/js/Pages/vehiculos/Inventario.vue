<script setup>
import DetalleVehiculoModal from '@/Components/Vehiculos/DetalleVehiculoModal.vue';
import {
    documentosFaltantes,
    formatearMoneda,
    tieneAlertaPagosMultas,
    useInventarioFiltros,
} from '@/Composables/useInventarioVehiculos';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    vehiculos: {
        type: Array,
        default: () => [],
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

const {
    busqueda,
    filtroMarca,
    filtroEstado,
    filtroPatio,
    filtroAlerta,
    filtroMetodoIngreso,
    marcasDisponibles,
    estadosDisponibles,
    patiosDisponibles,
    hayFiltrosActivos,
    limpiarFiltros,
    vehiculosFiltrados,
} = useInventarioFiltros(() => props.vehiculos);

const vehiculoSeleccionadoId = ref(null);
const vehiculoSeleccionado = computed(() =>
    props.vehiculos.find((v) => v.id_vehiculo === vehiculoSeleccionadoId.value) || null,
);
const puedeVerDatosFinancieros = computed(() =>
    Boolean(props.usuarioActual?.puede_ver_datos_financieros),
);

const formatearKilometraje = (km) =>
    km !== null && km !== undefined && km !== ''
        ? `${Number(km).toLocaleString('es-EC')} km`
        : 'No registrado';

const abrirDetalles = (auto) => {
    vehiculoSeleccionadoId.value = auto.id_vehiculo;
};

const cerrarDetalles = () => {
    vehiculoSeleccionadoId.value = null;
};
</script>

<template>
    <Head title="Inventario de Vehículos" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-center">
                <div>
                    <h2 class="text-lg font-semibold leading-tight text-gray-800">
                        Inventario General de Vehículos
                    </h2>
                    <p class="mt-0.5 text-[11px] text-gray-500">
                        Haz clic sobre cualquier vehículo para abrir su tarjeta y gestionar documentos, llaves y seguimiento.
                    </p>
                </div>
                <Link
                    :href="route('vehiculos.create')"
                    class="w-fit rounded-md bg-yellow-500 px-3 py-1.5 text-xs font-bold text-black shadow-xs transition hover:bg-yellow-600"
                >
                    + Nuevo Ingreso
                </Link>
            </div>
        </template>

        <div class="py-3">
            <div class="mx-auto w-full max-w-full space-y-2 px-2 sm:px-4">
                <div
                    v-if="success"
                    role="status"
                    class="rounded-md border border-green-200 bg-green-50 px-3 py-1.5 text-xs font-medium text-green-800"
                >
                    {{ success }}
                </div>

                <!-- BARRA DE FILTROS MULTICRITERIO Y BÚSQUEDA (ALTA DENSIDAD) -->
                <div class="rounded-lg border border-gray-200 bg-white px-3 py-2 shadow-xs">
                    <div class="grid grid-cols-2 items-end gap-2 sm:grid-cols-3 lg:grid-cols-12">
                        <div class="col-span-2 sm:col-span-3 lg:col-span-3">
                            <label for="filtro-busqueda" class="block text-[10px] font-semibold uppercase tracking-wide text-gray-600">
                                Buscar placa, marca o modelo
                            </label>
                            <input
                                id="filtro-busqueda"
                                v-model="busqueda"
                                type="search"
                                placeholder="Ej. PBA1234 o Toyota Corolla..."
                                class="mt-0.5 block w-full rounded border-gray-300 px-2 py-1 text-xs shadow-xs focus:border-yellow-500 focus:ring-yellow-500"
                            />
                        </div>

                        <div class="lg:col-span-2">
                            <label for="filtro-marca" class="block text-[10px] font-semibold uppercase tracking-wide text-gray-600">Marca</label>
                            <select
                                id="filtro-marca"
                                v-model="filtroMarca"
                                class="mt-0.5 block w-full rounded border-gray-300 px-2 py-1 text-xs shadow-xs focus:border-yellow-500 focus:ring-yellow-500"
                            >
                                <option value="">Todas</option>
                                <option v-for="marca in marcasDisponibles" :key="marca" :value="marca">
                                    {{ marca }}
                                </option>
                            </select>
                        </div>

                        <div class="lg:col-span-2">
                            <label for="filtro-estado" class="block text-[10px] font-semibold uppercase tracking-wide text-gray-600">Estado</label>
                            <select
                                id="filtro-estado"
                                v-model="filtroEstado"
                                class="mt-0.5 block w-full rounded border-gray-300 px-2 py-1 text-xs shadow-xs focus:border-yellow-500 focus:ring-yellow-500"
                            >
                                <option value="">Todos</option>
                                <option v-for="estado in estadosDisponibles" :key="estado" :value="estado">
                                    {{ estado }}
                                </option>
                            </select>
                        </div>

                        <div class="lg:col-span-1">
                            <label for="filtro-patio" class="block text-[10px] font-semibold uppercase tracking-wide text-gray-600">Patio</label>
                            <select
                                id="filtro-patio"
                                v-model="filtroPatio"
                                class="mt-0.5 block w-full rounded border-gray-300 px-2 py-1 text-xs shadow-xs focus:border-yellow-500 focus:ring-yellow-500"
                            >
                                <option value="">Todos</option>
                                <option v-for="patio in patiosDisponibles" :key="patio" :value="patio">
                                    {{ patio }}
                                </option>
                            </select>
                        </div>

                        <div class="lg:col-span-1">
                            <label for="filtro-metodo-ingreso" class="block text-[10px] font-semibold uppercase tracking-wide text-gray-600">Ingreso</label>
                            <select
                                id="filtro-metodo-ingreso"
                                v-model="filtroMetodoIngreso"
                                class="mt-0.5 block w-full rounded border-gray-300 px-2 py-1 text-xs shadow-xs focus:border-yellow-500 focus:ring-yellow-500"
                            >
                                <option value="">Todos</option>
                                <option value="particular">Particular</option>
                                <option value="empresa">Empresa</option>
                                <option value="nuevos">Nuevos</option>
                            </select>
                        </div>

                        <div class="lg:col-span-1">
                            <label for="filtro-alerta" class="block text-[10px] font-semibold uppercase tracking-wide text-gray-600">Alerta</label>
                            <select
                                id="filtro-alerta"
                                v-model="filtroAlerta"
                                class="mt-0.5 block w-full rounded border-gray-300 px-2 py-1 text-xs shadow-xs focus:border-yellow-500 focus:ring-yellow-500"
                            >
                                <option value="">Todas</option>
                                <option value="en_alerta">En alerta</option>
                                <option value="sin_alerta">Sin alerta</option>
                            </select>
                        </div>

                        <div class="col-span-2 flex items-center justify-between gap-2 sm:col-span-3 lg:col-span-2 lg:justify-end">
                            <span class="whitespace-nowrap text-[11px] text-gray-600">
                                <strong class="text-gray-900">{{ vehiculosFiltrados.length }}</strong>/{{ vehiculos.length }}
                            </span>
                            <button
                                type="button"
                                :disabled="!hayFiltrosActivos"
                                class="whitespace-nowrap rounded border border-gray-300 bg-gray-50 px-2.5 py-1 text-[11px] font-semibold text-gray-700 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="limpiarFiltros"
                            >
                                Limpiar
                            </button>
                        </div>
                    </div>
                </div>

                <!-- TABLA PRINCIPAL DEL INVENTARIO (ALTA DENSIDAD) -->
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xs">
                    <div class="overflow-x-auto">
                        <table class="w-full table-auto divide-y divide-gray-200 text-[11px]">
                            <thead class="bg-gray-900 text-white">
                                <tr>
                                    <th class="whitespace-nowrap px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider">Placa</th>
                                    <th class="whitespace-nowrap px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider">Marca</th>
                                    <th class="whitespace-nowrap px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider">Modelo</th>
                                    <th v-if="!puedeVerDatosFinancieros" class="whitespace-nowrap px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider">Año</th>
                                    <th v-if="!puedeVerDatosFinancieros" class="whitespace-nowrap px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider">Kilometraje</th>
                                    <th v-if="!puedeVerDatosFinancieros" class="whitespace-nowrap px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider">Precio Venta</th>
                                    <th class="whitespace-nowrap px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider">Ubicación</th>
                                    <th class="whitespace-nowrap px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider">Contrato</th>
                                    <th v-if="puedeVerDatosFinancieros" class="whitespace-nowrap px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider">Pagos / Multas</th>
                                    <th class="whitespace-nowrap px-1.5 py-1.5 text-center text-[10px] font-bold uppercase tracking-wider">Llaves</th>
                                    <th class="whitespace-nowrap px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider">Documentación</th>
                                    <th class="whitespace-nowrap px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider">Estado</th>
                                    <th class="whitespace-nowrap px-2 py-1.5 text-center text-[10px] font-bold uppercase tracking-wider">Detalle</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <tr
                                    v-for="auto in vehiculosFiltrados"
                                    :key="auto.id_vehiculo"
                                    class="cursor-pointer transition duration-150 hover:bg-yellow-50"
                                    @click="abrirDetalles(auto)"
                                >
                                    <td class="whitespace-nowrap px-2 py-1.5 font-mono text-xs font-bold text-gray-900">
                                        {{ auto.id_vehiculo || auto.placa }}
                                    </td>
                                    <td class="whitespace-nowrap px-2 py-1.5 text-xs font-bold text-gray-900">
                                        {{ auto.marca?.nombre || 'No registrada' }}
                                    </td>
                                    <td class="max-w-[160px] px-2 py-1.5 text-gray-900">
                                        <div class="truncate text-xs font-semibold leading-tight" :title="auto.modelo">
                                            {{ auto.modelo }}
                                        </div>
                                        <div class="truncate text-[10px] leading-tight text-gray-500">
                                            {{ auto.clase?.nombre }} • {{ auto.transmision?.nombre }}
                                        </div>
                                    </td>
                                    <td v-if="!puedeVerDatosFinancieros" class="whitespace-nowrap px-2 py-1.5 text-xs font-semibold text-gray-900">
                                        {{ auto.anio || 'N/D' }}
                                    </td>
                                    <td v-if="!puedeVerDatosFinancieros" class="whitespace-nowrap px-2 py-1.5 text-[11px] text-gray-700">
                                        {{ formatearKilometraje(auto.kilometraje) }}
                                    </td>
                                    <td v-if="!puedeVerDatosFinancieros" class="whitespace-nowrap px-2 py-1.5 font-mono text-xs font-bold text-emerald-800">
                                        <span v-if="auto.precio_venta !== null && auto.precio_venta !== undefined && auto.precio_venta !== ''">
                                            {{ formatearMoneda(auto.precio_venta) }}
                                        </span>
                                        <span v-else class="font-sans text-[10px] font-medium italic text-gray-400">
                                            Por definir
                                        </span>
                                    </td>
                                    <td class="max-w-[115px] truncate whitespace-nowrap px-2 py-1.5 text-[11px] text-gray-700" :title="auto.patio?.nombre || 'Sin asignar'">
                                        {{ auto.patio?.nombre || 'Sin asignar' }}
                                    </td>
                                    <td class="max-w-[110px] px-2 py-1.5">
                                        <span
                                            v-if="auto.tipo_contrato?.nombre || auto.tipo_contrato_nombre"
                                            class="inline-flex max-w-full truncate rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-semibold leading-tight text-indigo-800"
                                            :title="auto.tipo_contrato?.nombre || auto.tipo_contrato_nombre"
                                        >
                                            {{ auto.tipo_contrato?.nombre || auto.tipo_contrato_nombre }}
                                        </span>
                                        <span v-else class="text-[10px] text-gray-400">Sin asignar</span>
                                    </td>
                                    <td v-if="puedeVerDatosFinancieros" class="px-2 py-1.5">
                                        <div class="flex flex-wrap items-center gap-1">
                                            <template v-if="tieneAlertaPagosMultas(auto)">
                                                <span
                                                    v-if="auto.multas_pendientes_count > 0 && auto.multas_requieren_revision_manual && Number(auto.multas_pendientes_total || 0) <= 0"
                                                    class="inline-flex items-center gap-0.5 whitespace-nowrap rounded-full border border-amber-300 bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold leading-tight text-amber-900"
                                                >
                                                    ⚠️ {{ auto.multas_pendientes_count }} multa(s) · Rev.
                                                </span>
                                                <span
                                                    v-else-if="auto.multas_pendientes_count > 0"
                                                    class="inline-flex items-center gap-0.5 whitespace-nowrap rounded-full border border-red-300 bg-red-100 px-1.5 py-0.5 text-[10px] font-bold leading-tight text-red-800"
                                                >
                                                    ⚠️ {{ auto.multas_pendientes_count }} · {{ formatearMoneda(auto.multas_pendientes_total) }}
                                                </span>
                                                <span
                                                    v-if="auto.credito_vencido"
                                                    class="inline-flex items-center gap-0.5 whitespace-nowrap rounded-full border border-red-300 bg-red-100 px-1.5 py-0.5 text-[10px] font-bold leading-tight text-red-800"
                                                >
                                                    🚨 Créd. vencido
                                                </span>
                                                <span
                                                    v-if="Number(auto.saldo_pendiente_pago || 0) > 0.009"
                                                    class="inline-flex items-center gap-0.5 whitespace-nowrap rounded-full border border-amber-300 bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold leading-tight text-amber-900"
                                                >
                                                    ⏳ {{ formatearMoneda(auto.saldo_pendiente_pago) }}
                                                </span>
                                            </template>
                                            <span
                                                v-else
                                                class="inline-flex items-center gap-0.5 whitespace-nowrap rounded-full border border-green-200 bg-green-50 px-2 py-0.5 text-[10px] font-semibold leading-tight text-green-700"
                                            >
                                                ✅ Al día
                                            </span>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-1.5 py-1.5 text-center">
                                        <span
                                            v-if="auto.entrega_llaves_solicitada"
                                            class="inline-flex items-center rounded-full border border-green-300 bg-green-100 px-2 py-0.5 text-[10px] font-bold leading-tight text-green-800"
                                        >
                                            Sí
                                        </span>
                                        <span
                                            v-else
                                            class="inline-flex items-center rounded-full border border-amber-300 bg-amber-50 px-2 py-0.5 text-[10px] font-semibold leading-tight text-amber-800"
                                        >
                                            No
                                        </span>
                                    </td>
                                    <td class="max-w-[210px] px-2 py-1.5">
                                        <div v-if="documentosFaltantes(auto).length === 0">
                                            <span class="inline-flex items-center gap-0.5 whitespace-nowrap rounded-full border border-green-200 bg-green-50 px-2 py-0.5 text-[10px] font-semibold leading-tight text-green-700">
                                                ✅ Completo
                                            </span>
                                        </div>
                                        <div v-else class="flex flex-wrap gap-0.5">
                                            <span
                                                v-for="faltante in documentosFaltantes(auto)"
                                                :key="faltante.texto"
                                                class="inline-flex items-center whitespace-nowrap rounded-full border px-1.5 py-0.5 text-[10px] font-bold leading-tight"
                                                :class="faltante.severidad === 'red'
                                                    ? 'border-red-300 bg-red-100 text-red-800'
                                                    : 'border-amber-300 bg-amber-100 text-amber-900'"
                                            >
                                                {{ faltante.texto }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-2 py-1.5">
                                        <span class="rounded-full border border-yellow-300 bg-yellow-100 px-2 py-0.5 text-[10px] font-bold leading-tight text-yellow-800">
                                            {{ auto.estado?.nombre }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-2 py-1.5 text-center">
                                        <span class="inline-flex items-center rounded bg-gray-900 px-2 py-0.5 text-[10px] font-bold text-yellow-400 hover:bg-gray-700">
                                            Ficha &rarr;
                                        </span>
                                    </td>
                                </tr>

                                <tr v-if="!vehiculosFiltrados.length">
                                    <td :colspan="puedeVerDatosFinancieros ? 10 : 12" class="px-4 py-8 text-center text-xs text-gray-500">
                                        {{ vehiculos.length === 0
                                            ? 'No hay vehículos registrados en el inventario todavía.'
                                            : 'Ningún vehículo coincide con los filtros seleccionados.' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- MODAL DESACOPLADO (MONTADO BAJO DEMANDA CON v-if) -->
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <DetalleVehiculoModal
                v-if="vehiculoSeleccionado"
                :key="vehiculoSeleccionado.id_vehiculo"
                :vehiculo="vehiculoSeleccionado"
                :patios="patios"
                :usuarios="usuarios"
                :usuario-actual="usuarioActual"
                :success="success"
                @close="cerrarDetalles"
            />
        </Transition>
    </AuthenticatedLayout>
</template>
