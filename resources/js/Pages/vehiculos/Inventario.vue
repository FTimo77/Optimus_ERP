<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';

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

const ITEMS_CUSTODIA = [
    { key: 'matricula', label: 'Documento de matrícula' },
    { key: 'rtv', label: 'Revisión Técnica Vehicular (RTV)' },
    { key: 'contrato_legalizado', label: 'Contrato legalizado' },
    { key: 'llaves', label: 'Llaves del vehículo' },
];

const vehiculoSeleccionadoId = ref(null);
const vehiculoSeleccionado = computed(() =>
    props.vehiculos.find((v) => v.id_vehiculo === vehiculoSeleccionadoId.value) || null,
);
const todosDocumentosRecibidos = computed(() => {
    const lista = vehiculoSeleccionado.value?.custodia_actual || [];
    return lista.length > 0 && lista.every((registro) => registro.estado === 'recibido');
});

const archivosMultas = reactive({});
const pagoMultaForm = useForm({
    comprobante: null,
});
const multaProcesandoId = ref(null);

const entregaForm = useForm({
    items: [],
    usuario_recepcion_id: '',
    patio_destino_id: '',
    observacion: '',
});

const recepcionForm = useForm({});
const custodiaProcesandoId = ref(null);

const abrirDetalles = (auto) => {
    vehiculoSeleccionadoId.value = auto.id_vehiculo;
    entregaForm.reset();
    entregaForm.clearErrors();
    pagoMultaForm.clearErrors();
    recepcionForm.clearErrors();
};

const cerrarDetalles = () => {
    vehiculoSeleccionadoId.value = null;
};

const manejarTecla = (e) => {
    if (e.key === 'Escape' && vehiculoSeleccionadoId.value) {
        cerrarDetalles();
    }
};

onMounted(() => window.addEventListener('keydown', manejarTecla));
onUnmounted(() => window.removeEventListener('keydown', manejarTecla));

