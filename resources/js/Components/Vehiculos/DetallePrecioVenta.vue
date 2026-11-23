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

const tienePrecioFijado = computed(() =>
    props.vehiculo.precio_venta !== null
    && props.vehiculo.precio_venta !== undefined
    && props.vehiculo.precio_venta !== '',
);

const precioForm = useForm({
    precio_venta: tienePrecioFijado.value ? String(props.vehiculo.precio_venta) : '',
});

watch(
    () => [props.vehiculo.id_vehiculo, props.vehiculo.precio_venta],
    () => {
        precioForm.precio_venta = tienePrecioFijado.value ? String(props.vehiculo.precio_venta) : '';
        precioForm.clearErrors();
    },
);

const guardarPrecioVenta = () => {
    if (!puedeEditarPrecio.value) {
        return;
    }

    precioForm.patch(route('vehiculos.precio-venta.update', props.vehiculo.id_vehiculo), {
        preserveScroll: true,
    });
};
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-yellow-300 bg-yellow-50/60 shadow-xs">
        <div class="flex flex-col justify-between gap-4 p-4 sm:flex-row sm:items-center">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-gray-600">
                    Precio de Venta al Público
                </p>
                <div class="mt-1 flex flex-wrap items-baseline gap-2">
                    <span
                        v-if="tienePrecioFijado"
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

            <form
                v-if="puedeEditarPrecio"
                class="flex flex-col gap-2 sm:flex-row sm:items-end"
                @submit.prevent="guardarPrecioVenta"
            >
                <div>
                    <label
                        :for="`precio-venta-${vehiculo.id_vehiculo}`"
                        class="block text-xs font-semibold text-gray-700"
                    >
                        {{ tienePrecioFijado ? 'Editar precio de venta ($)' : 'Fijar precio de venta ($)' }}
                    </label>
                    <input
                        :id="`precio-venta-${vehiculo.id_vehiculo}`"
                        v-model="precioForm.precio_venta"
                        type="number"
                        step="0.01"
                        min="0"
                        required
                        placeholder="0.00"
                        class="mt-1 block w-full rounded-md border-gray-300 bg-white font-mono text-sm font-semibold text-gray-900 shadow-sm focus:border-yellow-500 focus:ring-yellow-500 sm:w-44"
                    />
                </div>
                <button
                    type="submit"
                    :disabled="precioForm.processing || precioForm.precio_venta === ''"
                    class="rounded-md bg-gray-900 px-4 py-2 text-xs font-bold text-yellow-400 shadow-sm transition hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ precioForm.processing
                        ? 'Guardando...'
                        : tienePrecioFijado
                            ? 'Actualizar precio'
                            : 'Fijar precio' }}
                </button>
            </form>
        </div>

        <p
            v-if="precioForm.errors.precio_venta"
            role="alert"
            class="border-t border-red-200 bg-red-50 px-4 py-2 text-xs font-medium text-red-700"
        >
            {{ precioForm.errors.precio_venta }}
        </p>
    </section>
</template>
