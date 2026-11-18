<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';

const props = defineProps({
    validacion: {
        type: Object,
        required: true,
    },
    success: String,
});

const form = useForm({
    decision: '',
    comentario_revision: '',
    comentario_reenvio: '',
    documento_cuv: null,
});
const archivoCuv = ref(null);
const escaneandoCuv = ref(false);
const errorCuv = ref('');
const erroresValidacionCuv = ref([]);
const resultadoCuv = ref(props.validacion.resumen_auditoria?.cuv || null);
const cuvUrlActual = ref(props.validacion.cuv_url || null);
const puedeReenviar = computed(() => form.comentario_reenvio.trim().length > 0);
const tieneResumenWeb = computed(() => Boolean(
    props.validacion.resumen_auditoria
    && (
        props.validacion.resumen_auditoria.multas
        || props.validacion.resumen_auditoria.sri
        || props.validacion.resumen_auditoria.fiscalia
        || props.validacion.resumen_auditoria.propietarios
    ),
));

const seleccionarCuv = (event) => {
    const archivo = event.target.files[0] || null;
    archivoCuv.value = archivo;
    form.documento_cuv = archivo;
    errorCuv.value = '';
    erroresValidacionCuv.value = [];
};

const escanearYCompararCuv = async () => {
    if (!archivoCuv.value) {
        errorCuv.value = 'Selecciona primero un archivo PDF o imagen del CUV.';
        erroresValidacionCuv.value = [];
        return;
    }

    escaneandoCuv.value = true;
    errorCuv.value = '';
    erroresValidacionCuv.value = [];

    const formData = new FormData();
    formData.append('documento_cuv', archivoCuv.value);

    const mostrarError = (respuesta, mensajeAlternativo) => {
        errorCuv.value = respuesta?.message || mensajeAlternativo;
        erroresValidacionCuv.value = Object.values(respuesta?.errors || {})
            .flat()
            .filter((mensaje) => typeof mensaje === 'string');
    };

    try {
        const res = await axios.post(
            route('validacion-documentos.escanear.cuv', props.validacion.id),
            formData,
            {
                headers: { 'Content-Type': 'multipart/form-data' },
            },
        );

        if (res.data && res.data.success) {
            resultadoCuv.value = res.data;
            if (res.data.cuv_url) {
                cuvUrlActual.value = res.data.cuv_url;
            }
            form.documento_cuv = null;
        } else {
            mostrarError(res.data, 'El servidor no devolvió una respuesta válida al analizar el CUV.');
        }
    } catch (error) {
        mostrarError(error.response?.data, error.message || 'Error al escanear el CUV.');
    } finally {
        escaneandoCuv.value = false;
    }
};

const estadoTexto = (estado) => ({
    pendiente: 'Pendiente de revisión',
    aprobada: 'Aprobada',
    rechazada: 'Rechazada',
    registrada: 'Registrada en inventario',
}[estado] || estado);

const estadoClases = (estado) => ({
    pendiente: 'bg-amber-100 text-amber-900',
    aprobada: 'bg-green-100 text-green-900',
    rechazada: 'bg-red-100 text-red-900',
    registrada: 'bg-blue-100 text-blue-900',
}[estado] || 'bg-gray-100 text-gray-800');

const fechaTexto = (fecha) => fecha
    ? new Date(fecha).toLocaleString('es-EC')
    : 'No disponible';

const valorTexto = (valor) => {
    if (valor === null || valor === undefined || valor === '') {
        return 'No disponible';
    }

    return typeof valor === 'object' ? JSON.stringify(valor) : String(valor);
};

const campoIngresoTexto = (campo) => ({
    id_vehiculo: 'Placa',
    id_marca: 'Marca',
    id_clase: 'Clase',
    id_pais: 'País',
    id_color: 'Color',
    id_tipo_combustible: 'Combustible',
    id_transmision: 'Transmisión',
    id_patio: 'Patio',
    id_proveedor: 'Proveedor',
    modelo: 'Modelo',
    anio: 'Año',
    motor: 'Motor',
    chasis: 'Chasis',
    cilindraje: 'Cilindraje',
    servicio: 'Servicio',
    tipo_vehiculo: 'Tipo de vehículo',
    ultima_fecha_revision: 'Última fecha de revisión',
    lugar_revision: 'Lugar de revisión',
    kilometraje: 'Kilometraje',
    costo_adquisicion: 'Costo de adquisición',
    precio_venta: 'Precio de venta',
}[campo] || campo.replaceAll('_', ' '));

