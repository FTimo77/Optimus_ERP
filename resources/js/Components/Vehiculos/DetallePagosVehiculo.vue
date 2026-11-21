<script setup>
import { formatearMoneda } from '@/Composables/useInventarioVehiculos';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    vehiculo: {
        type: Object,
        required: true,
    },
});

const hoyIso = new Date().toISOString().slice(0, 10);
const abonoPagoForm = useForm({
    comprobante: null,
    monto: '',
    fecha_pago: hoyIso,
});
const abonoPagoInput = ref(null);

const registrarAbonoVehiculo = () => {
    const saldo = Number(props.vehiculo.saldo_pendiente_pago || 0);
    if (!abonoPagoForm.comprobante || Number(abonoPagoForm.monto) <= 0 || Number(abonoPagoForm.monto) > saldo + 0.009) {
        return;
    }

    abonoPagoForm.post(route('vehiculos.pagos.abono', props.vehiculo.id_vehiculo), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            abonoPagoForm.reset();
            abonoPagoForm.fecha_pago = hoyIso;
            if (abonoPagoInput.value) {
                abonoPagoInput.value.value = '';
            }
        },
    });
};
</script>

<template>
    <details class="overflow-hidden rounded-lg border border-gray-200 bg-white">
        <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 bg-gray-50 px-4 py-3 text-sm font-bold uppercase tracking-wide text-gray-800 hover:bg-gray-100">
            <span>
                D. Pagos y Comprobantes ({{ (vehiculo.pagos_detalle || []).length }})
            </span>
            <div class="flex flex-wrap items-center gap-2 text-xs font-semibold normal-case">
                <span class="font-mono text-gray-700">
                    Pagado: {{ formatearMoneda(vehiculo.total_pagado) }} / {{ formatearMoneda(vehiculo.costo_adquisicion) }}
                </span>
                <span
                    v-if="Number(vehiculo.saldo_pendiente_pago || 0) <= 0.009"
                    class="rounded-full bg-green-100 px-2.5 py-0.5 text-green-800"
                >
                    ✅ Cubierto 100%
                </span>
                <span
                    v-else
                    class="rounded-full bg-amber-100 px-2.5 py-0.5 text-amber-900"
                >
                    Saldo pendiente: {{ formatearMoneda(vehiculo.saldo_pendiente_pago) }}
                </span>
            </div>
        </summary>

        <div class="space-y-4 border-t border-gray-200 p-4">
            <div
                v-if="vehiculo.credito_detalle"
                class="rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-xs text-indigo-950"
            >
                <p class="font-bold uppercase">Crédito asociado</p>
                <div class="mt-1 grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <span><strong>Monto total:</strong> {{ formatearMoneda(vehiculo.credito_detalle.monto_total) }}</span>
                    <span><strong>Vencimiento:</strong> {{ vehiculo.credito_detalle.fecha_vencimiento }}</span>
                    <span><strong>Estado:</strong> {{ vehiculo.credito_detalle.estado }}</span>
                </div>
                <p v-if="vehiculo.credito_detalle.observaciones" class="mt-1 italic text-indigo-800">
                    "{{ vehiculo.credito_detalle.observaciones }}"
                </p>
            </div>

            <div
                v-if="vehiculo.pagos_detalle && vehiculo.pagos_detalle.length"
                class="overflow-x-auto rounded border border-gray-200"
            >
                <table class="min-w-full divide-y divide-gray-200 text-xs">
                    <thead class="bg-gray-100 text-left font-semibold uppercase tracking-wide text-gray-600">
                        <tr>
                            <th class="px-4 py-2.5">Tipo</th>
                            <th class="px-4 py-2.5">Fecha</th>
                            <th class="px-4 py-2.5 text-right">Monto</th>
                            <th class="px-4 py-2.5">Registrado por</th>
                            <th class="px-4 py-2.5 text-right">Comprobante</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-for="pago in vehiculo.pagos_detalle" :key="pago.id_pago">
                            <td class="px-4 py-2.5 font-semibold uppercase text-gray-800">{{ pago.tipo_pago }}</td>
                            <td class="px-4 py-2.5 text-gray-600">{{ pago.fecha_pago }}</td>
                            <td class="px-4 py-2.5 text-right font-mono font-bold text-gray-900">{{ formatearMoneda(pago.monto) }}</td>
                            <td class="px-4 py-2.5 text-gray-600">{{ pago.registrado_por || 'N/D' }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <a
                                    :href="pago.comprobante_url"
                                    class="font-semibold text-emerald-700 hover:text-emerald-900"
                                >
                                    Descargar comprobante
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="text-xs text-gray-500">
                No hay comprobantes de pago registrados todavía.
            </p>

            <form
                v-if="Number(vehiculo.saldo_pendiente_pago || 0) > 0.009"
                class="space-y-3 rounded-lg border border-amber-200 bg-amber-50/50 p-3"
                @submit.prevent="registrarAbonoVehiculo"
            >
                <p class="text-xs font-bold uppercase tracking-wide text-amber-900">
                    + Añadir otro comprobante / cuota (saldo: {{ formatearMoneda(vehiculo.saldo_pendiente_pago) }})
                </p>
                <div
                    v-if="abonoPagoForm.errors.monto || abonoPagoForm.errors.comprobante"
                    role="alert"
                    class="rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700"
                >
                    <p v-if="abonoPagoForm.errors.monto">{{ abonoPagoForm.errors.monto }}</p>
                    <p v-if="abonoPagoForm.errors.comprobante">{{ abonoPagoForm.errors.comprobante }}</p>
                </div>
                <p
                    v-if="Number(abonoPagoForm.monto || 0) > Number(vehiculo.saldo_pendiente_pago || 0) + 0.009"
                    role="alert"
                    class="text-xs font-semibold text-red-700"
                >
                    El valor ingresado excede el saldo pendiente ({{ formatearMoneda(vehiculo.saldo_pendiente_pago) }}).
                </p>
                <div class="grid grid-cols-1 items-end gap-3 sm:grid-cols-12">
                    <div class="sm:col-span-5">
                        <label class="block text-xs font-medium text-gray-700">Comprobante *</label>
                        <input
                            ref="abonoPagoInput"
                            type="file"
                            accept="image/jpeg,image/png,image/webp,.pdf"
                            required
                            class="mt-1 block w-full text-xs text-gray-600 file:mr-2 file:rounded file:border-0 file:bg-emerald-700 file:px-2.5 file:py-1 file:text-white hover:file:bg-emerald-600"
                            @change="abonoPagoForm.comprobante = $event.target.files[0] || null"
                        />
                    </div>
                    <div class="sm:col-span-3">
                        <label class="block text-xs font-medium text-gray-700">Valor ($) *</label>
                        <input
                            v-model="abonoPagoForm.monto"
                            type="number"
                            step="0.01"
                            min="0.01"
                            :max="vehiculo.saldo_pendiente_pago"
                            required
                            placeholder="0.00"
                            class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs shadow-sm"
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-gray-700">Fecha</label>
                        <input
                            v-model="abonoPagoForm.fecha_pago"
                            type="date"
                            class="mt-1 block w-full rounded-md border-gray-300 text-xs shadow-sm"
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <button
                            type="submit"
                            :disabled="abonoPagoForm.processing || !abonoPagoForm.comprobante || Number(abonoPagoForm.monto) <= 0 || Number(abonoPagoForm.monto) > Number(vehiculo.saldo_pendiente_pago || 0) + 0.009"
                            class="w-full rounded-md bg-emerald-700 px-3 py-1.5 text-xs font-bold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ abonoPagoForm.processing ? 'Guardando...' : 'Agregar' }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </details>
</template>
