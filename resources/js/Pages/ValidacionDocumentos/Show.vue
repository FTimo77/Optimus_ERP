<script setup>
import EscanerAuditoriaMatricula from '@/Components/EscanerAuditoriaMatricula.vue';
import Modal from '@/Components/Modal.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    validacion: {
        type: Object,
        required: true,
    },
    tiposContrato: {
        type: Array,
        default: () => [],
    },
    success: String,
});

const form = useForm({
    decision: '',
    id_tipo_contrato: props.validacion.id_tipo_contrato || '',
    comentario_revision: '',
    comentario_reenvio: '',
    documento_cuv: null,
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
const formatearMoneda = (valor) =>
    `$${Number(valor || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const abonoForm = useForm({
    comprobante: null,
    monto: '',
    fecha_pago: hoyIso,
});
const abonoInputRef = ref(null);
const totalPagadoRegistrado = computed(() =>
    (props.validacion.pagos || []).reduce((acc, p) => acc + (Number(p.monto) || 0), 0),
);
const saldoPendienteRegistrado = computed(() =>
    Math.max(0, Number((costoAdquisicion.value - totalPagadoRegistrado.value).toFixed(2))),
);
const abonoExcedeSaldo = computed(() =>
    Number(abonoForm.monto || 0) > saldoPendienteRegistrado.value + 0.009,
);
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
const archivoCuv = ref(null);
const escaneandoCuv = ref(false);
const errorCuv = ref('');
const erroresValidacionCuv = ref([]);
const resultadoCuv = ref(props.validacion.resumen_auditoria?.cuv || null);
const cuvUrlActual = ref(props.validacion.cuv_url || null);
const cedulaUrlActual = ref(props.validacion.cedula_url || null);
const contratoUrlActual = ref(props.validacion.contrato_url || null);
const mostrarModalConstancia = ref(false);
const archivoCedula = ref(null);
const escaneandoCedula = ref(false);
const errorCedula = ref('');
const generandoConstancia = ref(false);
const errorConstancia = ref('');
const mensajeConstancia = ref('');
const mensajeAuditoriaEmpresa = ref('');
const datosCedula = reactive({
    nombre_completo: props.validacion.datos_cedula_cliente?.nombre_completo || '',
    cedula: props.validacion.datos_cedula_cliente?.cedula || '',
    estado_civil: props.validacion.datos_cedula_cliente?.estado_civil || '',
    es_casado: Boolean(props.validacion.datos_cedula_cliente?.es_casado),
    nombre_conyuge: props.validacion.datos_cedula_cliente?.nombre_conyuge || '',
    cedula_conyuge: props.validacion.datos_cedula_cliente?.cedula_conyuge || '',
});
const estadoVisual = computed(() => {
    if (props.validacion.estado_revision === 'aprobada' && !contratoUrlActual.value) {
        return 'pendiente_constancia';
    }

    return props.validacion.estado_revision;
});
const puedeReenviar = computed(() => form.comentario_reenvio.trim().length > 0);
const tieneResumenWeb = computed(() => Boolean(
    props.validacion.resumen_auditoria
    && (
        props.validacion.resumen_auditoria.multas
        || props.validacion.resumen_auditoria.sri
        || props.validacion.resumen_auditoria.fiscalia
        || props.validacion.resumen_auditoria.propietarios
        || props.validacion.resumen_auditoria.amt
        || props.validacion.resumen_auditoria.cte
        || props.validacion.resumen_auditoria.atm
        || props.validacion.resumen_auditoria.supa
        || props.validacion.resumen_auditoria.satje
    ),
));

const fuentesMultasDirectas = computed(() => {
    const r = props.validacion.resumen_auditoria;
    if (!r) {
        return [];
    }

    return [
        { key: 'amt', titulo: 'AMT (Quito)', data: r.amt },
        { key: 'cte', titulo: 'CTE (Comisión de Tránsito)', data: r.cte },
        { key: 'atm', titulo: 'ATM (Guayaquil)', data: r.atm },
    ].filter((item) => Boolean(item.data));
});

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
    pendiente_comprobante: 'Pendiente subir comprobante de pago',
    pendiente_constancia: 'Pendiente generar constancia',
    aprobada: 'Aprobado',
    rechazada: 'Rechazada',
    registrada: 'Registrada en inventario',
}[estado] || estado);

const estadoClases = (estado) => ({
    pendiente: 'bg-amber-100 text-amber-900',
    pendiente_comprobante: 'bg-yellow-100 text-yellow-900',
    pendiente_constancia: 'bg-indigo-100 text-indigo-900',
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

const errorTipoContratoLocal = ref('');

const decidir = (decision) => {
    if (!form.id_tipo_contrato) {
        errorTipoContratoLocal.value = 'Debes seleccionar un Tipo de Contrato antes de aprobar o rechazar.';
        return;
    }
    errorTipoContratoLocal.value = '';
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

const seleccionarCedula = (event) => {
    archivoCedula.value = event.target.files[0] || null;
    errorCedula.value = '';
};

const escanearCedulaConGemini = async () => {
    if (!archivoCedula.value) {
        errorCedula.value = 'Selecciona primero una foto o PDF de la cédula del cliente.';
        return;
    }

    escaneandoCedula.value = true;
    errorCedula.value = '';

    const formData = new FormData();
    formData.append('documento_cedula', archivoCedula.value);

    try {
        const res = await axios.post(
            route('validacion-documentos.escanear.cedula', props.validacion.id),
            formData,
            { headers: { 'Content-Type': 'multipart/form-data' } },
        );

        if (res.data?.success) {
            const d = res.data.datos_cedula_cliente || {};
            datosCedula.nombre_completo = d.nombre_completo || '';
            datosCedula.cedula = d.cedula || '';
            datosCedula.estado_civil = d.estado_civil || '';
            datosCedula.es_casado = Boolean(d.es_casado);
            datosCedula.nombre_conyuge = d.nombre_conyuge || '';
            if (d.cedula_conyuge !== undefined) {
                datosCedula.cedula_conyuge = d.cedula_conyuge || '';
            }
            if (res.data.cedula_url) {
                cedulaUrlActual.value = res.data.cedula_url;
            }
        } else {
            errorCedula.value = res.data?.message || 'No se pudo procesar la cédula con Gemini.';
        }
    } catch (error) {
        const errores = Object.values(error.response?.data?.errors || {}).flat();
        errorCedula.value = errores[0] || error.response?.data?.message || 'Error al escanear la cédula.';
    } finally {
        escaneandoCedula.value = false;
    }
};

const onAuditoriaEmpresaExitosa = () => {
    mensajeAuditoriaEmpresa.value = 'Matrícula escaneada y comparada correctamente. Los datos del expediente fueron actualizados.';
    router.reload({
        only: ['validacion'],
        preserveScroll: true,
    });
};

const generarYDescargarConstancia = async () => {
    generandoConstancia.value = true;
    errorConstancia.value = '';
    mensajeConstancia.value = '';

    try {
        const res = await axios.post(
            route('validacion-documentos.generar.constancia', props.validacion.id),
            {
                nombre_completo: datosCedula.nombre_completo,
                cedula: datosCedula.cedula,
                estado_civil: datosCedula.estado_civil,
                es_casado: datosCedula.es_casado,
                nombre_conyuge: datosCedula.es_casado ? datosCedula.nombre_conyuge : '',
                cedula_conyuge: datosCedula.es_casado ? datosCedula.cedula_conyuge : '',
            },
        );

        if (res.data?.success && res.data.contrato_url) {
            contratoUrlActual.value = res.data.contrato_url;
            mensajeConstancia.value = 'Constancia generada correctamente. El estado ahora es Aprobado.';
            mostrarModalConstancia.value = false;

            const enlace = document.createElement('a');
            enlace.href = res.data.contrato_url;
            enlace.setAttribute('download', '');
            document.body.appendChild(enlace);
            enlace.click();
            document.body.removeChild(enlace);
        } else {
            errorConstancia.value = res.data?.message || 'No se pudo generar el contrato.';
        }
    } catch (error) {
        const errores = Object.values(error.response?.data?.errors || {}).flat();
        errorConstancia.value = errores[0] || error.response?.data?.message || 'Error al generar la constancia.';
    } finally {
        generandoConstancia.value = false;
    }
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
                            v-if="validacion.documento_url"
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
                        <a
                            v-if="validacion.comprobante_url"
                            :href="validacion.comprobante_url"
                            class="inline-flex rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-600"
                        >
                            Descargar comprobante
                        </a>
                        <a
                            v-if="cedulaUrlActual"
                            :href="cedulaUrlActual"
                            class="inline-flex rounded-md bg-slate-700 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-600"
                        >
                            Descargar cédula
                        </a>
                        <a
                            v-if="contratoUrlActual"
                            :href="contratoUrlActual"
                            class="inline-flex rounded-md bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-600"
                        >
                            Descargar contrato (.docx)
                        </a>
                    </div>
                </section>

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
                                                @click="mostrarModalConstancia = true"
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
                                                @click="mostrarModalConstancia = true"
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

                <!-- SECCIÓN INDEPENDIENTE: GESTIÓN DE PAGOS E INGRESO A INVENTARIO -->
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

                <!-- PANEL VISUAL DE AUDITORÍA Y COMPARACIÓN (MATRÍCULA VS FUENTES OFICIALES) -->
                <section class="rounded-lg border border-gray-700 bg-gray-900 p-5 text-white shadow-md">
                    <div class="mb-4 flex flex-col justify-between gap-2 border-b border-gray-700 pb-3 md:flex-row md:items-center">
                        <div>
                            <h3 class="text-sm font-extrabold uppercase tracking-wider text-yellow-400">
                                Auditoría Integral y Comparación Automática
                            </h3>
                            <p class="mt-0.5 text-xs text-gray-300">
                                Datos extraídos de la matrícula (IA) y fuentes oficiales guardados para verificación manual y contraste con el CUV.
                            </p>
                        </div>
                        <span
                            class="w-fit rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wider"
                            :class="validacion.alertas_criticas > 0
                                ? 'bg-red-900 text-red-200'
                                : validacion.comparacion.some((item) => item.estado !== 'coincide')
                                    ? 'bg-amber-900 text-amber-200'
                                    : 'bg-green-900 text-green-200'"
                        >
                            {{ validacion.alertas_criticas > 0
                                ? `${validacion.alertas_criticas} discrepancia(s) crítica(s)`
                                : validacion.comparacion.some((item) => item.estado !== 'coincide')
                                    ? 'Revisión parcial'
                                    : 'Datos coincidentes' }}
                        </span>
                    </div>

                    <!-- TABLA COMPARATIVA MATRÍCULA VS AXISCLOUD -->
                    <div class="overflow-hidden rounded-lg border border-gray-700 bg-gray-800">
                        <div class="border-b border-gray-700 bg-gray-900/70 px-4 py-2.5 text-xs font-bold uppercase tracking-wider text-gray-300">
                            Comparación automática: Matrícula / Ingreso vs AxisCloud
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs text-gray-200">
                                <thead class="bg-gray-900 uppercase text-gray-400">
                                    <tr>
                                        <th class="px-3 py-2.5">Campo</th>
                                        <th class="px-3 py-2.5">Matrícula / Dato ingresado</th>
                                        <th class="px-3 py-2.5">AxisCloud</th>
                                        <th class="px-3 py-2.5 text-center">Estado</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-700">
                                    <tr
                                        v-for="(item, index) in validacion.comparacion"
                                        :key="`${item.clave}-${index}`"
                                        :class="item.estado === 'diferente' && item.critico
                                            ? 'bg-red-950/50'
                                            : item.estado === 'casi_coincide' && item.critico
                                                ? 'bg-amber-950/40'
                                                : ''"
                                    >
                                        <td class="px-3 py-2.5 font-semibold">{{ item.campo }}</td>
                                        <td class="px-3 py-2.5 font-mono">{{ item.documento }}</td>
                                        <td class="px-3 py-2.5 font-mono">{{ item.sistema_web }}</td>
                                        <td class="px-3 py-2.5 text-center">
                                            <span
                                                :class="{
                                                    'bg-green-900 text-green-300': item.estado === 'coincide',
                                                    'bg-amber-700 text-yellow-200': item.estado === 'casi_coincide',
                                                    'bg-gray-700 text-gray-200': item.estado === 'no_verificable',
                                                    'bg-red-900 text-red-300': item.estado === 'diferente',
                                                    'bg-blue-900 text-blue-200': item.estado === 'informativo',
                                                }"
                                                class="rounded-full px-2.5 py-0.5 text-[11px] font-bold"
                                            >
                                                {{ item.estado === 'coincide'
                                                    ? '✓ Coincide'
                                                    : item.estado === 'casi_coincide'
                                                        ? '⚠ Casi idéntico'
                                                        : item.estado === 'diferente'
                                                            ? '✕ Diferente'
                                                            : item.estado.replaceAll('_', ' ') }}
                                            </span>
                                            <p v-if="item.nota" class="mt-1 text-[10px] text-gray-300">
                                                {{ item.nota }}
                                            </p>
                                        </td>
                                    </tr>
                                    <tr v-if="validacion.comparacion.length === 0">
                                        <td colspan="4" class="px-5 py-6 text-center text-gray-400">
                                            No hay campos comparables para mostrar.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- TARJETAS FINANCIERAS Y LEGALES GUARDADAS EN RESUMEN DE AUDITORÍA -->
                    <div v-if="tieneResumenWeb" class="mt-5 space-y-4">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <!-- 1. Multas Autority -->
                            <div
                                class="rounded-lg border p-4"
                                :class="!validacion.resumen_auditoria.multas?.ok
                                    ? 'border-amber-500 bg-amber-950/40'
                                    : validacion.resumen_auditoria.multas?.tiene_multas
                                        ? 'border-red-500 bg-red-950/50'
                                        : 'border-green-600 bg-green-950/40'"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <div>
                                        <h5
                                            class="text-xs font-extrabold uppercase tracking-wider"
                                            :class="!validacion.resumen_auditoria.multas?.ok
                                                ? 'text-amber-400'
                                                : validacion.resumen_auditoria.multas?.tiene_multas
                                                    ? 'text-red-400'
                                                    : 'text-green-400'"
                                        >
                                            {{ !validacion.resumen_auditoria.multas?.ok
                                                ? '⚠️ Autority no respondió (Multas no verificadas)'
                                                : validacion.resumen_auditoria.multas?.tiene_multas
                                                    ? `🚨 ${validacion.resumen_auditoria.multas.cantidad} Multa(s) Pendiente(s)`
                                                    : '✅ Sin Multas de Tránsito (Autority)' }}
                                        </h5>
                                        <p class="mt-0.5 text-[11px] text-gray-300">
                                            {{ validacion.resumen_auditoria.multas?.ok && validacion.resumen_auditoria.multas?.tiene_multas && Number(validacion.resumen_auditoria.multas?.total || 0) <= 0
                                                ? 'Multa detectada con valor $0.00: es necesaria revisión manual.'
                                                : (validacion.resumen_auditoria.multas?.mensaje || 'Fuente oficial: Autority') }}
                                        </p>
                                    </div>
                                    <span
                                        v-if="!validacion.resumen_auditoria.multas?.ok"
                                        class="rounded bg-amber-900/70 px-2.5 py-1 text-xs font-bold uppercase text-amber-300"
                                    >
                                        No disponible
                                    </span>
                                    <span
                                        v-else-if="validacion.resumen_auditoria.multas?.tiene_multas && Number(validacion.resumen_auditoria.multas?.total || 0) <= 0"
                                        class="whitespace-nowrap rounded bg-amber-900/80 px-2.5 py-1 text-xs font-bold uppercase text-amber-200"
                                    >
                                        Revisión manual ($0.00)
                                    </span>
                                    <span
                                        v-else
                                        class="font-mono text-lg font-extrabold"
                                        :class="validacion.resumen_auditoria.multas?.tiene_multas ? 'text-red-400' : 'text-green-400'"
                                    >
                                        ${{ Number(validacion.resumen_auditoria.multas?.total || 0).toFixed(2) }}
                                    </span>
                                </div>
                                <ul
                                    v-if="validacion.resumen_auditoria.multas?.ok && validacion.resumen_auditoria.multas?.listado?.length"
                                    class="mt-3 max-h-40 space-y-1.5 overflow-y-auto border-t border-red-800/60 pt-2 text-[11px]"
                                >
                                    <li
                                        v-for="(multa, idx) in validacion.resumen_auditoria.multas.listado"
                                        :key="idx"
                                        class="flex justify-between gap-2 text-gray-200"
                                    >
                                        <span class="truncate">{{ multa.citacion }} · {{ multa.entidad }} · {{ multa.descripcion }}</span>
                                        <span
                                            class="whitespace-nowrap font-bold"
                                            :class="Number(multa.total || 0) <= 0 ? 'text-amber-300' : 'text-red-300'"
                                        >
                                            {{ Number(multa.total || 0) <= 0 ? 'Revisión manual ($0.00)' : `$${Number(multa.total || 0).toFixed(2)}` }}
                                        </span>
                                    </li>
                                </ul>
                            </div>

                            <!-- 2. Valores de Matrícula SRI -->
                            <div
                                class="rounded-lg border p-4"
                                :class="validacion.resumen_auditoria.sri?.tiene_deuda
                                    ? 'border-amber-500 bg-amber-950/40'
                                    : 'border-green-600 bg-green-950/30'"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <div>
                                        <h5
                                            class="text-xs font-extrabold uppercase tracking-wider"
                                            :class="validacion.resumen_auditoria.sri?.tiene_deuda ? 'text-amber-400' : 'text-green-400'"
                                        >
                                            {{ validacion.resumen_auditoria.sri?.tiene_deuda
                                                ? '⚠️ Valores Pendientes en el SRI'
                                                : '✅ Matrícula al Día en el SRI' }}
                                        </h5>
                                        <p class="mt-0.5 text-[11px] text-gray-300">
                                            Último año pagado:
                                            <strong class="text-white">{{ validacion.resumen_auditoria.sri?.ultimo_anio || 'S/N' }}</strong>
                                            <span v-if="validacion.resumen_auditoria.sri?.camv_cpn" class="ml-2 text-gray-400">
                                                · CAMV/CPN: {{ validacion.resumen_auditoria.sri.camv_cpn }}
                                            </span>
                                        </p>
                                    </div>
                                    <span
                                        class="font-mono text-lg font-extrabold"
                                        :class="validacion.resumen_auditoria.sri?.tiene_deuda ? 'text-amber-400' : 'text-green-400'"
                                    >
                                        ${{ Number(validacion.resumen_auditoria.sri?.total || 0).toFixed(2) }}
                                    </span>
                                </div>
                                <ul
                                    v-if="validacion.resumen_auditoria.sri?.rubros?.length"
                                    class="mt-3 max-h-40 space-y-1.5 overflow-y-auto border-t border-amber-800/60 pt-2 text-[11px]"
                                >
                                    <li
                                        v-for="(rubro, idx) in validacion.resumen_auditoria.sri.rubros"
                                        :key="idx"
                                        class="flex justify-between gap-2 text-gray-200"
                                    >
                                        <span>{{ rubro.concepto }} {{ rubro.periodo ? `(${rubro.periodo})` : '' }}</span>
                                        <span class="font-mono font-bold text-amber-300">
                                            ${{ Number(rubro.valor || 0).toFixed(2) }}
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- Total Deudas (Multas + SRI) -->
                        <div class="flex flex-col items-start justify-between gap-2 rounded-md border border-gray-700 bg-gray-800 px-4 py-2.5 text-xs sm:flex-row sm:items-center">
                            <div>
                                <span class="font-bold uppercase text-yellow-400">Total Deudas Detectadas (Multas + SRI):</span>
                                <span
                                    class="ml-2 font-mono text-sm font-extrabold"
                                    :class="Number(validacion.resumen_auditoria.total_deudas || 0) > 0 ? 'text-red-400' : 'text-green-400'"
                                >
                                    ${{ Number(validacion.resumen_auditoria.total_deudas || 0).toFixed(2) }}
                                </span>
                            </div>
                            <span class="text-[11px] text-gray-400">
                                {{ Number(validacion.resumen_auditoria.total_deudas || 0) > 0
                                    ? 'Verificar retención o descuento antes de aprobar.'
                                    : 'Sin valores pendientes en Autority ni SRI.' }}
                            </span>
                        </div>

                        <!-- Comparación directa por entidad (AMT · CTE · ATM) -->
                        <div v-if="fuentesMultasDirectas.length">
                            <p class="mb-2 text-[11px] font-bold uppercase tracking-wider text-gray-400">
                                Comparación directa por entidad (AMT · CTE · ATM)
                            </p>
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                <div
                                    v-for="fuente in fuentesMultasDirectas"
                                    :key="fuente.key"
                                    class="rounded-lg border p-4"
                                    :class="!fuente.data?.ok
                                        ? 'border-amber-500 bg-amber-950/40'
                                        : fuente.data?.tiene_multas
                                            ? 'border-red-500 bg-red-950/50'
                                            : 'border-green-600 bg-green-950/40'"
                                >
                                    <div class="flex items-start justify-between gap-2">
                                        <div>
                                            <h5
                                                class="text-xs font-extrabold uppercase tracking-wider"
                                                :class="!fuente.data?.ok
                                                    ? 'text-amber-400'
                                                    : fuente.data?.tiene_multas
                                                        ? 'text-red-400'
                                                        : 'text-green-400'"
                                            >
                                                {{ !fuente.data?.ok
                                                    ? `⚠️ ${fuente.titulo} no disponible`
                                                    : fuente.data?.tiene_multas
                                                        ? `🚨 ${fuente.data.cantidad} Multa(s) (${fuente.titulo})`
                                                        : `✅ Sin Multas (${fuente.titulo})` }}
                                            </h5>
                                            <p class="mt-0.5 text-[11px] text-gray-300">
                                                {{ !fuente.data?.ok
                                                    ? (fuente.data?.error || fuente.data?.mensaje || 'No se pudo consultar esta entidad.')
                                                    : fuente.data?.tiene_multas && Number(fuente.data?.total || 0) <= 0
                                                        ? 'Multa detectada con valor $0.00: es necesaria revisión manual.'
                                                        : (fuente.data?.mensaje || `Fuente directa: ${fuente.titulo}`) }}
                                            </p>
                                        </div>
                                        <span
                                            v-if="!fuente.data?.ok"
                                            class="shrink-0 rounded bg-amber-900/70 px-2.5 py-1 text-xs font-bold uppercase text-amber-300"
                                        >
                                            No disponible
                                        </span>
                                        <span
                                            v-else-if="fuente.data?.tiene_multas && Number(fuente.data?.total || 0) <= 0"
                                            class="shrink-0 rounded bg-amber-900/80 px-2.5 py-1 text-xs font-bold uppercase text-amber-200"
                                        >
                                            Revisión manual ($0.00)
                                        </span>
                                        <span
                                            v-else
                                            class="shrink-0 font-mono text-lg font-extrabold"
                                            :class="fuente.data?.tiene_multas ? 'text-red-400' : 'text-green-400'"
                                        >
                                            ${{ Number(fuente.data?.total || 0).toFixed(2) }}
                                        </span>
                                    </div>
                                    <ul
                                        v-if="fuente.data?.ok && fuente.data?.tiene_multas && fuente.data?.listado?.length"
                                        class="mt-3 max-h-36 space-y-1.5 overflow-y-auto border-t border-red-800/60 pt-2 text-[11px]"
                                    >
                                        <li
                                            v-for="(m, idx) in fuente.data.listado"
                                            :key="idx"
                                            class="flex justify-between gap-2 text-gray-200"
                                        >
                                            <span class="truncate">{{ m.fecha ? `${m.fecha} · ` : '' }}{{ m.citacion ? `[${m.citacion}] ` : '' }}{{ m.descripcion }}</span>
                                            <span
                                                class="whitespace-nowrap font-bold"
                                                :class="Number(m.total || 0) <= 0 ? 'text-amber-300' : 'text-red-300'"
                                            >
                                                {{ Number(m.total || 0) <= 0 ? 'Revisión manual ($0.00)' : `$${Number(m.total || 0).toFixed(2)}` }}
                                            </span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Traspasos SRI + Fiscalía por Placa -->
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div v-if="validacion.resumen_auditoria.propietarios" class="rounded-lg border border-gray-700 bg-gray-800 p-4">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <h5 class="text-xs font-extrabold uppercase tracking-wider text-yellow-400">
                                            👥 Historial de Propietarios (SRI)
                                        </h5>
                                        <p class="mt-0.5 text-[11px] text-gray-300">
                                            {{ validacion.resumen_auditoria.propietarios?.mensaje }}
                                        </p>
                                    </div>
                                    <span
                                        class="rounded px-2.5 py-1 font-mono text-xs font-bold"
                                        :class="(validacion.resumen_auditoria.propietarios?.total_traspasos || 0) >= 3 ? 'bg-amber-600 text-white' : 'bg-blue-900 text-blue-200'"
                                    >
                                        {{ validacion.resumen_auditoria.propietarios?.total_traspasos || 0 }} Traspaso(s)
                                    </span>
                                </div>
                            </div>

                            <div
                                v-if="validacion.resumen_auditoria.fiscalia"
                                class="rounded-lg border p-4"
                                :class="validacion.resumen_auditoria.fiscalia?.ok === false
                                    ? 'border-amber-500 bg-amber-950/40'
                                    : validacion.resumen_auditoria.fiscalia?.tiene_denuncias
                                        ? 'border-red-600 bg-red-950/70'
                                        : 'border-green-600 bg-green-950/30'"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <div>
                                        <h5
                                            class="text-xs font-extrabold uppercase tracking-wider"
                                            :class="validacion.resumen_auditoria.fiscalia?.ok === false
                                                ? 'text-amber-400'
                                                : validacion.resumen_auditoria.fiscalia?.tiene_denuncias
                                                    ? 'text-red-300'
                                                    : 'text-green-400'"
                                        >
                                            {{ validacion.resumen_auditoria.fiscalia?.ok === false
                                                ? '⚠️ Fiscalía por Placa no disponible'
                                                : validacion.resumen_auditoria.fiscalia?.tiene_denuncias
                                                    ? `🚨 ${validacion.resumen_auditoria.fiscalia.cantidad} Denuncia(s) por Placa`
                                                    : '✅ Sin Denuncias en Fiscalía (Placa)' }}
                                        </h5>
                                    </div>
                                    <span
                                        class="rounded px-2.5 py-1 font-mono text-xs font-bold"
                                        :class="validacion.resumen_auditoria.fiscalia?.ok === false
                                            ? 'bg-amber-900/70 text-amber-300'
                                            : validacion.resumen_auditoria.fiscalia?.tiene_denuncias
                                                ? 'bg-red-600 text-white'
                                                : 'bg-green-800 text-green-200'"
                                    >
                                        {{ validacion.resumen_auditoria.fiscalia?.ok === false
                                            ? 'NO VERIFICADA'
                                            : `${validacion.resumen_auditoria.fiscalia?.cantidad || 0} registro(s)` }}
                                    </span>
                                </div>
                                <ul
                                    v-if="validacion.resumen_auditoria.fiscalia?.listado?.length"
                                    class="mt-3 space-y-2 border-t border-red-800 pt-2 text-xs text-red-100"
                                >
                                    <li
                                        v-for="(denuncia, index) in validacion.resumen_auditoria.fiscalia.listado"
                                        :key="index"
                                        class="rounded bg-red-900/60 p-2"
                                    >
                                        {{ denuncia.noticia_delito }} · {{ denuncia.fecha }} · {{ denuncia.localidad }} · {{ denuncia.descripcion }}
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- Fiscalía por Cédula del Propietario -->
                        <div
                            v-if="validacion.resumen_auditoria.fiscalia_cedula"
                            class="rounded-lg border p-4"
                            :class="!validacion.resumen_auditoria.fiscalia_cedula.ok
                                ? 'border-amber-500 bg-amber-950/40'
                                : validacion.resumen_auditoria.fiscalia_cedula.tiene_denuncias
                                    ? 'border-red-600 bg-red-950'
                                    : 'border-green-600 bg-green-950/30'"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <h5
                                        class="text-xs font-extrabold uppercase tracking-wider"
                                        :class="!validacion.resumen_auditoria.fiscalia_cedula.ok
                                            ? 'text-amber-400'
                                            : validacion.resumen_auditoria.fiscalia_cedula.tiene_denuncias
                                                ? 'text-red-300'
                                                : 'text-green-400'"
                                    >
                                        {{ !validacion.resumen_auditoria.fiscalia_cedula.ok
                                            ? '⚠️ Fiscalía no pudo verificar la cédula'
                                            : validacion.resumen_auditoria.fiscalia_cedula.tiene_denuncias
                                                ? `🚨 ¡ALERTA! Propietario figura como SOSPECHOSO/DENUNCIADO en ${validacion.resumen_auditoria.fiscalia_cedula.cantidad} caso(s)`
                                                : '✅ Propietario sin denuncias como Sospechoso o Denunciado' }}
                                    </h5>
                                    <p class="mt-0.5 text-[11px] text-gray-300">
                                        Cédula auditada:
                                        <span class="font-mono font-bold text-white">{{ validacion.resumen_auditoria.fiscalia_cedula.cedula || 'No disponible' }}</span>
                                    </p>
                                </div>
                                <span
                                    class="shrink-0 rounded px-2.5 py-1 font-mono text-xs font-bold"
                                    :class="!validacion.resumen_auditoria.fiscalia_cedula.ok
                                        ? 'bg-amber-900/70 text-amber-300'
                                        : validacion.resumen_auditoria.fiscalia_cedula.tiene_denuncias
                                            ? 'bg-red-600 text-white'
                                            : 'bg-green-800 text-green-200'"
                                >
                                    {{ !validacion.resumen_auditoria.fiscalia_cedula.ok
                                        ? 'NO VERIFICADA'
                                        : validacion.resumen_auditoria.fiscalia_cedula.tiene_denuncias
                                            ? 'SOSPECHOSO / DENUNCIADO'
                                            : 'LIMPIO' }}
                                </span>
                            </div>
                            <ul
                                v-if="validacion.resumen_auditoria.fiscalia_cedula.listado?.length"
                                class="mt-3 max-h-40 space-y-2 overflow-y-auto border-t border-red-800 pt-2 text-xs text-red-100"
                            >
                                <li
                                    v-for="(denuncia, index) in validacion.resumen_auditoria.fiscalia_cedula.listado"
                                    :key="index"
                                    class="rounded bg-red-900/60 p-2"
                                >
                                    {{ denuncia.noticia_delito }} · {{ denuncia.fecha }} · {{ denuncia.rol }} · {{ denuncia.localidad }} · {{ denuncia.descripcion }}
                                </li>
                            </ul>
                        </div>

                        <!-- Pensiones Alimenticias (SUPA) -->
                        <div
                            v-if="validacion.resumen_auditoria.supa"
                            class="rounded-lg border p-4"
                            :class="!validacion.resumen_auditoria.supa.consultado || validacion.resumen_auditoria.supa.ok === false
                                ? 'border-amber-500 bg-amber-950/40'
                                : validacion.resumen_auditoria.supa.tiene_alerta
                                    ? 'border-red-600 bg-red-950'
                                    : 'border-green-600 bg-green-950/30'"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <h5
                                        class="text-xs font-extrabold uppercase tracking-wider"
                                        :class="!validacion.resumen_auditoria.supa.consultado || validacion.resumen_auditoria.supa.ok === false
                                            ? 'text-amber-400'
                                            : validacion.resumen_auditoria.supa.tiene_alerta
                                                ? 'text-red-300'
                                                : 'text-green-400'"
                                    >
                                        {{ !validacion.resumen_auditoria.supa.consultado || validacion.resumen_auditoria.supa.ok === false
                                            ? '⚠️ SUPA no verificada'
                                            : validacion.resumen_auditoria.supa.tiene_alerta
                                                ? `🚨 ¡ALERTA SUPA! ${validacion.resumen_auditoria.supa.pagos_pendientes} pago(s) pendiente(s) de pensión alimenticia`
                                                : '✅ Pensiones Alimenticias al Día (SUPA)' }}
                                    </h5>
                                    <p class="mt-0.5 text-[11px] text-gray-300">
                                        {{ validacion.resumen_auditoria.supa.mensaje }}
                                    </p>
                                </div>
                                <span
                                    class="shrink-0 rounded px-2.5 py-1 font-mono text-xs font-bold"
                                    :class="!validacion.resumen_auditoria.supa.consultado || validacion.resumen_auditoria.supa.ok === false
                                        ? 'bg-amber-900/70 text-amber-300'
                                        : validacion.resumen_auditoria.supa.tiene_alerta
                                            ? 'bg-red-600 text-white'
                                            : 'bg-green-800 text-green-200'"
                                >
                                    {{ !validacion.resumen_auditoria.supa.consultado || validacion.resumen_auditoria.supa.ok === false
                                        ? 'NO VERIFICADA'
                                        : validacion.resumen_auditoria.supa.tiene_alerta
                                            ? `${validacion.resumen_auditoria.supa.pagos_pendientes} PENDIENTES`
                                            : 'SIN ALERTAS' }}
                                </span>
                            </div>
                        </div>

                        <!-- Causas Judiciales (SATJE) -->
                        <div
                            v-if="validacion.resumen_auditoria.satje"
                            class="rounded-lg border p-4"
                            :class="!validacion.resumen_auditoria.satje.consultado || validacion.resumen_auditoria.satje.ok === false
                                ? 'border-amber-500 bg-amber-950/40'
                                : validacion.resumen_auditoria.satje.tiene_causas
                                    ? 'border-red-600 bg-red-950/70'
                                    : 'border-green-600 bg-green-950/30'"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <h5
                                        class="text-xs font-extrabold uppercase tracking-wider"
                                        :class="!validacion.resumen_auditoria.satje.consultado || validacion.resumen_auditoria.satje.ok === false
                                            ? 'text-amber-400'
                                            : validacion.resumen_auditoria.satje.tiene_causas
                                                ? 'text-red-300'
                                                : 'text-green-400'"
                                    >
                                        {{ !validacion.resumen_auditoria.satje.consultado || validacion.resumen_auditoria.satje.ok === false
                                            ? '⚠️ SATJE no verificada'
                                            : validacion.resumen_auditoria.satje.tiene_causas
                                                ? `🚨 Función Judicial: ${validacion.resumen_auditoria.satje.cantidad_demandado} Causa(s) como Demandado`
                                                : '✅ Sin Causas como Demandado (SATJE)' }}
                                    </h5>
                                    <p class="mt-0.5 text-[11px] text-gray-300">
                                        {{ validacion.resumen_auditoria.satje.mensaje }}
                                    </p>
                                </div>
                                <span
                                    class="shrink-0 rounded px-2.5 py-1 font-mono text-xs font-bold"
                                    :class="!validacion.resumen_auditoria.satje.consultado || validacion.resumen_auditoria.satje.ok === false
                                        ? 'bg-amber-900/70 text-amber-300'
                                        : validacion.resumen_auditoria.satje.tiene_causas
                                            ? 'bg-red-600 text-white'
                                            : 'bg-green-800 text-green-200'"
                                >
                                    {{ !validacion.resumen_auditoria.satje.consultado || validacion.resumen_auditoria.satje.ok === false
                                        ? 'NO VERIFICADA'
                                        : validacion.resumen_auditoria.satje.tiene_causas
                                            ? `${validacion.resumen_auditoria.satje.cantidad_demandado} DEMANDADO`
                                            : 'LIMPIO' }}
                                </span>
                            </div>
                            <ul
                                v-if="validacion.resumen_auditoria.satje.ok && validacion.resumen_auditoria.satje.listado?.length"
                                class="mt-3 max-h-40 space-y-2 overflow-y-auto border-t border-gray-700 pt-2 text-xs text-gray-100"
                            >
                                <li
                                    v-for="(causa, index) in validacion.resumen_auditoria.satje.listado"
                                    :key="index"
                                    class="rounded p-2"
                                    :class="causa.rol === 'DEMANDADO' ? 'bg-red-900/60' : 'bg-gray-900/70'"
                                >
                                    Juicio {{ causa.id_juicio }} ({{ causa.rol }}) · {{ causa.fecha_ingreso ? causa.fecha_ingreso.slice(0, 10) : '' }} · {{ causa.delito_accion }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </section>

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
                            <label for="tipo-contrato-decision" class="block text-sm font-semibold text-gray-800">
                                Tipo de Contrato *
                            </label>
                            <select
                                id="tipo-contrato-decision"
                                v-model="form.id_tipo_contrato"
                                required
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:max-w-md"
                                @change="errorTipoContratoLocal = ''"
                            >
                                <option value="" disabled>Seleccione el tipo de contrato...</option>
                                <option
                                    v-for="tipo in tiposContrato"
                                    :key="tipo.id_tipo_contrato"
                                    :value="tipo.id_tipo_contrato"
                                >
                                    {{ tipo.nombre }}{{ tipo.requiere_documento ? '' : ' (No requiere documento)' }}
                                </option>
                            </select>
                            <p
                                v-if="errorTipoContratoLocal || form.errors.id_tipo_contrato"
                                role="alert"
                                class="mt-1 text-xs font-medium text-red-600"
                            >
                                {{ errorTipoContratoLocal || form.errors.id_tipo_contrato }}
                            </p>
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
                                :disabled="form.processing || !form.id_tipo_contrato"
                                class="rounded-md bg-green-700 px-5 py-2 text-sm font-semibold text-white hover:bg-green-800 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="decidir('aprobada')"
                            >
                                Aprobar documento
                            </button>
                            <button
                                type="button"
                                :disabled="form.processing || !form.id_tipo_contrato"
                                class="rounded-md bg-red-700 px-5 py-2 text-sm font-semibold text-white hover:bg-red-800 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="decidir('rechazada')"
                            >
                                Rechazar documento
                            </button>
                        </div>
                    </div>
                    <div
                        v-else-if="validacion.estado_revision === 'pendiente_comprobante'"
                        class="mt-4 space-y-3"
                    >
                        <div class="rounded-md border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-900">
                            <p class="font-semibold">Documentación aprobada · Pendiente subir comprobante de pago</p>
                            <p class="mt-1">
                                El vehículo permanece en la tabla temporal de ingreso y todavía no entra al inventario.
                                Sube el comprobante de pago en la tabla <strong>Documentación</strong> superior para completar su ingreso al inventario.
                            </p>
                        </div>
                        <div class="space-y-2 text-sm text-gray-700">
                            <p>
                                Revisado por {{ validacion.revisado_por || 'Usuario no disponible' }}
                                el {{ fechaTexto(validacion.revisado_en) }}.
                            </p>
                            <p v-if="validacion.comentario_revision" class="whitespace-pre-wrap rounded-md bg-gray-50 p-3">
                                {{ validacion.comentario_revision }}
                            </p>
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

        <Modal :show="mostrarModalConstancia" max-width="2xl" @close="mostrarModalConstancia = false">
            <div class="p-6">
                <div class="flex items-start justify-between gap-4 border-b border-gray-200 pb-4">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">
                            Generar Constancia · Contrato de Compra-Venta
                        </h3>
                        <p class="mt-1 text-xs text-gray-600">
                            Sube la cédula del vendedor para extraer sus datos con Gemini, revísalos y genera el documento Word listo para imprimir y firmar.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-md px-2 py-1 text-sm font-semibold text-gray-500 hover:bg-gray-100 hover:text-gray-800"
                        @click="mostrarModalConstancia = false"
                    >
                        ✕
                    </button>
                </div>

                <div class="mt-5 space-y-5">
                    <!-- Paso 1: Subir y escanear cédula -->
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <label for="documento-cedula-cliente" class="text-sm font-semibold text-gray-900">
                                1. Cédula escaneada del cliente (vendedor)
                            </label>
                            <a
                                v-if="cedulaUrlActual"
                                :href="cedulaUrlActual"
                                class="text-xs font-semibold text-indigo-700 hover:text-indigo-900"
                            >
                                Ver cédula guardada
                            </a>
                        </div>
                        <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center">
                            <input
                                id="documento-cedula-cliente"
                                type="file"
                                :disabled="escaneandoCedula"
                                accept="image/jpeg,image/png,image/webp,.pdf"
                                class="block w-full text-xs text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-900 file:px-3 py-2 file:text-white hover:file:bg-gray-700"
                                @change="seleccionarCedula"
                            />
                            <button
                                type="button"
                                :disabled="escaneandoCedula || !archivoCedula"
                                class="whitespace-nowrap rounded-md bg-yellow-500 px-4 py-2 text-xs font-bold text-gray-900 shadow-sm hover:bg-yellow-400 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="escanearCedulaConGemini"
                            >
                                {{ escaneandoCedula ? 'Escaneando con Gemini...' : 'Escanear cédula con IA' }}
                            </button>
                        </div>
                        <p class="mt-1 text-[11px] text-gray-500">
                            Formatos permitidos: JPG, PNG, WEBP o PDF (máximo 5 MB).
                        </p>
                        <div
                            v-if="escaneandoCedula"
                            role="progressbar"
                            class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-200"
                        >
                            <div class="h-full w-1/3 animate-pulse rounded-full bg-yellow-500" />
                        </div>
                        <p v-if="errorCedula" role="alert" class="mt-2 text-xs font-medium text-red-600">
                            {{ errorCedula }}
                        </p>
                    </div>

                    <!-- Paso 2: Datos editables del vendedor -->
                    <form class="space-y-4" @submit.prevent="generarYDescargarConstancia">
                        <p class="text-sm font-semibold text-gray-900">
                            2. Datos del vendedor para el contrato
                        </p>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label for="constancia-nombre" class="block text-xs font-medium text-gray-700">
                                    Nombre completo del vendedor *
                                </label>
                                <input
                                    id="constancia-nombre"
                                    v-model="datosCedula.nombre_completo"
                                    type="text"
                                    required
                                    maxlength="200"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    placeholder="EJ. JUAN CARLOS PÉREZ GÓMEZ"
                                />
                            </div>
                            <div>
                                <label for="constancia-cedula" class="block text-xs font-medium text-gray-700">
                                    Número de cédula *
                                </label>
                                <input
                                    id="constancia-cedula"
                                    v-model="datosCedula.cedula"
                                    type="text"
                                    required
                                    maxlength="30"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    placeholder="1801234567"
                                />
                            </div>
                            <div>
                                <label for="constancia-estado-civil" class="block text-xs font-medium text-gray-700">
                                    Estado civil
                                </label>
                                <input
                                    id="constancia-estado-civil"
                                    v-model="datosCedula.estado_civil"
                                    type="text"
                                    maxlength="60"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    placeholder="SOLTERO / CASADO"
                                />
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <input
                                id="constancia-es-casado"
                                v-model="datosCedula.es_casado"
                                type="checkbox"
                                class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                            />
                            <label for="constancia-es-casado" class="text-xs font-medium text-gray-800">
                                El vendedor es casado / unión de hecho (incluir cónyuge en el contrato)
                            </label>
                        </div>

                        <div v-if="datosCedula.es_casado" class="grid grid-cols-1 gap-4 rounded-md border border-indigo-200 bg-indigo-50/50 p-3 sm:grid-cols-2">
                            <div>
                                <label for="constancia-conyuge" class="block text-xs font-medium text-gray-700">
                                    Nombre completo del cónyuge *
                                </label>
                                <input
                                    id="constancia-conyuge"
                                    v-model="datosCedula.nombre_conyuge"
                                    type="text"
                                    :required="datosCedula.es_casado"
                                    maxlength="200"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                            </div>
                            <div>
                                <label for="constancia-ci-conyuge" class="block text-xs font-medium text-gray-700">
                                    Cédula del cónyuge (opcional)
                                </label>
                                <input
                                    id="constancia-ci-conyuge"
                                    v-model="datosCedula.cedula_conyuge"
                                    type="text"
                                    maxlength="30"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                            </div>
                        </div>

                        <!-- Resumen del vehículo -->
                        <div v-if="validacion.vehiculo_contrato" class="rounded-md border border-gray-200 bg-gray-50 p-3 text-xs text-gray-700">
                            <p class="font-semibold text-gray-900">Datos del vehículo que se insertarán en el contrato</p>
                            <div class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4">
                                <span><strong>Placa:</strong> {{ validacion.vehiculo_contrato.id_vehiculo }}</span>
                                <span><strong>Marca:</strong> {{ validacion.vehiculo_contrato.marca || 'N/D' }}</span>
                                <span><strong>Modelo:</strong> {{ validacion.vehiculo_contrato.modelo || 'N/D' }}</span>
                                <span><strong>Año:</strong> {{ validacion.vehiculo_contrato.anio || 'N/D' }}</span>
                                <span><strong>Color:</strong> {{ validacion.vehiculo_contrato.color || 'N/D' }}</span>
                                <span><strong>Chasis:</strong> {{ validacion.vehiculo_contrato.chasis || 'N/D' }}</span>
                                <span><strong>Motor:</strong> {{ validacion.vehiculo_contrato.motor || 'N/D' }}</span>
                                <span><strong>Valor:</strong> ${{ Number(validacion.vehiculo_contrato.costo_adquisicion || 0).toFixed(2) }}</span>
                            </div>
                        </div>

                        <p v-if="errorConstancia" role="alert" class="rounded-md border border-red-200 bg-red-50 p-3 text-xs font-medium text-red-700">
                            {{ errorConstancia }}
                        </p>

                        <div class="flex flex-col-reverse justify-end gap-3 border-t border-gray-200 pt-4 sm:flex-row">
                            <button
                                type="button"
                                class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                @click="mostrarModalConstancia = false"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                :disabled="generandoConstancia || !datosCedula.nombre_completo.trim() || !datosCedula.cedula.trim()"
                                class="rounded-md bg-gray-900 px-5 py-2 text-sm font-semibold text-yellow-400 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {{ generandoConstancia ? 'Generando contrato...' : 'Generar y descargar contrato (.docx)' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