const formatearFecha = (fecha) => {
    if (!fecha) return 'No registrada';
    return new Date(fecha).toLocaleString('es-EC', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const formatearMoneda = (valor) =>
    `$${Number(valor || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const seleccionarComprobanteMulta = (idMulta, event) => {
    archivosMultas[idMulta] = event.target.files[0] || null;
};

const saldarMulta = (idVehiculo, idMulta) => {
    const archivo = archivosMultas[idMulta];
    if (!archivo) {
        return;
    }

    multaProcesandoId.value = idMulta;
    pagoMultaForm.comprobante = archivo;
    pagoMultaForm.post(route('vehiculos.multas.pagar', [idVehiculo, idMulta]), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            delete archivosMultas[idMulta];
            pagoMultaForm.reset();
        },
        onFinish: () => {
            multaProcesandoId.value = null;
        },
    });
};

const seleccionarPaqueteCompleto = () => {
    if (!vehiculoSeleccionado.value) return;
    const disponibles = (vehiculoSeleccionado.value.custodia_actual || [])
        .filter((c) => c.estado !== 'pendiente_recepcion')
        .map((c) => c.item);
    entregaForm.items = disponibles;
};

const enviarSolicitudEntrega = () => {
    if (!vehiculoSeleccionado.value || entregaForm.items.length === 0 || !entregaForm.patio_destino_id) {
        return;
    }

    entregaForm.post(route('vehiculos.custodia.solicitar', vehiculoSeleccionado.value.id_vehiculo), {
        preserveScroll: true,
        onSuccess: () => {
            entregaForm.reset();
        },
    });
};

const puedeConfirmarRecepcion = (registro) => {
    if (registro.estado !== 'pendiente_recepcion' || !registro.id_custodia) {
        return false;
    }
    if (!registro.usuario_recepcion_id) {
        return true;
    }
    return (
        Number(registro.usuario_recepcion_id) === Number(props.usuarioActual?.id)
        || Boolean(props.usuarioActual?.es_admin)
    );
};

const marcarComoRecibido = (idVehiculo, idCustodia) => {
    custodiaProcesandoId.value = idCustodia;
    recepcionForm.patch(route('vehiculos.custodia.recibir', [idVehiculo, idCustodia]), {
        preserveScroll: true,
        onFinish: () => {
            custodiaProcesandoId.value = null;
        },
    });
};

const estadoCustodiaTexto = (estado) => ({
    sin_asignar: 'Sin asignar',
    pendiente_recepcion: 'Pendiente de recepción',
    recibido: 'Recibido',
}[estado] || estado);

const estadoCustodiaClases = (estado) => ({
    sin_asignar: 'bg-gray-100 text-gray-600',
    pendiente_recepcion: 'bg-amber-100 text-amber-900 border border-amber-300',
    recibido: 'bg-green-100 text-green-800 border border-green-300',
}[estado] || 'bg-gray-100 text-gray-700');
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

                <div class="overflow-hidden border border-gray-200 bg-white shadow-sm sm:rounded-lg">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-900 text-white">
                                <tr>
                                    <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Placa</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Vehículo</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Año / Color</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Ubicación</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Alertas</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Estado</th>
                                    <th class="px-6 py-3.5 text-center text-xs font-bold uppercase tracking-wider">Detalle</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr
                                    v-for="auto in vehiculos"
                                    :key="auto.id_vehiculo"
                                    class="cursor-pointer transition duration-150 hover:bg-yellow-50"
                                    @click="abrirDetalles(auto)"
                                >
                                    <td class="whitespace-nowrap px-6 py-4 font-mono text-sm font-bold text-gray-900">
                                        {{ auto.id_vehiculo }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900">
                                        <div class="text-base font-bold">{{ auto.marca?.nombre }} {{ auto.modelo }}</div>
                                        <div class="text-xs text-gray-500">{{ auto.clase?.nombre }} • {{ auto.transmision?.nombre }}</div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        <span class="font-semibold">{{ auto.anio }}</span>
                                        <span class="block text-xs text-gray-500">{{ auto.color?.nombre }}</span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        {{ auto.patio?.nombre || 'Sin asignar' }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm">
                                        <span
                                            v-if="auto.multas_pendientes_count > 0"
                                            class="inline-flex items-center gap-1 rounded-full border border-red-300 bg-red-100 px-2.5 py-1 text-xs font-bold text-red-800"
                                        >
                                            ⚠️ {{ auto.multas_pendientes_count }} pendiente(s) · {{ formatearMoneda(auto.multas_pendientes_total) }}
                                        </span>
                                        <span
                                            v-else
                                            class="inline-flex items-center gap-1 rounded-full border border-green-200 bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700"
                                        >
                                            ✅ Sin alertas
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm">
                                        <span class="rounded-full border border-yellow-300 bg-yellow-100 px-2.5 py-1 text-xs font-bold text-yellow-800">
                                            {{ auto.estado?.nombre }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-center">
                                        <span class="inline-flex items-center rounded bg-gray-900 px-3 py-1 text-xs font-bold text-yellow-400 hover:bg-gray-700">
                                            Abrir Ficha &rarr;
                                        </span>
                                    </td>
                                </tr>

                                <tr v-if="!vehiculos || vehiculos.length === 0">
                                    <td colspan="7" class="px-6 py-10 text-center text-gray-500">
                                        No hay vehículos registrados en el inventario todavía.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TARJETA / EXPEDIENTE DETALLADO DEL VEHÍCULO -->
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="vehiculoSeleccionado"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-xs"
                @click.self="cerrarDetalles"
            >
                <div class="w-full max-w-5xl overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl">
                    <!-- Cabecera -->
                    <div class="flex items-start justify-between border-b-4 border-yellow-500 bg-gray-900 px-6 py-5 text-white">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded bg-yellow-500 px-2.5 py-0.5 font-mono text-xs font-extrabold uppercase text-black">
                                    Placa {{ vehiculoSeleccionado.id_vehiculo }}
                                </span>
                                <span class="rounded-full bg-gray-700 px-2.5 py-0.5 text-xs font-semibold text-yellow-300">
                                    Estado: {{ vehiculoSeleccionado.estado?.nombre }}
                                </span>
                                <span
                                    v-if="vehiculoSeleccionado.multas_pendientes_count > 0"
                                    class="rounded-full bg-red-600 px-2.5 py-0.5 text-xs font-bold text-white"
                                >
                                    ⚠️ {{ vehiculoSeleccionado.multas_pendientes_count }} multa(s) pendiente(s) · {{ formatearMoneda(vehiculoSeleccionado.multas_pendientes_total) }}
                                </span>
                                <span
                                    v-else
                                    class="rounded-full bg-emerald-700 px-2.5 py-0.5 text-xs font-semibold text-emerald-100"
                                >
                                    ✅ Sin multas pendientes
                                </span>
                            </div>
                            <h3 class="mt-2 text-2xl font-extrabold">
                                {{ vehiculoSeleccionado.marca?.nombre }} {{ vehiculoSeleccionado.modelo }}
                                <span class="font-normal text-yellow-400">({{ vehiculoSeleccionado.anio }})</span>
                            </h3>
                            <p class="mt-1 text-xs text-gray-400">
                                Ingresado al sistema: {{ formatearFecha(vehiculoSeleccionado.created_at) }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-9 w-9 items-center justify-center rounded-lg bg-gray-800 text-xl font-bold text-gray-400 transition hover:bg-gray-700 hover:text-white"
                            title="Cerrar (Esc)"
                            @click="cerrarDetalles"
                        >
                            &times;
                        </button>
                    </div>

                    <!-- Cuerpo -->
                    <div class="max-h-[78vh] space-y-6 overflow-y-auto p-6">
                        <div
                            v-if="success"
                            role="status"
                            class="rounded-md border border-green-200 bg-green-50 p-3 text-xs font-semibold text-green-800"
                        >
                            {{ success }}
                        </div>

                        <!-- Imagen del vehículo -->
                        <div v-if="vehiculoSeleccionado.imagen_url" class="overflow-hidden rounded-lg border border-gray-200 bg-gray-50">
                            <img
                                :src="vehiculoSeleccionado.imagen_url"
                                :alt="`Imagen de ${vehiculoSeleccionado.marca?.nombre ?? ''} ${vehiculoSeleccionado.modelo}`"
                                class="max-h-64 w-full object-contain"
                            />
                        </div>

                        <!-- A. TABLA INFORMATIVA DE DATOS DEL VEHÍCULO -->
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
                                            <td class="w-1/4 px-4 py-2.5 font-mono font-bold text-gray-900">{{ vehiculoSeleccionado.id_vehiculo }}</td>
                                            <th class="w-1/4 bg-gray-50 px-4 py-2.5 text-left font-semibold text-gray-600">Marca</th>
                                            <td class="w-1/4 px-4 py-2.5 text-gray-900">{{ vehiculoSeleccionado.marca?.nombre || 'No registrada' }}</td>
                                        </tr>
                                        <tr>
                                            <th class="bg-gray-50 px-4 py-2.5 text-left font-semibold text-gray-600">Modelo</th>
                                            <td class="px-4 py-2.5 text-gray-900">{{ vehiculoSeleccionado.modelo || 'No registrado' }}</td>
                                            <th class="bg-gray-50 px-4 py-2.5 text-left font-semibold text-gray-600">Color</th>
                                            <td class="px-4 py-2.5 text-gray-900">{{ vehiculoSeleccionado.color?.nombre || 'No registrado' }}</td>
                                        </tr>
                                        <tr>
                                            <th class="bg-gray-50 px-4 py-2.5 text-left font-semibold text-gray-600">Año</th>
                                            <td class="px-4 py-2.5 text-gray-900">{{ vehiculoSeleccionado.anio }}</td>
                                            <th class="bg-gray-50 px-4 py-2.5 text-left font-semibold text-gray-600">Precio de adquisición</th>
                                            <td class="px-4 py-2.5 font-bold text-gray-900">{{ formatearMoneda(vehiculoSeleccionado.costo_adquisicion) }}</td>
                                        </tr>
                                        <tr>
                                            <th class="bg-gray-50 px-4 py-2.5 text-left font-semibold text-gray-600">Estado del sistema</th>
                                            <td class="px-4 py-2.5">
                                                <span class="rounded-full bg-yellow-100 px-2.5 py-0.5 text-xs font-bold text-yellow-800">
                                                    {{ vehiculoSeleccionado.estado?.nombre || 'Sin estado' }}
                                                </span>
                                            </td>
                                            <th class="bg-gray-50 px-4 py-2.5 text-left font-semibold text-gray-600">Proveedor</th>
                                            <td class="px-4 py-2.5 text-gray-900">{{ vehiculoSeleccionado.proveedor_nombre || 'No registrado' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <!-- B. SECCIÓN DE MULTAS Y VALORES PENDIENTES -->
                        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 px-4 py-3">
                                <div>
                                    <h4 class="text-sm font-bold uppercase tracking-wide text-gray-800">
                                        B. Multas y Valores Pendientes
                                    </h4>
                                    <p class="text-xs text-gray-500">
                                        Sube el comprobante de pago (PDF o imagen) para saldar cada multa y despejar la alerta del inventario.
                                    </p>
                                </div>
                                <span
                                    v-if="vehiculoSeleccionado.multas_pendientes_count > 0"
                                    class="rounded-full bg-red-100 px-3 py-1 text-xs font-bold text-red-800"
                                >
                                    Pendiente: {{ formatearMoneda(vehiculoSeleccionado.multas_pendientes_total) }}
                                </span>
                            </div>

                            <p
                                v-if="pagoMultaForm.errors.comprobante"
                                role="alert"
                                class="border-b border-red-200 bg-red-50 px-4 py-2 text-xs font-medium text-red-700"
                            >
                                {{ pagoMultaForm.errors.comprobante }}
                            </p>

                            <div
                                v-if="!vehiculoSeleccionado.multas_detalle || vehiculoSeleccionado.multas_detalle.length === 0"
                                class="p-4 text-sm text-green-700"
                            >
                                ✅ Este vehículo no registra multas ni valores pendientes.
                            </div>

                            <div v-else class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-xs">
                                    <thead class="bg-gray-100 text-left font-semibold uppercase tracking-wide text-gray-600">
                                        <tr>
                                            <th class="px-4 py-2.5">Fuente / Concepto</th>
                                            <th class="px-4 py-2.5">Fecha</th>
                                            <th class="px-4 py-2.5 text-right">Valor</th>
                                            <th class="px-4 py-2.5">Estado</th>
                                            <th class="px-4 py-2.5">Comprobante de pago</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr v-for="multa in vehiculoSeleccionado.multas_detalle" :key="multa.id_multa">
                                            <td class="px-4 py-3">
                                                <span class="font-bold text-gray-900">{{ multa.fuente }}</span>
                                                <p class="mt-0.5 text-gray-600">{{ multa.concepto }}</p>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 text-gray-600">
                                                {{ multa.fecha_infraccion || 'N/D' }}
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 text-right font-mono font-bold text-gray-900">
                                                {{ formatearMoneda(multa.valor) }}
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3">
                                                <span
                                                    v-if="multa.estado === 'pagada'"
                                                    class="rounded-full bg-green-100 px-2.5 py-0.5 font-semibold text-green-800"
                                                >
                                                    Saldada
                                                </span>
                                                <span
                                                    v-else
                                                    class="rounded-full bg-red-100 px-2.5 py-0.5 font-semibold text-red-800"
                                                >
                                                    Pendiente
                                                </span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div
                                                    v-if="multa.estado === 'pendiente'"
                                                    class="flex flex-col gap-2 sm:flex-row sm:items-center"
                                                >
                                                    <input
                                                        type="file"
                                                        accept="image/jpeg,image/png,image/webp,.pdf"
                                                        class="block max-w-[200px] text-xs text-gray-600 file:mr-2 file:rounded file:border-0 file:bg-gray-800 file:px-2.5 file:py-1 file:text-white hover:file:bg-gray-700"
                                                        @change="seleccionarComprobanteMulta(multa.id_multa, $event)"
                                                    />
                                                    <button
                                                        type="button"
                                                        :disabled="!archivosMultas[multa.id_multa] || multaProcesandoId === multa.id_multa"
                                                        class="whitespace-nowrap rounded bg-emerald-700 px-3 py-1.5 text-xs font-bold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                                                        @click="saldarMulta(vehiculoSeleccionado.id_vehiculo, multa.id_multa)"
                                                    >
                                                        {{ multaProcesandoId === multa.id_multa ? 'Guardando...' : 'Subir y saldar' }}
                                                    </button>
                                                </div>
                                                <div v-else class="space-y-0.5">
                                                    <a
                                                        v-if="multa.comprobante_url"
                                                        :href="multa.comprobante_url"
                                                        class="font-semibold text-indigo-700 hover:text-indigo-900"
                                                    >
                                                        Descargar comprobante
                                                    </a>
                                                    <p v-if="multa.pagado_por" class="text-[11px] text-gray-500">
                                                        Por {{ multa.pagado_por }} · {{ formatearFecha(multa.pagado_en) }}
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <!-- C. TABLA / FLUJO DE ENTREGA-RECEPCIÓN DE DOCUMENTOS Y LLAVES POR PATIO -->
                        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 px-4 py-3">
                                <div>
                                    <h4 class="text-sm font-bold uppercase tracking-wide text-gray-800">
                                        C. Entrega - Recepción de Documentos y Llaves por Patio
                                    </h4>
                                    <p class="text-xs text-gray-500">
                                        Al confirmar como <strong>Recibido</strong>, la custodia queda ligada automáticamente al <strong>patio destino</strong> seleccionado en la solicitud de entrega.
                                    </p>
                                </div>
                            </div>

                            <div
                                v-if="recepcionForm.errors.custodia"
                                role="alert"
                                class="border-b border-red-200 bg-red-50 px-4 py-2 text-xs font-medium text-red-700"
                            >
                                {{ recepcionForm.errors.custodia }}
                            </div>

                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-xs">
                                    <thead class="bg-gray-100 text-left font-semibold uppercase tracking-wide text-gray-600">
                                        <tr>
                                            <th class="px-4 py-2.5">Ítem</th>
                                            <th class="px-4 py-2.5">Estado actual</th>
                                            <th class="px-4 py-2.5">Entregó</th>
                                            <th class="px-4 py-2.5">Recibió / Destinatario</th>
                                            <th class="px-4 py-2.5">Fecha / Hora</th>
                                            <th class="px-4 py-2.5">Patio actual</th>
                                            <th class="px-4 py-2.5 text-right">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr v-for="registro in vehiculoSeleccionado.custodia_actual" :key="registro.item">
                                            <td class="px-4 py-3 font-semibold text-gray-900">
                                                {{ registro.etiqueta }}
                                                <p v-if="registro.observacion" class="mt-0.5 text-[11px] font-normal italic text-gray-500">
                                                    "{{ registro.observacion }}"
                                                </p>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3">
                                                <span
                                                    :class="estadoCustodiaClases(registro.estado)"
                                                    class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold"
                                                >
                                                    {{ estadoCustodiaTexto(registro.estado) }}
                                                </span>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">
                                                {{ registro.usuario_entrega || '—' }}
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">
                                                {{ registro.usuario_recepcion || (registro.estado === 'pendiente_recepcion' ? 'Cualquier receptor' : '—') }}
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 text-gray-600">
                                                {{ registro.recibido_en ? formatearFecha(registro.recibido_en) : (registro.solicitado_en ? formatearFecha(registro.solicitado_en) : '—') }}
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3">
                                                <span class="font-semibold text-gray-900">{{ registro.patio_actual }}</span>
                                                <span
                                                    v-if="registro.estado === 'pendiente_recepcion' && registro.patio_destino"
                                                    class="block text-[11px] text-amber-700"
                                                >
                                                    Destino: {{ registro.patio_destino }}
                                                </span>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                                <button
                                                    v-if="puedeConfirmarRecepcion(registro)"
                                                    type="button"
                                                    :disabled="custodiaProcesandoId === registro.id_custodia"
                                                    class="rounded bg-emerald-700 px-3 py-1.5 text-xs font-bold text-white hover:bg-emerald-800 disabled:opacity-50"
                                                    @click="marcarComoRecibido(vehiculoSeleccionado.id_vehiculo, registro.id_custodia)"
                                                >
                                                    {{ custodiaProcesandoId === registro.id_custodia ? 'Confirmando...' : 'Recibido' }}
                                                </button>
                                                <span v-else class="text-gray-400">—</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Formulario de nueva solicitud de entrega (oculto si todos los ítems ya fueron recibidos) -->
                            <div
                                v-if="todosDocumentosRecibidos"
                                class="border-t border-green-200 bg-green-50 px-4 py-3 text-xs font-semibold text-green-800"
                            >
                                ✅ Todos los documentos y llaves fueron marcados como recibidos en su patio correspondiente.
                            </div>
                            <form
                                v-else
                                class="space-y-4 border-t border-gray-200 bg-gray-50 p-4"
                                @submit.prevent="enviarSolicitudEntrega"
                            >
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs font-bold uppercase tracking-wide text-gray-800">
                                        Enviar solicitud de entrega
                                    </p>
                                    <button
                                        type="button"
                                        class="text-xs font-semibold text-indigo-700 hover:text-indigo-900"
                                        @click="seleccionarPaqueteCompleto"
                                    >
                                        Seleccionar paquete completo disponible
                                    </button>
                                </div>

                                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                    <label
                                        v-for="item in ITEMS_CUSTODIA"
                                        :key="item.key"
                                        class="flex cursor-pointer items-center gap-2 rounded border border-gray-200 bg-white px-3 py-2 text-xs text-gray-800 hover:bg-yellow-50"
                                    >
                                        <input
                                            v-model="entregaForm.items"
                                            type="checkbox"
                                            :value="item.key"
                                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                        />
                                        <span>{{ item.label }}</span>
                                    </label>
                                </div>

                                <p v-if="entregaForm.errors.items" role="alert" class="text-xs font-medium text-red-600">
                                    {{ entregaForm.errors.items }}
                                </p>

                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700">Patio destino *</label>
                                        <select
                                            v-model="entregaForm.patio_destino_id"
                                            required
                                            class="mt-1 block w-full rounded-md border-gray-300 text-xs shadow-sm"
                                        >
                                            <option value="" disabled>Seleccione patio destino...</option>
                                            <option v-for="p in patios" :key="p.id_patio" :value="p.id_patio">
                                                {{ p.nombre }}
                                            </option>
                                        </select>
                                        <p v-if="entregaForm.errors.patio_destino_id" role="alert" class="mt-1 text-xs text-red-600">
                                            {{ entregaForm.errors.patio_destino_id }}
                                        </p>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700">Usuario receptor (opcional)</label>
                                        <select
                                            v-model="entregaForm.usuario_recepcion_id"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-xs shadow-sm"
                                        >
                                            <option value="">Cualquier usuario receptor</option>
                                            <option v-for="u in usuarios" :key="u.id" :value="u.id">
                                                {{ u.name }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700">Observación (opcional)</label>
                                        <input
                                            v-model="entregaForm.observacion"
                                            type="text"
                                            maxlength="500"
                                            placeholder="Ej. Entrega con 2 copias de llave..."
                                            class="mt-1 block w-full rounded-md border-gray-300 text-xs shadow-sm"
                                        />
                                    </div>
                                </div>

                                <div class="flex justify-end">
                                    <button
                                        type="submit"
                                        :disabled="entregaForm.processing || entregaForm.items.length === 0 || !entregaForm.patio_destino_id"
                                        class="rounded-md bg-gray-900 px-4 py-2 text-xs font-bold text-yellow-400 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {{ entregaForm.processing ? 'Enviando solicitud...' : 'Enviar solicitud de entrega' }}
                                    </button>
                                </div>
                            </form>
                        </section>
                    </div>

                    <!-- Pie -->
                    <div class="flex justify-end border-t border-gray-200 bg-gray-50 px-6 py-4">
                        <button
                            type="button"
                            class="rounded-md bg-gray-800 px-5 py-2 text-sm font-semibold text-white transition hover:bg-gray-900"
                            @click="cerrarDetalles"
                        >
                            Cerrar Expediente
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </AuthenticatedLayout>
</template>
