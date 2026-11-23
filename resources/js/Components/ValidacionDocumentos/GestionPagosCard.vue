<script setup>
import { formatearMoneda } from '@/Composables/useValidacionDocumentoHelpers';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    validacion: {
        type: Object,
        required: true,
    },
});

const hoyIso = new Date().toISOString().slice(0, 10);

const pagoForm = useForm({
    forma_pago: 'contado',
    fecha_vencimiento: '',
    observaciones: '',
    fecha_pago: hoyIso,
    documento_comprobante: null,
    cuotas: [
        { comprobante: null, monto: '', fecha_pago: hoyIso },
    ],
});

const abonoForm = useForm({
    comprobante: null,
    monto: '',
    fecha_pago: hoyIso,
});
const abonoInputRef = ref(null);

const costoAdquisicion = computed(() =>
    Number(props.validacion.costo_adquisicion ?? props.validacion.datos_ingreso?.costo_adquisicion ?? 0),
);
const sumaCuotas = computed(() =>
    pagoForm.cuotas.reduce((acc, item) => acc + (Number(item.monto) || 0), 0),
);
const excedeCostoAdquisicion = computed(() =>
    costoAdquisicion.value > 0 && sumaCuotas.value > costoAdquisicion.value + 0.009,
);
const puedeAgregarCuota = computed(() =>
    costoAdquisicion.value <= 0 || sumaCuotas.value < costoAdquisicion.value - 0.009,
);
const saldoRestanteCuotas = computed(() =>
    Math.max(0, costoAdquisicion.value - sumaCuotas.value),
);

const totalPagadoRegistrado = computed(() =>
    (props.validacion.pagos || []).reduce((acc, p) => acc + (Number(p.monto) || 0), 0),
);
const saldoPendienteRegistrado = computed(() =>
    Math.max(0, Number((costoAdquisicion.value - totalPagadoRegistrado.value).toFixed(2))),
);
const abonoExcedeSaldo = computed(() =>
    Number(abonoForm.monto || 0) > saldoPendienteRegistrado.value + 0.009,
);

const agregarCuota = () => {
    if (!puedeAgregarCuota.value) {
        return;
    }
    pagoForm.cuotas.push({ comprobante: null, monto: '', fecha_pago: hoyIso });
};

const eliminarCuota = (index) => {
    if (pagoForm.cuotas.length <= 1) {
        return;
    }
    pagoForm.cuotas.splice(index, 1);
};

const puedeEnviarFormaPago = computed(() => {
    if (pagoForm.processing) {
        return false;
    }
    if (pagoForm.forma_pago === 'credito') {
        return Boolean(pagoForm.fecha_vencimiento);
    }
    if (pagoForm.forma_pago === 'contado') {
        return Boolean(pagoForm.documento_comprobante);
    }
    if (pagoForm.forma_pago === 'cuotas') {
        if (excedeCostoAdquisicion.value || pagoForm.cuotas.length === 0) {
            return false;
        }
        return pagoForm.cuotas.every((c) => Boolean(c.comprobante) && Number(c.monto) > 0);
    }
    return false;
});

const enviarFormaPago = () => {
    if (!puedeEnviarFormaPago.value) {
        return;
    }

    pagoForm
        .transform((data) => {
            if (data.forma_pago === 'credito') {
                return {
                    forma_pago: 'credito',
                    fecha_vencimiento: data.fecha_vencimiento,
                    observaciones: data.observaciones,
                };
            }
            if (data.forma_pago === 'contado') {
                return {
                    forma_pago: 'contado',
                    documento_comprobante: data.documento_comprobante,
                    fecha_pago: data.fecha_pago,
                };
            }
            return {
                forma_pago: 'cuotas',
                cuotas: data.cuotas,
            };
        })
        .post(route('validacion-documentos.pago.store', props.validacion.id), {
            preserveScroll: true,
            forceFormData: true,
            onFinish: () => pagoForm.transform((data) => data),
        });
};

