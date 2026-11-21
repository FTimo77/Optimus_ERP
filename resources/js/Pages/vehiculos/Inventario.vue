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
            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">
                        Inventario General de Vehículos
                    </h2>
                    <p class="mt-1 text-xs text-gray-500">
                        Haz clic sobre cualquier vehículo para abrir su tarjeta, liquidar multas y gestionar la entrega-recepción de documentos y llaves.
                    </p>
                </div>
                <Link
                    :href="route('vehiculos.create')"
                    class="w-fit rounded-md bg-yellow-500 px-4 py-2 text-sm font-bold text-black shadow transition hover:bg-yellow-600"
                >
                    + Nuevo Ingreso
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                <div
                    v-if="success"
                    role="status"
                    class="rounded-md border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-800"
                >
                    {{ success }}
                </div>

                <!-- BARRA DE FILTROS MULTICRITERIO Y BÚSQUEDA -->
                <div class="space-y-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <div class="lg:col-span-2">
                            <label for="filtro-busqueda" class="block text-xs font-semibold text-gray-700">
                                Buscar por placa, marca o modelo
                            </label>
                            <input
                                id="filtro-busqueda"
                                v-model="busqueda"
                                type="search"
                                placeholder="Ej. PBA1234 o Toyota Corolla..."
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-yellow-500 focus:ring-yellow-500"
                            />
                        </div>

                        <div>
                            <label for="filtro-marca" class="block text-xs font-semibold text-gray-700">Marca</label>
                            <select
                                id="filtro-marca"
                                v-model="filtroMarca"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-yellow-500 focus:ring-yellow-500"
                            >
                                <option value="">Todas</option>
                                <option v-for="marca in marcasDisponibles" :key="marca" :value="marca">
                                    {{ marca }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label for="filtro-estado" class="block text-xs font-semibold text-gray-700">Estado</label>
                            <select
                                id="filtro-estado"
                                v-model="filtroEstado"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-yellow-500 focus:ring-yellow-500"
                            >
                                <option value="">Todos</option>
                                <option v-for="estado in estadosDisponibles" :key="estado" :value="estado">
                                    {{ estado }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label for="filtro-patio" class="block text-xs font-semibold text-gray-700">Patio</label>
                            <select
                                id="filtro-patio"
                                v-model="filtroPatio"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-yellow-500 focus:ring-yellow-500"
                            >
                                <option value="">Todos</option>
                                <option v-for="patio in patiosDisponibles" :key="patio" :value="patio">
                                    {{ patio }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="flex flex-col justify-between gap-3 border-t border-gray-100 pt-3 sm:flex-row sm:items-center">
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="flex items-center gap-2">
                                <label for="filtro-metodo-ingreso" class="text-xs font-semibold text-gray-700">Método de ingreso:</label>
                                <select
                                    id="filtro-metodo-ingreso"
                                    v-model="filtroMetodoIngreso"
                                    class="rounded-md border-gray-300 py-1 text-xs shadow-sm focus:border-yellow-500 focus:ring-yellow-500"
                                >
                                    <option value="">Todos</option>
                                    <option value="particular">Particular</option>
                                    <option value="empresa">Empresa</option>
                                    <option value="nuevos">Nuevos</option>
                                </select>
                            </div>
                            <div class="flex items-center gap-2">
                                <label for="filtro-alerta" class="text-xs font-semibold text-gray-700">Alerta:</label>
                                <select
                                    id="filtro-alerta"
                                    v-model="filtroAlerta"
                                    class="rounded-md border-gray-300 py-1 text-xs shadow-sm focus:border-yellow-500 focus:ring-yellow-500"
                                >
                                    <option value="">Todas</option>
                                    <option value="en_alerta">En alerta</option>
                                    <option value="sin_alerta">Sin alerta / Vigente</option>
                                </select>
                            </div>
                            <span class="text-xs text-gray-600">
                                Mostrando <strong class="text-gray-900">{{ vehiculosFiltrados.length }}</strong> de
                                <strong class="text-gray-900">{{ vehiculos.length }}</strong> vehículo(s)
                            </span>
                        </div>

                        <button
                            type="button"
                            :disabled="!hayFiltrosActivos"
                            class="w-fit rounded-md border border-gray-300 bg-gray-50 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="limpiarFiltros"
                        >
                            Limpiar filtros
                        </button>
                    </div>
                </div>

                <!-- TABLA PRINCIPAL DEL INVENTARIO -->
                <div class="overflow-hidden border border-gray-200 bg-white shadow-sm sm:rounded-lg">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-900 text-white">
                                <tr>
                                    <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Placa</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Marca</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Modelo</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Ubicación</th>
                                    <th class="w-32 max-w-[130px] px-3 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Tipo de Contrato</th>
                                    <th class="min-w-[230px] px-4 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Alertas Pagos / Multas</th>
                                    <th class="px-4 py-3.5 text-center text-xs font-bold uppercase tracking-wider">Entrega Llaves</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Alertas Documentación</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Estado</th>
                                    <th class="px-4 py-3.5 text-center text-xs font-bold uppercase tracking-wider">Detalle</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr
                                    v-for="auto in vehiculosFiltrados"
                                    :key="auto.id_vehiculo"
                                    class="cursor-pointer transition duration-150 hover:bg-yellow-50"
                                    @click="abrirDetalles(auto)"
                                >
                                    <td class="whitespace-nowrap px-4 py-4 font-mono text-sm font-bold text-gray-900">
                                        {{ auto.id_vehiculo || auto.placa }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4 text-sm font-bold text-gray-900">
                                        {{ auto.marca?.nombre || 'No registrada' }}
                                    </td>
                                    <td class="px-4 py-4 text-sm text-gray-900">
                                        <div class="font-semibold">{{ auto.modelo }}</div>
                                        <div class="text-xs text-gray-500">{{ auto.clase?.nombre }} • {{ auto.transmision?.nombre }}</div>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4 text-sm text-gray-700">
                                        {{ auto.patio?.nombre || 'Sin asignar' }}
                                    </td>
                                    <td class="w-32 max-w-[130px] px-3 py-4 text-xs">
                                        <span
                                            v-if="auto.tipo_contrato?.nombre || auto.tipo_contrato_nombre"
                                            class="inline-flex rounded-md bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold leading-tight text-indigo-800"
                                        >
                                            {{ auto.tipo_contrato?.nombre || auto.tipo_contrato_nombre }}
                                        </span>
                                        <span v-else class="text-gray-400">Sin asignar</span>
                                    </td>
                                    <td class="min-w-[230px] px-4 py-4 text-xs">
                                        <div class="flex flex-col items-start gap-1">
                                            <template v-if="tieneAlertaPagosMultas(auto)">
                                                <span
                                                    v-if="auto.multas_pendientes_count > 0 && auto.multas_requieren_revision_manual && Number(auto.multas_pendientes_total || 0) <= 0"
                                                    class="inline-flex items-center gap-1 rounded-full border border-amber-300 bg-amber-100 px-2 py-0.5 font-bold text-amber-900"
                                                >
                                                    ⚠️ {{ auto.multas_pendientes_count }} multa(s) · Rev. manual ($0.00)
                                                </span>
                                                <span
                                                    v-else-if="auto.multas_pendientes_count > 0"
                                                    class="inline-flex items-center gap-1 rounded-full border border-red-300 bg-red-100 px-2 py-0.5 font-bold text-red-800"
                                                >
                                                    ⚠️ {{ auto.multas_pendientes_count }} multa(s) · {{ formatearMoneda(auto.multas_pendientes_total) }}{{ auto.multas_requieren_revision_manual ? ' · Rev. manual' : '' }}
                                                </span>
                                                <span
                                                    v-if="auto.credito_vencido"
                                                    class="inline-flex items-center gap-1 rounded-full border border-red-300 bg-red-100 px-2 py-0.5 font-bold text-red-800"
                                                >
                                                    🚨 Crédito vencido
                                                </span>
                                                <span
                                                    v-if="Number(auto.saldo_pendiente_pago || 0) > 0.009"
                                                    class="inline-flex items-center gap-1 rounded-full border border-amber-300 bg-amber-100 px-2 py-0.5 font-bold text-amber-900"
                                                >
                                                    ⏳ Saldo: {{ formatearMoneda(auto.saldo_pendiente_pago) }}
                                                </span>
                                            </template>
                                            <span
                                                v-else
                                                class="inline-flex items-center gap-1 rounded-full border border-green-200 bg-green-50 px-2.5 py-0.5 font-semibold text-green-700"
                                            >
                                                ✅ Al día
                                            </span>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4 text-center text-xs">
                                        <span
                                            v-if="auto.entrega_llaves_solicitada"
                                            class="inline-flex items-center rounded-full border border-green-300 bg-green-100 px-2.5 py-0.5 font-bold text-green-800"
                                        >
                                            Sí
                                        </span>
                                        <span
                                            v-else
                                            class="inline-flex items-center rounded-full border border-amber-300 bg-amber-50 px-2.5 py-0.5 font-semibold text-amber-800"
                                        >
                                            No
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-xs">
                                        <div v-if="documentosFaltantes(auto).length === 0">
                                            <span class="inline-flex items-center gap-1 rounded-full border border-green-200 bg-green-50 px-2.5 py-0.5 font-semibold text-green-700">
                                                ✅ Completo
                                            </span>
                                        </div>
                                        <div v-else class="flex flex-wrap gap-1">
                                            <span
                                                v-for="faltante in documentosFaltantes(auto)"
                                                :key="faltante.texto"
                                                class="inline-flex items-center rounded-full border px-2 py-0.5 font-bold"
                                                :class="faltante.severidad === 'red'
                                                    ? 'border-red-300 bg-red-100 text-red-800'
                                                    : 'border-amber-300 bg-amber-100 text-amber-900'"
                                            >
                                                Falta: {{ faltante.texto }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4 text-xs">
                                        <span class="rounded-full border border-yellow-300 bg-yellow-100 px-2.5 py-1 font-bold text-yellow-800">
                                            {{ auto.estado?.nombre }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4 text-center">
                                        <span class="inline-flex items-center rounded bg-gray-900 px-3 py-1 text-xs font-bold text-yellow-400 hover:bg-gray-700">
                                            Abrir Ficha &rarr;
                                        </span>
                                    </td>
                                </tr>

                                <tr v-if="!vehiculosFiltrados.length">
                                    <td colspan="10" class="px-6 py-10 text-center text-gray-500">
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
