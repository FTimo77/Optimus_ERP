<script setup>
defineProps({
    validacion: {
        type: Object,
        required: true,
    },
    estadoVisual: {
        type: String,
        required: true,
    },
    cuvUrlActual: {
        type: String,
        default: null,
    },
    cedulaUrlActual: {
        type: String,
        default: null,
    },
    contratoUrlActual: {
        type: String,
        default: null,
    },
});

const emit = defineEmits(['abrir-constancia']);
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-200 bg-gray-50 px-5 py-3">
            <h3 class="text-sm font-semibold text-gray-900">Documentación</h3>
            <p class="mt-0.5 text-xs text-gray-600">
                Al aprobar la validación documental, el expediente pasa a
                <strong>Pendiente subir comprobante de pago</strong> y permanece en la tabla temporal hasta cargar el comprobante.
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-5 py-3">Documento</th>
                        <th class="px-5 py-3">Estado</th>
                        <th class="px-5 py-3">Acción</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    <tr>
                        <td class="px-5 py-3 font-medium text-gray-900">Matrícula vehicular</td>
                        <td class="px-5 py-3">
                            <span
                                v-if="validacion.documento_url"
                                class="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-semibold text-green-800"
                            >
                                Cargada
                            </span>
                            <span
                                v-else
                                class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800"
                            >
                                Pendiente de escaneo
                            </span>
                        </td>
                        <td class="px-5 py-3">
                            <a
                                v-if="validacion.documento_url"
                                :href="validacion.documento_url"
                                class="text-xs font-semibold text-indigo-700 hover:text-indigo-900"
                            >
                                Descargar matrícula
                            </a>
                            <span v-else class="text-xs text-gray-500">Escanea la matrícula arriba</span>
                        </td>
                    </tr>
                    <tr>
                        <td class="px-5 py-3 font-medium text-gray-900">Certificado Único Vehicular (CUV)</td>
                        <td class="px-5 py-3">
                            <span
                                v-if="cuvUrlActual"
                                class="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-semibold text-green-800"
                            >
                                Cargado
                            </span>
                            <span
                                v-else
                                class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-600"
                            >
                                No adjunto
                            </span>
                        </td>
                        <td class="px-5 py-3">
                            <a
                                v-if="cuvUrlActual"
                                :href="cuvUrlActual"
                                class="text-xs font-semibold text-indigo-700 hover:text-indigo-900"
                            >
                                Descargar CUV
                            </a>
                            <span v-else class="text-xs text-gray-500">Sin archivo</span>
                        </td>
                    </tr>
                    <tr>
                        <td class="px-5 py-3 font-medium text-gray-900">Cédula del cliente (vendedor)</td>
                        <td class="px-5 py-3">
                            <span
                                v-if="cedulaUrlActual"
                                class="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-semibold text-green-800"
                            >
                                Cargada
                            </span>
                            <span
                                v-else-if="validacion.estado_revision === 'aprobada'"
                                class="rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-semibold text-indigo-800"
                            >
                                Pendiente
                            </span>
                            <span
                                v-else
                                class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-600"
                            >
                                Bloqueado hasta subir comprobante
                            </span>
                        </td>
                        <td class="px-5 py-3">
                            <a
                                v-if="cedulaUrlActual"
                                :href="cedulaUrlActual"
                                class="text-xs font-semibold text-indigo-700 hover:text-indigo-900"
                            >
                                Ver / Descargar cédula
                            </a>
                            <span v-else class="text-xs text-gray-500">
                                {{ validacion.estado_revision === 'aprobada' ? 'Se carga en Generar Constancia' : 'No disponible' }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td class="px-5 py-3 font-medium text-gray-900">Constancia / Contrato de compra-venta (.docx)</td>
                        <td class="px-5 py-3">
                            <span
                                v-if="contratoUrlActual"
                                class="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-semibold text-green-800"
                            >
                                Generada · Aprobado
                            </span>
                            <span
                                v-else-if="estadoVisual === 'pendiente_constancia'"
                                class="rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-semibold text-indigo-900"
                            >
                                Pendiente generar constancia
                            </span>
                            <span
                                v-else
                                class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-600"
                            >
                                Bloqueado hasta subir comprobante
                            </span>
                        </td>
                        <td class="px-5 py-3">
                            <div v-if="estadoVisual === 'pendiente_constancia'" class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    class="rounded-md bg-yellow-500 px-4 py-1.5 text-xs font-bold text-gray-900 shadow-sm hover:bg-yellow-400"
                                    @click="emit('abrir-constancia')"
                                >
                                    Generar Constancia
                                </button>
                            </div>
                            <div v-else-if="contratoUrlActual" class="flex flex-wrap items-center gap-3">
                                <a
                                    :href="contratoUrlActual"
                                    class="text-xs font-semibold text-blue-700 hover:text-blue-900"
                                >
                                    Descargar contrato (.docx)
                                </a>
                                <button
                                    type="button"
                                    class="text-xs font-medium text-gray-600 underline hover:text-gray-900"
                                    @click="emit('abrir-constancia')"
                                >
                                    Actualizar / Regenerar
                                </button>
                            </div>
                            <span v-else class="text-xs text-gray-500">Se habilitará al registrar el pago</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