const registrarAbonoAdicional = () => {
    if (!abonoForm.comprobante || Number(abonoForm.monto) <= 0 || abonoExcedeSaldo.value) {
        return;
    }
    abonoForm.post(route('validacion-documentos.pago.abono', props.validacion.id), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            abonoForm.reset();
            abonoForm.fecha_pago = hoyIso;
            if (abonoInputRef.value) {
                abonoInputRef.value.value = '';
            }
        },
    });
};
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-col justify-between gap-3 border-b border-gray-200 bg-gray-900 px-5 py-4 text-white sm:flex-row sm:items-center">
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wide text-yellow-400">
                    Gestión de Pagos e Ingreso a Inventario
                </h3>
                <p class="mt-0.5 text-xs text-gray-300">
                    Registra la forma de pago (contado, cuotas o crédito) para incorporar el vehículo al inventario y asentar su movimiento en el Kardex.
                </p>
            </div>
            <div class="shrink-0 rounded-md border border-yellow-500/40 bg-gray-800 px-4 py-2 text-right">
                <span class="block text-[10px] font-semibold uppercase tracking-wider text-gray-400">
                    Costo de Adquisición (Referencia)
                </span>
                <span class="font-mono text-lg font-extrabold text-yellow-400">
                    {{ formatearMoneda(costoAdquisicion) }}
                </span>
            </div>
        </div>

        <div v-if="validacion.estado_revision === 'pendiente'" class="p-5 text-sm text-gray-600">
            La gestión de pagos se habilitará automáticamente una vez que apruebes la validación documental.
        </div>

        <div v-else-if="validacion.estado_revision === 'rechazada'" class="p-5 text-sm text-gray-600">
            Este expediente fue rechazado. Debe reenviarse y aprobarse antes de registrar una forma de pago.
        </div>

        <!-- FORMULARIO ACTIVO DE FORMA DE PAGO -->
        <form
            v-else-if="validacion.estado_revision === 'pendiente_comprobante'"
            class="space-y-5 p-5"
            @submit.prevent="enviarFormaPago"
        >
            <div
                v-if="Object.keys(pagoForm.errors).length"
                role="alert"
                class="rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-700"
            >
                <p v-for="(err, key) in pagoForm.errors" :key="key">{{ err }}</p>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="forma-pago-select" class="block text-sm font-semibold text-gray-800">
                        Forma de Pago *
                    </label>
                    <select
                        id="forma-pago-select"
                        v-model="pagoForm.forma_pago"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="contado">Contado (pago total)</option>
                        <option value="cuotas">Cuotas / Abonos múltiples</option>
                        <option value="credito">Crédito</option>
                    </select>
                </div>
            </div>

            <!-- OPCIÓN 1: CRÉDITO -->
            <div
                v-if="pagoForm.forma_pago === 'credito'"
                class="space-y-4 rounded-lg border border-indigo-200 bg-indigo-50/50 p-4"
            >
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="credito-fecha-vencimiento" class="block text-xs font-semibold text-gray-800">
                            Fecha de vencimiento del crédito *
                        </label>
                        <input
                            id="credito-fecha-vencimiento"
                            v-model="pagoForm.fecha_vencimiento"
                            type="date"
                            required
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-800">
                            Monto total del crédito
                        </label>
                        <input
                            type="text"
                            :value="formatearMoneda(costoAdquisicion)"
                            disabled
                            class="mt-1 block w-full rounded-md border-gray-200 bg-gray-100 font-mono text-sm font-bold text-gray-700"
                        />
                    </div>
                </div>
                <div>
                    <label for="credito-observaciones" class="block text-xs font-semibold text-gray-800">
                        Observaciones del crédito (opcional)
                    </label>
                    <textarea
                        id="credito-observaciones"
                        v-model="pagoForm.observaciones"
                        rows="2"
                        maxlength="1000"
                        placeholder="Condiciones, entidad o notas del crédito..."
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                </div>
            </div>

            <!-- OPCIÓN 2: CUOTAS / ABONOS MÚLTIPLES -->
            <div
                v-else-if="pagoForm.forma_pago === 'cuotas'"
                class="space-y-4 rounded-lg border border-amber-200 bg-amber-50/40 p-4"
            >
                <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                    <div class="flex flex-wrap items-center gap-4">
                        <span class="font-semibold text-gray-700">
                            Acumulado:
                            <strong
                                class="font-mono text-sm"
                                :class="excedeCostoAdquisicion ? 'text-red-700' : 'text-emerald-700'"
                            >
                                {{ formatearMoneda(sumaCuotas) }}
                            </strong>
                        </span>
                        <span class="font-semibold text-gray-700">
                            Saldo restante:
                            <strong class="font-mono text-sm text-gray-900">
                                {{ formatearMoneda(saldoRestanteCuotas) }}
                            </strong>
                        </span>
                    </div>
                    <button
                        type="button"
                        :disabled="!puedeAgregarCuota"
                        class="rounded-md bg-gray-900 px-3 py-1.5 text-xs font-bold text-yellow-400 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-40"
                        @click="agregarCuota"
                    >
                        + Agregar cuota/comprobante
                    </button>
                </div>

                <p
                    v-if="excedeCostoAdquisicion"
                    role="alert"
                    class="rounded border border-red-300 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700"
                >
                    La suma de las cuotas ({{ formatearMoneda(sumaCuotas) }}) excede el Costo de Adquisición ({{ formatearMoneda(costoAdquisicion) }}). Ajusta los montos para continuar.
                </p>

                <div class="space-y-3">
                    <div
                        v-for="(cuota, idx) in pagoForm.cuotas"
                        :key="idx"
                        class="grid grid-cols-1 items-end gap-3 rounded-md border border-gray-200 bg-white p-3 sm:grid-cols-12"
                    >
                        <div class="sm:col-span-5">
                            <label :for="`cuota-archivo-${idx}`" class="block text-xs font-medium text-gray-700">
                                Comprobante #{{ idx + 1 }} *
                            </label>
                            <input
                                :id="`cuota-archivo-${idx}`"
                                type="file"
                                accept="image/jpeg,image/png,image/webp,.pdf"
                                required
                                class="mt-1 block w-full text-xs text-gray-600 file:mr-2 file:rounded file:border-0 file:bg-emerald-700 file:px-2.5 file:py-1.5 file:text-white hover:file:bg-emerald-600"
                                @change="cuota.comprobante = $event.target.files[0] || null"
                            />
                        </div>
                        <div class="sm:col-span-3">
                            <label :for="`cuota-monto-${idx}`" class="block text-xs font-medium text-gray-700">
                                Valor del abono ($) *
                            </label>
                            <input
                                :id="`cuota-monto-${idx}`"
                                v-model="cuota.monto"
                                type="number"
                                step="0.01"
                                min="0.01"
                                required
                                placeholder="0.00"
                                class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                        </div>
                        <div class="sm:col-span-3">
                            <label :for="`cuota-fecha-${idx}`" class="block text-xs font-medium text-gray-700">
                                Fecha de pago
                            </label>
                            <input
                                :id="`cuota-fecha-${idx}`"
                                v-model="cuota.fecha_pago"
                                type="date"
                                class="mt-1 block w-full rounded-md border-gray-300 text-xs shadow-sm"
                            />
                        </div>
                        <div class="flex justify-end sm:col-span-1">
                            <button
                                v-if="pagoForm.cuotas.length > 1"
                                type="button"
                                class="rounded bg-red-100 px-2.5 py-1.5 text-xs font-bold text-red-700 hover:bg-red-200"
                                title="Quitar cuota"
                                @click="eliminarCuota(idx)"
                            >
                                ✕
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- OPCIÓN 3: CONTADO -->
            <div
                v-else
                class="grid grid-cols-1 gap-4 rounded-lg border border-emerald-200 bg-emerald-50/50 p-4 sm:grid-cols-2"
            >
                <div>
                    <label for="contado-comprobante" class="block text-xs font-semibold text-gray-800">
                        Comprobante de pago por el valor total ({{ formatearMoneda(costoAdquisicion) }}) *
                    </label>
                    <input
                        id="contado-comprobante"
                        type="file"
                        accept="image/jpeg,image/png,image/webp,.pdf"
                        required
                        class="mt-1 block w-full text-xs text-gray-600 file:mr-2 file:rounded-md file:border-0 file:bg-emerald-700 file:px-3 py-1.5 file:text-white hover:file:bg-emerald-600"
                        @change="pagoForm.documento_comprobante = $event.target.files[0] || null"
                    />
                </div>
                <div>
                    <label for="contado-fecha-pago" class="block text-xs font-semibold text-gray-800">
                        Fecha de pago
                    </label>
                    <input
                        id="contado-fecha-pago"
                        v-model="pagoForm.fecha_pago"
                        type="date"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                    />
                </div>
            </div>

            <div class="flex justify-end">
                <button
                    type="submit"
                    :disabled="!puedeEnviarFormaPago"
                    class="rounded-md bg-emerald-700 px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ pagoForm.processing ? 'Registrando e ingresando...' : 'Registrar forma de pago e ingresar al inventario' }}
                </button>
            </div>
        </form>

        <!-- RESUMEN DE PAGO / CRÉDITO YA REGISTRADO -->
        <div v-else class="space-y-4 p-5 text-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold uppercase text-emerald-800">
                        Forma de pago: {{ validacion.forma_pago || 'Contado' }}
                    </span>
                    <span class="text-xs text-gray-600">
                        Total pagado: <strong class="font-mono text-gray-900">{{ formatearMoneda(totalPagadoRegistrado) }}</strong>
                        / {{ formatearMoneda(costoAdquisicion) }}
                    </span>
                </div>
                <span
                    v-if="saldoPendienteRegistrado <= 0.009"
                    class="rounded-full bg-green-100 px-3 py-1 text-xs font-bold text-green-800"
                >
                    ✅ Valor total cubierto (100%)
                </span>
                <span
                    v-else
                    class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-900"
                >
                    Saldo pendiente: {{ formatearMoneda(saldoPendienteRegistrado) }}
                </span>
            </div>

            <div v-if="validacion.credito" class="rounded-lg border border-indigo-200 bg-indigo-50 p-4 text-xs text-indigo-950">
                <p class="font-bold uppercase">Crédito registrado</p>
                <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <span><strong>Monto total:</strong> {{ formatearMoneda(validacion.credito.monto_total) }}</span>
                    <span><strong>Vencimiento:</strong> {{ validacion.credito.fecha_vencimiento }}</span>
                    <span><strong>Estado:</strong> {{ validacion.credito.estado }}</span>
                </div>
                <p v-if="validacion.credito.observaciones" class="mt-2 italic text-indigo-800">
                    "{{ validacion.credito.observaciones }}"
                </p>
            </div>

            <div v-if="validacion.pagos && validacion.pagos.length" class="overflow-x-auto rounded-lg border border-gray-200">
                <table class="min-w-full divide-y divide-gray-200 text-xs">
                    <thead class="bg-gray-50 text-left font-semibold uppercase tracking-wide text-gray-600">
                        <tr>
                            <th class="px-4 py-2.5">Tipo</th>
                            <th class="px-4 py-2.5">Fecha</th>
                            <th class="px-4 py-2.5 text-right">Monto</th>
                            <th class="px-4 py-2.5">Registrado por</th>
                            <th class="px-4 py-2.5 text-right">Comprobante</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-for="pago in validacion.pagos" :key="pago.id_pago">
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

            <!-- AÑADIR OTRO COMPROBANTE / CUOTA MIENTRAS EXISTA SALDO PENDIENTE -->
            <form
                v-if="saldoPendienteRegistrado > 0.009"
                class="space-y-3 rounded-lg border border-amber-200 bg-amber-50/50 p-4"
                @submit.prevent="registrarAbonoAdicional"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs font-bold uppercase tracking-wide text-amber-900">
                        + Añadir otro comprobante / cuota (pendiente {{ formatearMoneda(saldoPendienteRegistrado) }})
                    </p>
                </div>
                <div
                    v-if="abonoForm.errors.monto || abonoForm.errors.comprobante"
                    role="alert"
                    class="rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700"
                >
                    <p v-if="abonoForm.errors.monto">{{ abonoForm.errors.monto }}</p>
                    <p v-if="abonoForm.errors.comprobante">{{ abonoForm.errors.comprobante }}</p>
                </div>
                <p v-if="abonoExcedeSaldo" role="alert" class="text-xs font-semibold text-red-700">
                    El valor ingresado excede el saldo pendiente ({{ formatearMoneda(saldoPendienteRegistrado) }}).
                </p>
                <div class="grid grid-cols-1 items-end gap-3 sm:grid-cols-12">
                    <div class="sm:col-span-5">
                        <label class="block text-xs font-medium text-gray-700">Nuevo comprobante *</label>
                        <input
                            ref="abonoInputRef"
                            type="file"
                            accept="image/jpeg,image/png,image/webp,.pdf"
                            required
                            class="mt-1 block w-full text-xs text-gray-600 file:mr-2 file:rounded file:border-0 file:bg-emerald-700 file:px-2.5 file:py-1.5 file:text-white hover:file:bg-emerald-600"
                            @change="abonoForm.comprobante = $event.target.files[0] || null"
                        />
                    </div>
                    <div class="sm:col-span-3">
                        <label class="block text-xs font-medium text-gray-700">Valor del abono ($) *</label>
                        <input
                            v-model="abonoForm.monto"
                            type="number"
                            step="0.01"
                            min="0.01"
                            :max="saldoPendienteRegistrado"
                            required
                            placeholder="0.00"
                            class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm"
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-gray-700">Fecha</label>
                        <input
                            v-model="abonoForm.fecha_pago"
                            type="date"
                            class="mt-1 block w-full rounded-md border-gray-300 text-xs shadow-sm"
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <button
                            type="submit"
                            :disabled="abonoForm.processing || !abonoForm.comprobante || Number(abonoForm.monto) <= 0 || abonoExcedeSaldo"
                            class="w-full rounded-md bg-emerald-700 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ abonoForm.processing ? 'Guardando...' : 'Agregar cuota' }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </section>
</template>
