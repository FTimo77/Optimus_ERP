<script setup>
import EscanerAuditoriaMatricula from '@/Components/EscanerAuditoriaMatricula.vue';
import AuditoriaIntegralPanel from '@/Components/ValidacionDocumentos/AuditoriaIntegralPanel.vue';
import DatosExtraidosPanel from '@/Components/ValidacionDocumentos/DatosExtraidosPanel.vue';
import DecisionHumanaCard from '@/Components/ValidacionDocumentos/DecisionHumanaCard.vue';
import GenerarConstanciaModal from '@/Components/ValidacionDocumentos/GenerarConstanciaModal.vue';
import GestionPagosCard from '@/Components/ValidacionDocumentos/GestionPagosCard.vue';
import ResumenExpedienteCard from '@/Components/ValidacionDocumentos/ResumenExpedienteCard.vue';
import SolicitudIngresoCard from '@/Components/ValidacionDocumentos/SolicitudIngresoCard.vue';
import TablaDocumentacionCard from '@/Components/ValidacionDocumentos/TablaDocumentacionCard.vue';
import { estadoClases, estadoTexto } from '@/Composables/useValidacionDocumentoHelpers';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    validacion: {
        type: Object,
        required: true,
    },
    tiposContrato: {
        type: Array,
        default: () => [],
    },
    success: {
        type: String,
        default: null,
    },
});

const cuvUrlActual = ref(props.validacion.cuv_url || null);
const cedulaUrlActual = ref(props.validacion.cedula_url || null);
const contratoUrlActual = ref(props.validacion.contrato_url || null);
const mostrarModalConstancia = ref(false);
const mensajeConstancia = ref('');
const mensajeAuditoriaEmpresa = ref('');

const estadoVisual = computed(() => {
    if (props.validacion.estado_revision === 'aprobada' && !contratoUrlActual.value) {
        return 'pendiente_constancia';
    }

    return props.validacion.estado_revision;
});

const onAuditoriaEmpresaExitosa = () => {
    mensajeAuditoriaEmpresa.value = 'Matrícula escaneada y comparada correctamente. Los datos del expediente fueron actualizados.';
    router.reload({
        only: ['validacion'],
        preserveScroll: true,
    });
};

const onConstanciaGenerada = (contratoUrl) => {
    contratoUrlActual.value = contratoUrl;
    mensajeConstancia.value = 'Constancia generada correctamente. El estado ahora es Aprobado.';
};
</script>

