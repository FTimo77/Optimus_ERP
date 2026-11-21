<script setup>
import { formatearFecha, ITEMS_CUSTODIA } from '@/Composables/useInventarioVehiculos';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

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
});

const entregaForm = useForm({
    items: [],
    usuario_recepcion_id: '',
    patio_destino_id: '',
    observacion: '',
});

const recepcionForm = useForm({});
const custodiaProcesandoId = ref(null);

const todosDocumentosRecibidos = computed(() => {
    const lista = props.vehiculo?.custodia_actual || [];
    return lista.length > 0 && lista.every((registro) => registro.estado === 'recibido');
});

const seleccionarPaqueteCompleto = () => {
    const disponibles = (props.vehiculo?.custodia_actual || [])
        .filter((c) => c.estado !== 'pendiente_recepcion')
        .map((c) => c.item);
    entregaForm.items = disponibles;
};

const enviarSolicitudEntrega = () => {
    if (entregaForm.items.length === 0 || !entregaForm.patio_destino_id) {
        return;
    }

    entregaForm.post(route('vehiculos.custodia.solicitar', props.vehiculo.id_vehiculo), {
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

const marcarComoRecibido = (idCustodia) => {
    custodiaProcesandoId.value = idCustodia;
    recepcionForm.patch(route('vehiculos.custodia.recibir', [props.vehiculo.id_vehiculo, idCustodia]), {
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
                    <tr v-for="registro in vehiculo.custodia_actual" :key="registro.item">
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
                                @click="marcarComoRecibido(registro.id_custodia)"
                            >
                                {{ custodiaProcesandoId === registro.id_custodia ? 'Confirmando...' : 'Recibido' }}
                            </button>
                            <span v-else class="text-gray-400">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

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
</template>
