<script setup>
import { formatearMoneda } from '@/Composables/useInventarioVehiculos';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    vehiculo: {
        type: Object,
        required: true,
    },
    esAdmin: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();

const puedeEditarPrecio = computed(() =>
    props.esAdmin || Boolean(page.props.auth?.roles?.includes('Administrador')),
);

const tienePrecioLista = computed(() =>
    props.vehiculo.precio_venta !== null
    && props.vehiculo.precio_venta !== undefined
    && props.vehiculo.precio_venta !== '',
);

const tienePrecioFinal = computed(() =>
    props.vehiculo.precio_final !== null
    && props.vehiculo.precio_final !== undefined
    && props.vehiculo.precio_final !== '',
);

const precioForm = useForm({
    precio_venta: tienePrecioLista.value ? String(props.vehiculo.precio_venta) : '',
    precio_final: tienePrecioFinal.value ? String(props.vehiculo.precio_final) : '',
});

watch(
    () => [props.vehiculo.id_vehiculo, props.vehiculo.precio_venta, props.vehiculo.precio_final],
    () => {
        precioForm.precio_venta = tienePrecioLista.value ? String(props.vehiculo.precio_venta) : '';
        precioForm.precio_final = tienePrecioFinal.value ? String(props.vehiculo.precio_final) : '';
        precioForm.clearErrors();
    },
);

const precioFinalExcedeLista = computed(() =>
    precioForm.precio_final !== ''
    && precioForm.precio_venta !== ''
    && Number(precioForm.precio_final) > Number(precioForm.precio_venta) + 0.009,
);

const guardarPrecios = () => {
    if (!puedeEditarPrecio.value || precioFinalExcedeLista.value) {
        return;
    }

    precioForm.patch(route('vehiculos.precio-venta.update', props.vehiculo.id_vehiculo), {
        preserveScroll: true,
    });
};
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-yellow-300 bg-yellow-50/60 shadow-xs">
        <div class="flex flex-col justify-between gap-4 p-4 md:flex-row md:items-start">
            <!-- Visualización de ambos precios (visible para todos los roles) -->
            <div class="space-y-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-600">
                        Precio de Lista
                    </p>
                    <div class="mt-0.5 flex flex-wrap items-baseline gap-2">
                        <span
                            v-if="tienePrecioLista"
                            class="font-mono text-2xl font-extrabold text-gray-900"
                        >
                            {{ formatearMoneda(vehiculo.precio_venta) }}
                        </span>
                        <span
                            v-else
                            class="text-sm font-semibold italic text-amber-800"
                        >
                            Pendiente de fijar por Administrador
                        </span>
                    </div>
                </div>

                <div class="border-t border-yellow-200/80 pt-2.5">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-600">
                        Precio Final <span class="font-normal normal-case text-gray-500">(mínimo de venta)</span>
                    </p>
                    <div class="mt-0.5 flex flex-wrap items-baseline gap-2">
                        <span
                            v-if="tienePrecioFinal"
                            class="font-mono text-xl font-extrabold text-emerald-800"
                        >
                            {{ formatearMoneda(vehiculo.precio_final) }}
                        </span>
                        <span
                            v-else
                            class="text-xs font-medium italic text-gray-500"
                        >
                            Sin precio final configurado
                        </span>
                    </div>
                </div>
            </div>

            <!-- Formulario de configuración (exclusivo para Administrador) -->
            <form
                v-if="puedeEditarPrecio"
                class="w-full space-y-2.5 rounded-lg border border-yellow-200 bg-white/80 p-3 md:w-72"
                @submit.prevent="guardarPrecios"
            >
                <div>
                    <label
                        :for="`precio-venta-${vehiculo.id_vehiculo}`"
                        class="block text-xs font-semibold text-gray-700"
                    >
                        Precio de Lista ($) *
                    </label>
                    <input
                        :id="`precio-venta-${vehiculo.id_vehiculo}`"
                        v-model="precioForm.precio_venta"
                        type="number"
                        step="0.01"
                        min="0"
                        required
                        placeholder="0.00"
                        class="mt-1 block w-full rounded-md border-gray-300 bg-white font-mono text-sm font-semibold text-gray-900 shadow-sm focus:border-yellow-500 focus:ring-yellow-500"
                    />
                </div>

                <div>
                    <label
                        :for="`precio-final-${vehiculo.id_vehiculo}`"
                        class="block text-xs font-semibold text-gray-700"
                    >
                        Precio Final / Mínimo ($)
                    </label>
                    <input
                        :id="`precio-final-${vehiculo.id_vehiculo}`"
                        v-model="precioForm.precio_final"
                        type="number"
                        step="0.01"
                        min="0"
                        :max="precioForm.precio_venta || undefined"
                        placeholder="Opcional (ej. 14500.00)"
                        class="mt-1 block w-full rounded-md border-gray-300 bg-white font-mono text-sm font-semibold text-emerald-900 shadow-sm focus:border-yellow-500 focus:ring-yellow-500"
                    />
                    <p
                        v-if="precioFinalExcedeLista"
                        role="alert"
                        class="mt-1 text-[11px] font-medium text-red-600"
                    >
                        El Precio Final no puede superar el Precio de Lista.
                    </p>
                </div>

                <button
                    type="submit"
                    :disabled="precioForm.processing || precioForm.precio_venta === '' || precioFinalExcedeLista"
                    class="w-full rounded-md bg-gray-900 px-4 py-2 text-xs font-bold text-yellow-400 shadow-sm transition hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ precioForm.processing
                        ? 'Guardando...'
                        : (tienePrecioLista || tienePrecioFinal)
                            ? 'Actualizar precios'
                            : 'Fijar precios' }}
                </button>
            </form>
        </div>

        <div
            v-if="precioForm.errors.precio_venta || precioForm.errors.precio_final"
            role="alert"
            class="border-t border-red-200 bg-red-50 px-4 py-2 text-xs font-medium text-red-700"
        >
            <p v-if="precioForm.errors.precio_venta">{{ precioForm.errors.precio_venta }}</p>
            <p v-if="precioForm.errors.precio_final">{{ precioForm.errors.precio_final }}</p>
        </div>
    </section>
</template>