<template>
    <Head :title="`Expediente ${validacion.placa}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                <div>
                    <Link
                        :href="route('validacion-documentos.index')"
                        class="text-sm font-medium text-indigo-700 hover:text-indigo-900"
                    >
                        ← Volver a la bandeja
                    </Link>
                    <h2 class="mt-2 text-xl font-semibold leading-tight text-gray-800">
                        Revisión de matrícula: {{ validacion.placa }}
                    </h2>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="w-fit rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide"
                        :class="validacion.categoria_ingreso === 'empresa'
                            ? 'bg-slate-800 text-yellow-400'
                            : 'bg-gray-100 text-gray-700'"
                    >
                        {{ validacion.categoria_ingreso === 'empresa' ? 'Empresa' : 'Particular' }}
                    </span>
                    <span :class="estadoClases(estadoVisual)" class="w-fit rounded-full px-3 py-1 text-sm font-semibold">
                        {{ estadoTexto(estadoVisual) }}
                    </span>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div v-if="success" role="status" class="rounded-md border border-green-200 bg-green-50 p-4 text-sm text-green-800">
                    {{ success }}
                </div>
                <div v-if="mensajeConstancia" role="status" class="rounded-md border border-green-200 bg-green-50 p-4 text-sm text-green-800">
                    {{ mensajeConstancia }}
                </div>
                <div v-if="mensajeAuditoriaEmpresa" role="status" class="rounded-md border border-green-200 bg-green-50 p-4 text-sm text-green-800">
                    {{ mensajeAuditoriaEmpresa }}
                </div>

                <!-- MÓDULO DE ESCANEO Y COMPARACIÓN DE MATRÍCULA (SOLO CATEGORÍA EMPRESA) -->
                <EscanerAuditoriaMatricula
                    v-if="validacion.categoria_ingreso === 'empresa' && validacion.estado_revision === 'pendiente'"
                    :endpoint-url="route('validacion-documentos.escanear.matricula', validacion.id)"
                    input-id-prefix="show-empresa-matricula"
                    titulo="Escáner IA de Matrícula + Auditoría Oficial (Categoría Empresa)"
                    subtitulo="Sube la matrícula de este vehículo de Empresa para extraer sus datos con Gemini y compararlos contra AxisCloud, SRI, Autority, Fiscalía, SUPA y SATJE."
                    @success="onAuditoriaEmpresaExitosa"
                />

                <!-- BANNER PENDIENTE GENERAR CONSTANCIA -->
                <section
                    v-if="estadoVisual === 'pendiente_constancia'"
                    class="flex flex-col justify-between gap-4 rounded-lg border border-indigo-300 bg-indigo-50 p-5 shadow-sm sm:flex-row sm:items-center"
                >
                    <div>
                        <p class="text-sm font-bold text-indigo-950">
                            Comprobante cargado · Falta generar la constancia de compra-venta
                        </p>
                        <p class="mt-1 text-xs text-indigo-800">
                            El vehículo ya fue registrado en el inventario. Sube la cédula del vendedor, verifica los datos extraídos con Gemini y descarga el contrato `.docx` para completar el proceso como <strong>Aprobado</strong>.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 rounded-md bg-yellow-500 px-5 py-2.5 text-sm font-bold text-gray-900 shadow-sm hover:bg-yellow-400"
                        @click="mostrarModalConstancia = true"
                    >
                        Generar Constancia
                    </button>
                </section>

                <!-- 1. RESUMEN DEL EXPEDIENTE Y DESCARGAS -->
                <ResumenExpedienteCard
                    :validacion="validacion"
                    :cuv-url-actual="cuvUrlActual"
                    :cedula-url-actual="cedulaUrlActual"
                    :contrato-url-actual="contratoUrlActual"
                />

                <!-- 2. TABLA DE DOCUMENTACIÓN -->
                <TablaDocumentacionCard
                    :validacion="validacion"
                    :estado-visual="estadoVisual"
                    :cuv-url-actual="cuvUrlActual"
                    :cedula-url-actual="cedulaUrlActual"
                    :contrato-url-actual="contratoUrlActual"
                    @abrir-constancia="mostrarModalConstancia = true"
                />

                <!-- 3. GESTIÓN DE PAGOS E INGRESO A INVENTARIO -->
                <GestionPagosCard :validacion="validacion" />

                <!-- 4. DATOS LEÍDOS DE LA MATRÍCULA VS AXISCLOUD -->
                <DatosExtraidosPanel
                    :datos-documento="validacion.datos_documento"
                    :datos-oficiales="validacion.datos_oficiales"
                />

                <!-- 5. AUDITORÍA INTEGRAL Y COMPARACIÓN AUTOMÁTICA -->
                <AuditoriaIntegralPanel :validacion="validacion" />

                <!-- 6. SOLICITUD DE INGRESO AL INVENTARIO -->
                <SolicitudIngresoCard :validacion="validacion" />

                <!-- 7. DECISIÓN HUMANA (APROBACIÓN/RECHAZO SOLO ADMINISTRACIÓN) -->
                <DecisionHumanaCard
                    :validacion="validacion"
                    :tipos-contrato="tiposContrato"
                    @cuv-actualizado="(url) => (cuvUrlActual = url)"
                />
            </div>
        </div>

        <!-- MODAL DE GENERACIÓN DE CONSTANCIA / CONTRATO -->
        <GenerarConstanciaModal
            :show="mostrarModalConstancia"
            :validacion="validacion"
            :cedula-url-actual="cedulaUrlActual"
            @close="mostrarModalConstancia = false"
            @cedula-actualizada="(url) => (cedulaUrlActual = url)"
            @constancia-generada="onConstanciaGenerada"
        />
    </AuthenticatedLayout>
</template>
