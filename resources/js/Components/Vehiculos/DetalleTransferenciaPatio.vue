<script setup>
import { ITEMS_CUSTODIA } from '@/Composables/useInventarioVehiculos';
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
});

const mostrarFormulario = ref(false);

const transferenciaForm = useForm({
    patio_destino_id: '',
    usuario_recepcion_id: '',
    items: [],
    observacion: '',
});

const patiosDestinoDisponibles = computed(() =>
    (props.patios || []).filter(
        (p) => Number(p.id_patio) !== Number(props.vehiculo.id_patio),
    ),
);

const itemsPendientesRecepcion = computed(() =>
    new Set(
        (props.vehiculo?.custodia_actual || [])
            .filter((c) => c.estado === 'pendiente_recepcion')
            .map((c) => c.item),
    ),
);

const seleccionarChecklistDisponible = () => {
    transferenciaForm.items = ITEMS_CUSTODIA
        .map((i) => i.key)
        .filter((clave) => !itemsPendientesRecepcion.value.has(clave));
};

const enviarTransferencia = () => {
    if (
        !transferenciaForm.patio_destino_id
        || !transferenciaForm.usuario_recepcion_id
        || transferenciaForm.items.length === 0
    ) {
        return;
    }

    transferenciaForm.post(route('vehiculos.transferencia.patio', props.vehiculo.id_vehiculo), {
        preserveScroll: true,
        onSuccess: () => {
            transferenciaForm.reset();
            mostrarFormulario.value = false;
        },
    });
};
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-indigo-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-indigo-100 bg-indigo-50/70 px-4 py-3">
            <div>
                <h4 class="text-sm font-bold uppercase tracking-wide text-indigo-950">
                    Transferencia de Patio con Solicitud de Recepción
                </h4>
                <p class="text-xs text-indigo-800">
                    Patio actual: <strong>{{ vehiculo.patio?.nombre || 'Sin asignar' }}</strong>.
                    Envía el vehículo junto con el checklist de documentos y llaves al usuario receptor del patio destino.
                </p>
            </div>
            <button
                type="button"
                class="rounded-md bg-gray-900 px-3.5 py-1.5 text-xs font-bold text-yellow-400 shadow-xs transition hover:bg-gray-800"
                @click="mostrarFormulario = !mostrarFormulario"
            >
                {{ mostrarFormulario ? 'Ocultar formulario' : '⇄ Transferir de Patio' }}
            </button>
        </div>

        <form
            v-if="mostrarFormulario"
            class="space-y-4 bg-gray-50 p-4"
            @submit.prevent="enviarTransferencia"
        >
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label for="transferencia-patio-destino" class="block text-xs font-semibold text-gray-700">
                        Patio de destino *
                    </label>
                    <select
                        id="transferencia-patio-destino"
                        v-model="transferenciaForm.patio_destino_id"
                        required
                        class="mt-1 block w-full rounded-md border-gray-300 text-xs shadow-sm focus:border-yellow-500 focus:ring-yellow-500"
                    >
                        <option value="" disabled>Seleccione patio de destino...</option>
                        <option
                            v-for="patio in patiosDestinoDisponibles"
                            :key="patio.id_patio"
                            :value="patio.id_patio"
                        >
                            {{ patio.nombre }}
                        </option>
                    </select>
                    <p
                        v-if="transferenciaForm.errors.patio_destino_id"
                        role="alert"
                        class="mt-1 text-xs font-medium text-red-600"
                    >
                        {{ transferenciaForm.errors.patio_destino_id }}
                    </p>
                </div>

                <div>
                    <label for="transferencia-usuario-receptor" class="block text-xs font-semibold text-gray-700">
                        Usuario receptor *
                    </label>
                    <select
                        id="transferencia-usuario-receptor"
                        v-model="transferenciaForm.usuario_recepcion_id"
                        required
                        class="mt-1 block w-full rounded-md border-gray-300 text-xs shadow-sm focus:border-yellow-500 focus:ring-yellow-500"
                    >
                        <option value="" disabled>Seleccione usuario receptor...</option>
                        <option
                            v-for="usuario in usuarios"
                            :key="usuario.id"
                            :value="usuario.id"
                        >
                            {{ usuario.name }}
                        </option>
                    </select>
                    <p
                        v-if="transferenciaForm.errors.usuario_recepcion_id"
                        role="alert"
                        class="mt-1 text-xs font-medium text-red-600"
                    >
                        {{ transferenciaForm.errors.usuario_recepcion_id }}
                    </p>
                </div>
            </div>

            <!-- Reutilización del Checklist de Entrega-Recepción -->
            <div class="space-y-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs font-bold uppercase tracking-wide text-gray-800">
                        Checklist físico a entregar *
                    </p>
                    <button
                        type="button"
                        class="text-xs font-semibold text-indigo-700 hover:text-indigo-900"
                        @click="seleccionarChecklistDisponible"
                    >
                        Seleccionar todo disponible
                    </button>
                </div>

                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <label
                        v-for="item in ITEMS_CUSTODIA"
                        :key="`transf-${item.key}`"
                        class="flex items-center gap-2 rounded border px-3 py-2 text-xs"
                        :class="itemsPendientesRecepcion.has(item.key)
                            ? 'cursor-not-allowed border-amber-200 bg-amber-50/60 text-amber-800 opacity-70'
                            : 'cursor-pointer border-gray-200 bg-white text-gray-800 hover:bg-yellow-50'"
                    >
                        <input
                            v-model="transferenciaForm.items"
                            type="checkbox"
                            :value="item.key"
                            :disabled="itemsPendientesRecepcion.has(item.key)"
                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:cursor-not-allowed"
                        />
                        <span>
                            {{ item.label }}
                            <span
                                v-if="itemsPendientesRecepcion.has(item.key)"
                                class="block text-[10px] font-semibold text-amber-700"
                            >
                                (Pendiente de recepción)
                            </span>
                        </span>
                    </label>
                </div>

                <p
                    v-if="transferenciaForm.errors.items"
                    role="alert"
                    class="text-xs font-medium text-red-600"
                >
                    {{ transferenciaForm.errors.items }}
                </p>
            </div>

            <div>
                <label for="transferencia-observacion" class="block text-xs font-semibold text-gray-700">
                    Observaciones de la transferencia (opcional)
                </label>
                <input
                    id="transferencia-observacion"
                    v-model="transferenciaForm.observacion"
                    type="text"
                    maxlength="500"
                    placeholder="Ej. Traslado con 2 llaves y carpeta completa..."
                    class="mt-1 block w-full rounded-md border-gray-300 text-xs shadow-sm focus:border-yellow-500 focus:ring-yellow-500"
                />
            </div>

            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded-md border border-gray-300 bg-white px-4 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-100"
                    @click="mostrarFormulario = false"
                >
                    Cancelar
                </button>
                <button
                    type="submit"
                    :disabled="transferenciaForm.processing
                        || !transferenciaForm.patio_destino_id
                        || !transferenciaForm.usuario_recepcion_id
                        || transferenciaForm.items.length === 0"
                    class="rounded-md bg-indigo-700 px-4 py-1.5 text-xs font-bold text-white shadow-xs hover:bg-indigo-800 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ transferenciaForm.processing ? 'Enviando transferencia...' : 'Enviar solicitud de transferencia' }}
                </button>
            </div>
        </form>
    </section>
</template>
