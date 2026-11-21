<script setup>
import { formatearFecha, formatearMoneda } from '@/Composables/useInventarioVehiculos';
import { useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

const props = defineProps({
    vehiculo: {
        type: Object,
        required: true,
    },
});

const archivosMultas = reactive({});
const pagoMultaForm = useForm({
    comprobante: null,
});
const multaProcesandoId = ref(null);

const seleccionarComprobanteMulta = (idMulta, event) => {
    archivosMultas[idMulta] = event.target.files[0] || null;
};

const saldarMulta = (idMulta) => {
    const archivo = archivosMultas[idMulta];
    if (!archivo) {
        return;
    }

    multaProcesandoId.value = idMulta;
    pagoMultaForm.comprobante = archivo;
    pagoMultaForm.post(route('vehiculos.multas.pagar', [props.vehiculo.id_vehiculo, idMulta]), {
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
</script>

<template>
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
                v-if="vehiculo.multas_pendientes_count > 0"
                class="rounded-full bg-red-100 px-3 py-1 text-xs font-bold text-red-800"
            >
                Pendiente: {{ formatearMoneda(vehiculo.multas_pendientes_total) }}
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
            v-if="!vehiculo.multas_detalle || vehiculo.multas_detalle.length === 0"
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
                    <tr v-for="multa in vehiculo.multas_detalle" :key="multa.id_multa">
                        <td class="px-4 py-3">
                            <span class="font-bold text-gray-900">{{ multa.fuente }}</span>
                            <p class="mt-0.5 text-gray-600">{{ multa.concepto }}</p>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-600">
                            {{ multa.fecha_infraccion || 'N/D' }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right font-mono font-bold text-gray-900">
                            <span>{{ formatearMoneda(multa.valor) }}</span>
                            <span
                                v-if="Number(multa.valor || 0) <= 0"
                                class="mt-1 block rounded bg-amber-100 px-1.5 py-0.5 font-sans text-[10px] font-bold text-amber-900"
                            >
                                Revisión manual
                            </span>
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
                                    @click="saldarMulta(multa.id_multa)"
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
</template>