const decidir = (decision) => {
    form.decision = decision;
    form.transform((data) => ({ ...data, _method: 'patch' })).post(
        route('validacion-documentos.decidir', props.validacion.id),
        {
            preserveScroll: true,
            onFinish: () => form.transform((data) => data),
        },
    );
};

const reenviar = () => {
    form.post(route('validacion-documentos.reenviar', props.validacion.id), {
        preserveScroll: true,
    });
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
                <span :class="estadoClases(validacion.estado_revision)" class="w-fit rounded-full px-3 py-1 text-sm font-semibold">
                    {{ estadoTexto(validacion.estado_revision) }}
                </span>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div v-if="success" role="status" class="rounded-md border border-green-200 bg-green-50 p-4 text-sm text-green-800">
                    {{ success }}
                </div>

                <section class="grid grid-cols-1 gap-4 rounded-lg border border-gray-200 bg-white p-5 shadow-sm sm:grid-cols-2">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Subido por</p>
                        <p class="mt-1 text-sm text-gray-900">{{ validacion.subido_por }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Fecha de escaneo</p>
                        <p class="mt-1 text-sm text-gray-900">{{ fechaTexto(validacion.created_at) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Alertas críticas de IA</p>
                        <p class="mt-1 text-sm font-semibold" :class="validacion.alertas_criticas > 0 ? 'text-red-700' : 'text-green-700'">
                            {{ validacion.alertas_criticas }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-end gap-2">
                        <a
                            :href="validacion.documento_url"
                            class="inline-flex rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700"
                        >
                            Descargar matrícula
                        </a>
                        <a
                            v-if="cuvUrlActual"
                            :href="cuvUrlActual"
                            class="inline-flex rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-600"
                        >
                            Descargar CUV
                        </a>
                    </div>
                </section>

                <section class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <details class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                        <summary class="cursor-pointer border-b border-gray-200 bg-gray-50 px-5 py-3 text-sm font-semibold text-gray-900">
                            Datos leídos de la matrícula
                        </summary>
                        <dl class="divide-y divide-gray-100 px-5">
                            <div v-for="(valor, campo) in validacion.datos_documento" :key="campo" class="grid grid-cols-2 gap-3 py-3 text-sm">
                                <dt class="break-words font-medium text-gray-600">{{ campo.replaceAll('_', ' ') }}</dt>
                                <dd class="break-words text-gray-900">{{ valorTexto(valor) }}</dd>
                            </div>
                        </dl>
                    </details>

                    <details class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                        <summary class="cursor-pointer border-b border-gray-200 bg-gray-50 px-5 py-3 text-sm font-semibold text-gray-900">
                            Datos oficiales de AxisCloud
                        </summary>
                        <dl v-if="Object.keys(validacion.datos_oficiales).length" class="divide-y divide-gray-100 px-5">
                            <div v-for="(valor, campo) in validacion.datos_oficiales" :key="campo" class="grid grid-cols-2 gap-3 py-3 text-sm">
                                <dt class="break-words font-medium text-gray-600">{{ campo.replaceAll('_', ' ') }}</dt>
                                <dd class="break-words text-gray-900">{{ valorTexto(valor) }}</dd>
                            </div>
                        </dl>
                        <p v-else class="p-5 text-sm text-gray-500">
                            AxisCloud no devolvió datos oficiales para esta consulta.
                        </p>
                    </details>
                </section>

                <details class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <summary class="cursor-pointer border-b border-gray-200 bg-gray-50 px-5 py-3 text-sm font-semibold text-gray-900">
                        Comparación automática
                    </summary>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-white text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-5 py-3">Campo</th>
                                    <th class="px-5 py-3">Matrícula / dato ingresado</th>
                                    <th class="px-5 py-3">AxisCloud</th>
                                    <th class="px-5 py-3">Estado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="(item, index) in validacion.comparacion" :key="`${item.clave}-${index}`">
                                    <td class="px-5 py-3 font-medium text-gray-800">{{ item.campo }}</td>
                                    <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ item.documento }}</td>
                                    <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ item.sistema_web }}</td>
                                    <td class="px-5 py-3">
                                        <span
                                            :class="{
                                                'bg-green-100 text-green-800': item.estado === 'coincide',
                                                'bg-amber-100 text-amber-800': item.estado === 'casi_coincide',
                                                'bg-gray-100 text-gray-700': item.estado === 'no_verificable',
                                                'bg-red-100 text-red-800': item.estado === 'diferente',
                                                'bg-blue-100 text-blue-800': item.estado === 'informativo',
                                            }"
                                            class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                        >
                                            {{ item.estado.replaceAll('_', ' ') }}
                                        </span>
                                        <p v-if="item.nota" class="mt-1 max-w-xs text-xs text-gray-500">
                                            {{ item.nota }}
                                        </p>
                                    </td>
                                </tr>
                                <tr v-if="validacion.comparacion.length === 0">
                                    <td colspan="4" class="px-5 py-6 text-center text-gray-500">
                                        No hay campos comparables para mostrar.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </details>

                <details
                    v-if="tieneResumenWeb"
                    class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
                >
                    <summary class="cursor-pointer border-b border-gray-200 bg-gray-50 px-5 py-3 text-sm font-semibold text-gray-900">
                        Resumen de auditoría
                    </summary>
                    <div class="p-5">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <div class="rounded-md bg-gray-50 p-3 text-sm">
                                <p class="font-medium text-gray-600">Multas de tránsito</p>
                                <p class="mt-1 font-semibold text-gray-900">
                                    ${{ Number(validacion.resumen_auditoria.multas?.total || 0).toFixed(2) }}
                                    · {{ validacion.resumen_auditoria.multas?.cantidad || 0 }} registro(s)
                                </p>
                            </div>
                            <div class="rounded-md bg-gray-50 p-3 text-sm">
                                <p class="font-medium text-gray-600">Valores pendientes SRI</p>
                                <p class="mt-1 font-semibold text-gray-900">
                                    ${{ Number(validacion.resumen_auditoria.sri?.total || 0).toFixed(2) }}
                                </p>
                            </div>
                            <div class="rounded-md bg-gray-50 p-3 text-sm">
                                <p class="font-medium text-gray-600">Denuncias en Fiscalía</p>
                                <p class="mt-1 font-semibold text-gray-900">
                                    {{ validacion.resumen_auditoria.fiscalia?.ok === false
                                        ? 'Consulta no disponible'
                                        : `${validacion.resumen_auditoria.fiscalia?.cantidad || 0} registro(s)` }}
                                </p>
                            </div>
                            <div class="rounded-md bg-gray-50 p-3 text-sm">
                                <p class="font-medium text-gray-600">Número de traspasos</p>
                                <p class="mt-1 font-semibold text-gray-900">
                                    {{ validacion.resumen_auditoria.propietarios?.total_traspasos || 0 }}
                                </p>
                            </div>
                        </div>
                        <div
                            v-if="validacion.resumen_auditoria.fiscalia_cedula"
                            class="mt-3 rounded-md border p-3 text-sm"
                            :class="!validacion.resumen_auditoria.fiscalia_cedula.ok
                                ? 'border-amber-200 bg-amber-50 text-amber-900'
                                : validacion.resumen_auditoria.fiscalia_cedula.tiene_denuncias
                                    ? 'border-red-200 bg-red-50 text-red-900'
                                    : 'border-green-200 bg-green-50 text-green-900'"
                        >
                            <p class="font-semibold">Fiscalía por cédula del propietario</p>
                            <p class="mt-1">
                                {{ !validacion.resumen_auditoria.fiscalia_cedula.ok
                                    ? 'Consulta no disponible; no se puede confirmar ausencia de denuncias.'
                                    : validacion.resumen_auditoria.fiscalia_cedula.tiene_denuncias
                                        ? `Se encontraron ${validacion.resumen_auditoria.fiscalia_cedula.cantidad} proceso(s) como sospechoso o denunciado.`
                                        : 'Sin procesos como sospechoso o denunciado en la consulta realizada.' }}
                            </p>
                            <ul
                                v-if="validacion.resumen_auditoria.fiscalia_cedula.listado?.length"
                                class="mt-2 space-y-2 border-t border-gray-300 pt-2"
                            >
                                <li
                                    v-for="(denuncia, index) in validacion.resumen_auditoria.fiscalia_cedula.listado"
                                    :key="index"
                                >
                                    {{ denuncia.noticia_delito }} · {{ denuncia.fecha }} · {{ denuncia.rol }} · {{ denuncia.localidad }} · {{ denuncia.descripcion }}
                                </li>
                            </ul>
                        </div>
                        <p class="mt-3 text-sm text-gray-700">
                            {{ validacion.resumen_auditoria.propietarios?.mensaje }}
                        </p>
                        <ul
                            v-if="validacion.resumen_auditoria.multas?.listado?.length"
                            class="mt-3 space-y-2 border-t border-gray-100 pt-3 text-sm"
                        >
                            <li
                                v-for="(multa, index) in validacion.resumen_auditoria.multas.listado"
                                :key="index"
                                class="flex flex-col justify-between gap-1 sm:flex-row"
                            >
                                <span class="text-gray-700">
                                    {{ multa.citacion }} · {{ multa.entidad }} · {{ multa.descripcion }}
                                </span>
                                <span class="shrink-0 font-semibold text-red-700">
                                    ${{ Number(multa.total || 0).toFixed(2) }}
                                </span>
                            </li>
                        </ul>
                        <ul
                            v-if="validacion.resumen_auditoria.sri?.rubros?.length"
                            class="mt-3 space-y-2 border-t border-gray-100 pt-3 text-sm"
                        >
                            <li
                                v-for="(rubro, index) in validacion.resumen_auditoria.sri.rubros"
                                :key="index"
                                class="flex justify-between gap-3"
                            >
                                <span class="text-gray-700">
                                    {{ rubro.concepto }} {{ rubro.periodo ? `(${rubro.periodo})` : '' }}
                                </span>
                                <span class="shrink-0 font-semibold text-amber-700">
                                    ${{ Number(rubro.valor || 0).toFixed(2) }}
                                </span>
                            </li>
                        </ul>
                        <ul
                            v-if="validacion.resumen_auditoria.fiscalia?.listado?.length"
                            class="mt-3 space-y-2 border-t border-gray-100 pt-3 text-sm"
                        >
                            <li
                                v-for="(denuncia, index) in validacion.resumen_auditoria.fiscalia.listado"
                                :key="index"
                                class="rounded-md bg-red-50 p-3 text-red-900"
                            >
                                {{ denuncia.noticia_delito }} · {{ denuncia.fecha }} · {{ denuncia.localidad }} · {{ denuncia.descripcion }}
                            </li>
                        </ul>
                    </div>
                </details>

                <section
                    v-if="validacion.comentario_solicitud || validacion.comentario_reenvio || validacion.datos_ingreso"
                    class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm"
                >
                    <h3 class="text-sm font-semibold text-gray-900">Solicitud de ingreso al inventario</h3>
                    <div v-if="validacion.comentario_solicitud" class="mt-3 rounded-md bg-blue-50 p-3 text-sm text-blue-900">
                        <p class="font-semibold">Comentario del solicitante</p>
                        <p class="mt-1 whitespace-pre-wrap">{{ validacion.comentario_solicitud }}</p>
                    </div>
                    <div v-if="validacion.comentario_reenvio" class="mt-3 rounded-md bg-indigo-50 p-3 text-sm text-indigo-900">
                        <p class="font-semibold">Comentario del reenvío</p>
                        <p class="mt-1 whitespace-pre-wrap">{{ validacion.comentario_reenvio }}</p>
                    </div>
                    <details v-if="validacion.datos_ingreso" class="mt-3 rounded-md border border-gray-200">
                        <summary class="cursor-pointer bg-gray-50 px-4 py-3 text-sm font-semibold text-gray-800">
                            Ver datos de adquisición
                        </summary>
                        <dl class="grid grid-cols-1 gap-x-6 px-4 sm:grid-cols-2">
                            <div
                                v-for="(valor, campo) in validacion.datos_ingreso"
                                :key="campo"
                                class="grid grid-cols-2 gap-3 border-b border-gray-100 py-2 text-sm"
                            >
                                <dt class="font-medium text-gray-600">{{ campoIngresoTexto(campo) }}</dt>
                                <dd class="break-words text-gray-900">{{ valorTexto(valor) }}</dd>
                            </div>
                        </dl>
                    </details>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h3 class="text-sm font-semibold text-gray-900">Decisión humana</h3>
                    <div
                        v-if="validacion.estado_revision === 'pendiente' && validacion.comentario_revision"
                        class="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-900"
                    >
                        <p class="font-semibold">Motivo del rechazo anterior</p>
                        <p class="mt-1 whitespace-pre-wrap">{{ validacion.comentario_revision }}</p>
                    </div>
                    <div v-if="validacion.estado_revision === 'pendiente'" class="mt-4 space-y-4">
                        <div>
                            <label for="documento-cuv" class="block text-sm font-medium text-gray-700">
                                Certificado Único Vehicular (CUV) (opcional)
                            </label>
                            <div class="mt-1 flex flex-col gap-3 sm:flex-row sm:items-center">
                                <input
                                    id="documento-cuv"
                                    type="file"
                                    :disabled="escaneandoCuv"
                                    accept="image/jpeg,image/png,image/webp,.pdf"
                                    class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-700 file:px-3 file:py-2 file:text-white hover:file:bg-indigo-600"
                                    @input="seleccionarCuv"
                                />
                                <button
                                    type="button"
                                    :disabled="escaneandoCuv || !archivoCuv"
                                    class="whitespace-nowrap rounded-md bg-yellow-500 px-4 py-2 text-xs font-bold text-gray-900 shadow-sm hover:bg-yellow-400 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="escanearYCompararCuv"
                                >
                                    {{ escaneandoCuv ? 'Analizando CUV con IA...' : 'Analizar y comparar CUV con IA' }}
                                </button>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                PDF o imagen, máximo 5 MB. Puedes analizarlo con Gemini para verificar que coincida con la matrícula escaneada.
                            </p>
                            <div
                                v-if="escaneandoCuv"
                                role="progressbar"
                                aria-label="Analizando CUV con Gemini"
                                class="mt-3 h-1.5 overflow-hidden rounded-full bg-gray-200"
                            >
                                <div class="h-full w-1/3 animate-pulse rounded-full bg-indigo-600" />
                            </div>
                            <div
                                v-if="errorCuv"
                                role="alert"
                                class="mt-3 rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-700"
                            >
                                <p class="font-semibold">{{ errorCuv }}</p>
                                <ul
                                    v-if="erroresValidacionCuv.length"
                                    class="mt-1 list-inside list-disc space-y-1"
                                >
                                    <li v-for="(error, index) in erroresValidacionCuv" :key="index">
                                        {{ error }}
                                    </li>
                                </ul>
                            </div>
                            <p
                                v-if="form.errors.documento_cuv"
                                class="mt-1 text-sm text-red-600"
                                role="alert"
                            >
                                {{ form.errors.documento_cuv }}
                            </p>
                        </div>

                        <div
                            v-if="resultadoCuv && resultadoCuv.comparacion?.length"
                            class="overflow-hidden rounded-lg border border-gray-200 bg-gray-50 shadow-sm"
                        >
                            <div class="flex flex-col justify-between gap-2 border-b border-gray-200 bg-gray-900 px-4 py-3 text-xs text-white sm:flex-row sm:items-center">
                                <span
                                    class="font-bold uppercase tracking-wider"
                                    :class="resultadoCuv.alertas_criticas > 0
                                        ? 'text-red-400'
                                        : resultadoCuv.comparacion.some((item) => item.estado !== 'coincide')
                                            ? 'text-amber-300'
                                            : 'text-green-400'"
                                >
                                    {{ resultadoCuv.alertas_criticas > 0
                                        ? `¡Atención! Se detectaron ${resultadoCuv.alertas_criticas} alerta(s) crítica(s) en la verificación del CUV`
                                        : resultadoCuv.comparacion.some((item) => item.estado !== 'coincide')
                                            ? 'Comparación Matrícula / Formulario vs CUV: hay campos diferentes o no verificables'
                                            : 'Verificación exitosa: los datos del CUV coinciden con la matrícula y el formulario' }}
                                </span>
                                <div v-if="resultadoCuv.propietario_comparacion" class="text-[11px] text-gray-300">
                                    <span>
                                        Matrícula:
                                        <strong class="text-white">
                                            {{ resultadoCuv.propietario_comparacion.nombre_matricula || 'No visible' }}
                                            ({{ resultadoCuv.propietario_comparacion.cedula_matricula || 'S/N' }})
                                        </strong>
                                    </span>
                                    <span class="mx-1">·</span>
                                    <span>
                                        CUV:
                                        <strong class="text-yellow-300">
                                            {{ resultadoCuv.propietario_comparacion.nombre_cuv || 'No visible' }}
                                            ({{ resultadoCuv.propietario_comparacion.cedula_cuv || 'S/N' }})
                                        </strong>
                                    </span>
                                </div>
                            </div>

                            <!-- TARJETAS DE ALERTAS LEGALES Y DE DOMINIO DEL CUV -->
                            <div
                                v-if="resultadoCuv.alertas_cuv"
                                class="grid grid-cols-1 gap-3 border-b border-gray-200 bg-gray-100 p-4 md:grid-cols-3"
                            >
                                <!-- 1. Bloqueos Vigentes -->
                                <div
                                    class="rounded-lg border p-3 text-xs"
                                    :class="resultadoCuv.alertas_cuv.bloqueos?.tiene
                                        ? 'border-red-300 bg-red-50 text-red-900'
                                        : 'border-green-300 bg-green-50 text-green-900'"
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="font-bold uppercase tracking-wide">
                                            {{ resultadoCuv.alertas_cuv.bloqueos?.tiene
                                                ? '🚨 Bloqueos Vigentes'
                                                : '✅ Bloqueos Vigentes' }}
                                        </p>
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[11px] font-bold"
                                            :class="resultadoCuv.alertas_cuv.bloqueos?.tiene
                                                ? 'bg-red-200 text-red-900'
                                                : 'bg-green-200 text-green-900'"
                                        >
                                            {{ resultadoCuv.alertas_cuv.bloqueos?.tiene ? 'CON BLOQUEO' : 'SIN BLOQUEOS' }}
                                        </span>
                                    </div>
                                    <p class="mt-1.5 font-medium">
                                        {{ resultadoCuv.alertas_cuv.bloqueos?.estado_texto || 'NO TIENE REGISTRADOS.' }}
                                    </p>
                                    <ul
                                        v-if="resultadoCuv.alertas_cuv.bloqueos?.detalle?.length"
                                        class="mt-2 list-inside list-disc space-y-1 border-t border-red-200 pt-1.5 text-[11px]"
                                    >
                                        <li v-for="(item, idx) in resultadoCuv.alertas_cuv.bloqueos.detalle" :key="`bloq-${idx}`">
                                            {{ item }}
                                        </li>
                                    </ul>
                                </div>

                                <!-- 2. Gravámenes Vigentes -->
                                <div
                                    class="rounded-lg border p-3 text-xs"
                                    :class="resultadoCuv.alertas_cuv.gravamenes?.tiene
                                        ? 'border-red-300 bg-red-50 text-red-900'
                                        : 'border-green-300 bg-green-50 text-green-900'"
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="font-bold uppercase tracking-wide">
                                            {{ resultadoCuv.alertas_cuv.gravamenes?.tiene
                                                ? '🚨 Gravámenes Vigentes'
                                                : '✅ Gravámenes Vigentes' }}
                                        </p>
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[11px] font-bold"
                                            :class="resultadoCuv.alertas_cuv.gravamenes?.tiene
                                                ? 'bg-red-200 text-red-900'
                                                : 'bg-green-200 text-green-900'"
                                        >
                                            {{ resultadoCuv.alertas_cuv.gravamenes?.tiene ? 'CON GRAVAMEN' : 'SIN GRAVÁMENES' }}
                                        </span>
                                    </div>
                                    <p class="mt-1.5 font-medium">
                                        {{ resultadoCuv.alertas_cuv.gravamenes?.estado_texto || 'NO TIENE REGISTRADOS.' }}
                                    </p>
                                    <ul
                                        v-if="resultadoCuv.alertas_cuv.gravamenes?.detalle?.length"
                                        class="mt-2 list-inside list-disc space-y-1 border-t border-red-200 pt-1.5 text-[11px]"
                                    >
                                        <li v-for="(item, idx) in resultadoCuv.alertas_cuv.gravamenes.detalle" :key="`grav-${idx}`">
                                            {{ item }}
                                        </li>
                                    </ul>
                                </div>

                                <!-- 3. Historia de Dominio (Cantidad de Dueños) -->
                                <div
                                    class="rounded-lg border p-3 text-xs"
                                    :class="resultadoCuv.alertas_cuv.propietarios?.tiene_mas_de_un_dueno
                                        ? 'border-amber-300 bg-amber-50 text-amber-900'
                                        : 'border-green-300 bg-green-50 text-green-900'"
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="font-bold uppercase tracking-wide">
                                            {{ resultadoCuv.alertas_cuv.propietarios?.tiene_mas_de_un_dueno
                                                ? '⚠️ Historia de Dominio'
                                                : '✅ Historia de Dominio' }}
                                        </p>
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[11px] font-bold"
                                            :class="resultadoCuv.alertas_cuv.propietarios?.tiene_mas_de_un_dueno
                                                ? 'bg-amber-200 text-amber-900'
                                                : 'bg-green-200 text-green-900'"
                                        >
                                            {{ resultadoCuv.alertas_cuv.propietarios?.tiene_mas_de_un_dueno
                                                ? `${resultadoCuv.alertas_cuv.propietarios.cantidad} DUEÑOS`
                                                : '1 SOLO DUEÑO' }}
                                        </span>
                                    </div>
                                    <p class="mt-1.5 font-medium">
                                        {{ resultadoCuv.alertas_cuv.propietarios?.mensaje }}
                                    </p>
                                    <ul
                                        v-if="resultadoCuv.alertas_cuv.propietarios?.listado?.length"
                                        class="mt-2 space-y-1 border-t border-amber-200/80 pt-1.5 text-[11px]"
                                    >
                                        <li
                                            v-for="(dueno, idx) in resultadoCuv.alertas_cuv.propietarios.listado"
                                            :key="`dueno-${idx}`"
                                            class="truncate"
                                        >
                                            <strong>{{ dueno.nombre }}</strong> ({{ dueno.documento_identidad }}) · {{ dueno.desde }} → {{ dueno.hasta }}
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <div class="overflow-x-auto bg-white">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                        <tr>
                                            <th class="px-4 py-2.5">Campo</th>
                                            <th class="px-4 py-2.5">Matrícula / Formulario de ingreso</th>
                                            <th class="px-4 py-2.5">Leído en CUV (IA)</th>
                                            <th class="px-4 py-2.5">Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr
                                            v-for="(item, index) in resultadoCuv.comparacion"
                                            :key="`cuv-${item.clave}-${index}`"
                                            :class="item.estado === 'diferente' && item.critico
                                                ? 'bg-red-50/70'
                                                : item.estado === 'casi_coincide' && item.critico
                                                    ? 'bg-amber-50/70'
                                                    : ''"
                                        >
                                            <td class="px-4 py-2.5 font-medium text-gray-800">
                                                <div>{{ item.campo }}</div>
                                                <span
                                                    v-if="item.origen === 'formulario_ingreso'"
                                                    class="mt-0.5 inline-block rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-700"
                                                >
                                                    Enviado en formulario
                                                </span>
                                            </td>
                                            <td class="px-4 py-2.5 font-mono text-xs text-gray-700">{{ item.documento }}</td>
                                            <td class="px-4 py-2.5 font-mono text-xs text-gray-700">{{ item.cuv ?? item.sistema_web }}</td>
                                            <td class="px-4 py-2.5">
                                                <span
                                                    :class="{
                                                        'bg-green-100 text-green-800': item.estado === 'coincide',
                                                        'bg-amber-100 text-amber-800': item.estado === 'casi_coincide',
                                                        'bg-gray-100 text-gray-700': item.estado === 'no_verificable',
                                                        'bg-red-100 text-red-800': item.estado === 'diferente',
                                                    }"
                                                    class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                                >
                                                    {{ item.estado.replaceAll('_', ' ') }}
                                                </span>
                                                <p v-if="item.nota" class="mt-1 max-w-xs text-xs text-gray-500">
                                                    {{ item.nota }}
                                                </p>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div>
                            <label for="comentario-revision" class="block text-sm font-medium text-gray-700">
                                Comentario (opcional)
                            </label>
                            <textarea
                                id="comentario-revision"
                                v-model="form.comentario_revision"
                                rows="3"
                                maxlength="2000"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Agrega una observación para el expediente..."
                            />
                        </div>
                        <div
                            v-if="Object.keys(form.errors).length"
                            role="alert"
                            class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700"
                        >
                            <p v-for="(error, campo) in form.errors" :key="campo">
                                {{ error }}
                            </p>
                        </div>
                        <div class="flex flex-col gap-3 sm:flex-row">
                            <button
                                type="button"
                                :disabled="form.processing"
                                class="rounded-md bg-green-700 px-5 py-2 text-sm font-semibold text-white hover:bg-green-800 disabled:opacity-50"
                                @click="decidir('aprobada')"
                            >
                                Aprobar documento
                            </button>
                            <button
                                type="button"
                                :disabled="form.processing"
                                class="rounded-md bg-red-700 px-5 py-2 text-sm font-semibold text-white hover:bg-red-800 disabled:opacity-50"
                                @click="decidir('rechazada')"
                            >
                                Rechazar documento
                            </button>
                        </div>
                    </div>
                    <div
                        v-else-if="validacion.estado_revision === 'rechazada' && validacion.puede_reenviar"
                        class="mt-4 space-y-4"
                    >
                        <div class="space-y-2 text-sm text-gray-700">
                            <p>
                                Rechazado por {{ validacion.revisado_por || 'Usuario no disponible' }}
                                el {{ fechaTexto(validacion.revisado_en) }}.
                            </p>
                            <p v-if="validacion.comentario_revision" class="whitespace-pre-wrap rounded-md bg-red-50 p-3 text-red-900">
                                {{ validacion.comentario_revision }}
                            </p>
                            <p v-else class="text-gray-500">No se dejó un comentario de rechazo.</p>
                        </div>
                        <div>
                            <label for="comentario-reenvio" class="block text-sm font-medium text-gray-700">
                                Comentario para solicitar una nueva revisión
                            </label>
                            <textarea
                                id="comentario-reenvio"
                                v-model="form.comentario_reenvio"
                                rows="3"
                                maxlength="2000"
                                required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Explica qué se corrigió o agrega la información solicitada..."
                            />
                            <p
                                v-if="form.errors.comentario_reenvio"
                                class="mt-1 text-sm text-red-600"
                                role="alert"
                            >
                                {{ form.errors.comentario_reenvio }}
                            </p>
                        </div>
                        <button
                            type="button"
                            :disabled="form.processing || !puedeReenviar"
                            class="rounded-md bg-indigo-700 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-800 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="reenviar"
                        >
                            Enviar nuevamente a revisión
                        </button>
                    </div>
                    <div v-else-if="validacion.estado_revision === 'registrada'" class="mt-3 space-y-2 text-sm text-blue-800">
                        El vehículo fue registrado directamente en el inventario usando los datos oficiales de AxisCloud.
                    </div>
                    <div v-else class="mt-3 space-y-2 text-sm text-gray-700">
                        <p>
                            Revisado por {{ validacion.revisado_por || 'Usuario no disponible' }}
                            el {{ fechaTexto(validacion.revisado_en) }}.
                        </p>
                        <p v-if="validacion.comentario_revision" class="whitespace-pre-wrap rounded-md bg-gray-50 p-3">
                            {{ validacion.comentario_revision }}
                        </p>
                        <p v-else class="text-gray-500">No se dejó un comentario.</p>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
