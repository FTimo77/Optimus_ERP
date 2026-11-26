<script setup>
import Modal from '@/Components/Modal.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    venta: {
        type: Object,
        required: true,
    },
    tasasInteres: {
        type: Array,
        default: () => [],
    },
    success: {
        type: String,
        default: null,
    },
});

const hoyIso = new Date().toISOString().slice(0, 10);

const formatearMoneda = (valor) =>
    `$${Number(valor || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const fechaTexto = (fecha) => (
    fecha ? new Date(fecha).toLocaleString('es-EC') : 'No disponible'
);

const cedulaUrlActual = ref(props.venta.cedula_url || null);
const constanciaUrlActual = ref(props.venta.constancia_url || null);
const pagareUrlActual = ref(props.venta.credito?.pagare_url || null);
const mostrarModalFaltantes = ref(false);
const modoModalFaltantes = ref('constancia'); // 'pagare' | 'constancia' | 'editar'
const mostrarTodosLosCamposModal = ref(false);
const guardandoComprador = ref(false);
const errorModalFaltantes = ref('');
const mensajeConstancia = ref('');
const paso1CedulaRef = ref(null);

watch(
    () => props.venta.credito?.pagare_url,
    (nuevaUrl) => {
        if (nuevaUrl) {
            pagareUrlActual.value = nuevaUrl;
        }
    },
);

// 1. Formulario de Tipo de Operación y Precio Acordado
const operacionForm = useForm({
    tipo_operacion: props.venta.tipo_operacion || 'venta',
    precio_acordado: props.venta.precio_acordado ? String(props.venta.precio_acordado) : '',
    motivo_descuento: props.venta.motivo_descuento || '',
});

const decisionDescuentoForm = useForm({
    decision: '',
    comentario_aprobacion_descuento: '',
});

watch(
    () => [props.venta.tipo_operacion, props.venta.precio_acordado, props.venta.motivo_descuento],
    () => {
        operacionForm.tipo_operacion = props.venta.tipo_operacion || 'venta';
        operacionForm.precio_acordado = props.venta.precio_acordado ? String(props.venta.precio_acordado) : '';
        operacionForm.motivo_descuento = props.venta.motivo_descuento || '';
    },
);

const precioLista = computed(() => Number(props.venta.precio_lista ?? props.venta.precio_base ?? 0));
const precioFinal = computed(() => {
    const valor = Number(props.venta.precio_final || 0);
    return valor > 0 ? valor : 0;
});
const tienePrecioFinal = computed(() => precioFinal.value > 0);
const precioMinimoAutorizado = computed(() => (tienePrecioFinal.value ? precioFinal.value : precioLista.value));
const etiquetaPrecioMinimo = computed(() => (tienePrecioFinal.value ? 'Precio Final mínimo' : 'Precio de Lista'));
const precioAcordadoNum = computed(() => Number(operacionForm.precio_acordado || 0));
const diferenciaDescuento = computed(() =>
    Math.max(0, Number((precioMinimoAutorizado.value - precioAcordadoNum.value).toFixed(2))),
);

const esPrecioMenorAlMinimo = computed(() =>
    precioMinimoAutorizado.value > 0
    && precioAcordadoNum.value > 0
    && precioAcordadoNum.value < precioMinimoAutorizado.value - 0.009,
);

const descuentoAprobadoParaMontoActual = computed(() =>
    props.venta.estado_aprobacion_descuento === 'aprobado'
    && Math.abs(Number(props.venta.precio_acordado || 0) - precioAcordadoNum.value) < 0.01,
);

const requiereEnviarAprobacion = computed(() =>
    esPrecioMenorAlMinimo.value && !descuentoAprobadoParaMontoActual.value,
);

const precioAutorizadoParaPago = computed(() => {
    const acordadoGuardado = Number(props.venta.precio_acordado || 0);
    if (acordadoGuardado <= 0) {
        return false;
    }
    if (precioMinimoAutorizado.value > 0 && acordadoGuardado < precioMinimoAutorizado.value - 0.009) {
        return props.venta.estado_aprobacion_descuento === 'aprobado';
    }
    return true;
});

const guardarOperacionYPrecio = () => {
    if (requiereEnviarAprobacion.value) {
        return;
    }

    operacionForm.patch(route('mis-ventas.operacion.update', props.venta.id_venta), {
        preserveScroll: true,
    });
};

const enviarDescuentoAAprobacion = () => {
    if (!esPrecioMenorAlMinimo.value) {
        return;
    }

    operacionForm.post(route('mis-ventas.descuento.solicitar', props.venta.id_venta), {
        preserveScroll: true,
    });
};

const decidirSolicitudDescuento = (decision) => {
    decisionDescuentoForm.decision = decision;
    decisionDescuentoForm.patch(route('mis-ventas.descuento.decidir', props.venta.id_venta), {
        preserveScroll: true,
    });
};

// 2. Formulario de Pagos y Comprobantes (Almacenamiento privado local)
const sumarMesesSinDesborde = (fechaIso, mesesASumar) => {
    if (!fechaIso || !/^\d{4}-\d{2}-\d{2}$/.test(fechaIso)) {
        return '';
    }
    const [anio, mes, dia] = fechaIso.split('-').map(Number);
    const totalMeses = (anio * 12) + (mes - 1) + mesesASumar;
    const nuevoAnio = Math.floor(totalMeses / 12);
    const nuevoMes = (totalMeses % 12) + 1;
    const ultimoDiaMes = new Date(nuevoAnio, nuevoMes, 0).getDate();
    const diaAjustado = Math.min(dia, ultimoDiaMes);

    return `${String(nuevoAnio).padStart(4, '0')}-${String(nuevoMes).padStart(2, '0')}-${String(diaAjustado).padStart(2, '0')}`;
};

const pagoForm = useForm({
    forma_pago: 'contado',
    modalidad_credito: 'abierto',
    id_tasa_interes: '',
    monto_inicial: '',
    metodo_pago_inicial: 'transferencia',
    comprobante_inicial: null,
    cuotas_credito: [
        { monto: '', fecha_vencimiento: hoyIso },
    ],
    cuota_mensual: '',
    meses_credito: 4,
    fecha_siguiente_pago: hoyIso,
    observaciones: '',
    fecha_pago: hoyIso,
    documento_comprobante: null,
    pagos_contado: [
        {
            comprobante: null,
            monto: String(props.venta.precio_acordado || props.venta.precio_base || ''),
            metodo_pago: 'transferencia',
            numero_referencia: '',
            fecha_pago: hoyIso,
        },
    ],
    cuotas: [
        { comprobante: null, monto: '', fecha_pago: hoyIso },
    ],
});

const decisionCreditoForm = useForm({
    decision: '',
    comentario_aprobacion_credito: '',
});

const cobroCreditoForm = useForm({
    id_cuota_credito: '',
    monto_pagado: '',
    metodo_pago: 'transferencia',
    numero_referencia: '',
    fecha_pago: hoyIso,
    comprobante: null,
    observaciones: '',
});
const cobroCreditoInputRef = ref(null);

const abonoForm = useForm({
    comprobante: null,
    monto: '',
    fecha_pago: hoyIso,
});
const abonoInputRef = ref(null);

const montoObjetivoVenta = computed(() =>
    Number(props.venta.precio_acordado || props.venta.precio_base || 0),
);
const montoInicialNum = computed(() => Math.max(0, Number(pagoForm.monto_inicial || 0)));
const montoFinanciadoCredito = computed(() =>
    Math.max(0, Number((montoObjetivoVenta.value - montoInicialNum.value).toFixed(2))),
);
const sumaCuotas = computed(() =>
    pagoForm.cuotas.reduce((acc, item) => acc + (Number(item.monto) || 0), 0),
);
const excedeMontoObjetivo = computed(() =>
    montoObjetivoVenta.value > 0 && sumaCuotas.value > montoObjetivoVenta.value + 0.009,
);
const puedeAgregarCuota = computed(() =>
    montoObjetivoVenta.value <= 0 || sumaCuotas.value < montoObjetivoVenta.value - 0.009,
);
const saldoRestanteCuotas = computed(() =>
    Math.max(0, montoObjetivoVenta.value - sumaCuotas.value),
);

// Cálculos de Crédito abierto / personalizado (cuotas manuales sobre el saldo financiado)
const sumaCuotasCreditoAbierto = computed(() =>
    Number(
        pagoForm.cuotas_credito
            .reduce((acc, item) => acc + (Number(item.monto) || 0), 0)
            .toFixed(2),
    ),
);
const saldoRestanteCreditoAbierto = computed(() =>
    Number((montoFinanciadoCredito.value - sumaCuotasCreditoAbierto.value).toFixed(2)),
);
const creditoAbiertoCoincideTotal = computed(() =>
    montoFinanciadoCredito.value > 0
    && Math.abs(sumaCuotasCreditoAbierto.value - montoFinanciadoCredito.value) < 0.01,
);
const creditoAbiertoExcedeTotal = computed(() =>
    montoFinanciadoCredito.value > 0
    && sumaCuotasCreditoAbierto.value > montoFinanciadoCredito.value + 0.009,
);

const agregarCuotaCredito = () => {
    const ultimaFecha = pagoForm.cuotas_credito[pagoForm.cuotas_credito.length - 1]?.fecha_vencimiento || hoyIso;
    const siguienteFecha = sumarMesesSinDesborde(ultimaFecha, 1) || hoyIso;
    pagoForm.cuotas_credito.push({
        monto: saldoRestanteCreditoAbierto.value > 0 ? String(saldoRestanteCreditoAbierto.value) : '',
        fecha_vencimiento: siguienteFecha,
    });
};

const eliminarCuotaCredito = (index) => {
    if (pagoForm.cuotas_credito.length <= 1) return;
    pagoForm.cuotas_credito.splice(index, 1);
};

// Cálculos de Crédito > 3 meses (tasa fija 1.7% mensual meses 1-12 y 1.5% desde mes 13 sobre monto financiado)
const tasaSeleccionadaObj = computed(() => props.tasasInteres[0] || null);
const tasaBaseMensualPct = computed(() => {
    const val = Number(tasaSeleccionadaObj.value?.tasa_mensual || 0);
    return val > 0 ? val : 1.7;
});
const tasaMes13MensualPct = computed(() => {
    const val = tasaSeleccionadaObj.value?.tasa_mensual_desde_mes_13;
    return val !== null && val !== undefined && Number(val) > 0 ? Number(val) : 1.5;
});
const mesesCreditoNum = computed(() => Number.parseInt(pagoForm.meses_credito, 10) || 0);
const tablaCuotasMayor6Meses = computed(() => {
    const meses = mesesCreditoNum.value;
    const financiado = montoFinanciadoCredito.value;
    if (financiado <= 0 || meses <= 3 || !pagoForm.fecha_siguiente_pago) {
        return [];
    }
    const capitalBase = Number((financiado / meses).toFixed(2));
    const filas = [];
    for (let i = 0; i < meses; i += 1) {
        const numero = i + 1;
        const capitalCuota = i === meses - 1
            ? Math.max(0, Number((financiado - (capitalBase * (meses - 1))).toFixed(2)))
            : capitalBase;
        const tasaAplicada = numero <= 12 ? tasaBaseMensualPct.value : tasaMes13MensualPct.value;
        const interesCuota = Number((financiado * (tasaAplicada / 100)).toFixed(2));
        const monto = Number((capitalCuota + interesCuota).toFixed(2));
        filas.push({
            numero,
            tasa_aplicada: tasaAplicada,
            monto_capital: capitalCuota,
            monto_interes: interesCuota,
            monto,
            fecha_vencimiento: sumarMesesSinDesborde(pagoForm.fecha_siguiente_pago, i),
        });
    }
    return filas;
});
const totalInteresMayor6Meses = computed(() =>
    Number(
        tablaCuotasMayor6Meses.value
            .reduce((acc, item) => acc + item.monto_interes, 0)
            .toFixed(2),
    ),
);
const totalProyectadoMayor6Meses = computed(() =>
    Number(
        tablaCuotasMayor6Meses.value
            .reduce((acc, item) => acc + item.monto, 0)
            .toFixed(2),
    ),
);
const cuotaMensualReferencia = computed(() =>
    tablaCuotasMayor6Meses.value[0]?.monto || 0,
);

const totalPlanCreditoRegistrado = computed(() =>
    Number(
        (props.venta.plan_cuotas_credito || [])
            .reduce((acc, item) => acc + (Number(item.monto) || 0), 0)
            .toFixed(2),
    ),
);

const mostrarFormularioPago = computed(() =>
    !props.venta.forma_pago || props.venta.estado_aprobacion_credito === 'rechazado',
);

const totalPagadoRegistrado = computed(() =>
    (props.venta.pagos || []).reduce((acc, p) => acc + (Number(p.monto) || 0), 0),
);
const saldoPendienteRegistrado = computed(() =>
    Math.max(0, Number((montoObjetivoVenta.value - totalPagadoRegistrado.value).toFixed(2))),
);
const abonoExcedeSaldo = computed(() =>
    Number(abonoForm.monto || 0) > saldoPendienteRegistrado.value + 0.009,
);

const agregarCuota = () => {
    if (!puedeAgregarCuota.value) return;
    pagoForm.cuotas.push({ comprobante: null, monto: '', fecha_pago: hoyIso });
};

const eliminarCuota = (index) => {
    if (pagoForm.cuotas.length <= 1) return;
    pagoForm.cuotas.splice(index, 1);
};

const sumaPagosContado = computed(() =>
    Number(
        pagoForm.pagos_contado
            .reduce((acc, item) => acc + (Number(item.monto) || 0), 0)
            .toFixed(2),
    ),
);
const excedeMontoContado = computed(() =>
    montoObjetivoVenta.value > 0 && sumaPagosContado.value > montoObjetivoVenta.value + 0.009,
);
const saldoRestanteContado = computed(() =>
    Math.max(0, Number((montoObjetivoVenta.value - sumaPagosContado.value).toFixed(2))),
);
const agregarPagoContado = () => {
    pagoForm.pagos_contado.push({
        comprobante: null,
        monto: saldoRestanteContado.value > 0 ? String(saldoRestanteContado.value) : '',
        metodo_pago: 'transferencia',
        numero_referencia: '',
        fecha_pago: hoyIso,
    });
};
const eliminarPagoContado = (index) => {
    if (pagoForm.pagos_contado.length <= 1) return;
    pagoForm.pagos_contado.splice(index, 1);
};

const tieneCedulaComprador = computed(() => Boolean(cedulaUrlActual.value));

const puedeEnviarFormaPago = computed(() => {
    if (pagoForm.processing || !precioAutorizadoParaPago.value) {
        return false;
    }
    if (pagoForm.forma_pago === 'credito') {
        if (!tieneCedulaComprador.value) {
            return false;
        }
        if (montoInicialNum.value >= montoObjetivoVenta.value - 0.009) {
            return false;
        }
        if (pagoForm.modalidad_credito === 'mayor_6_meses') {
            return montoFinanciadoCredito.value > 0
                && mesesCreditoNum.value > 3
                && Boolean(pagoForm.fecha_siguiente_pago)
                && pagoForm.fecha_siguiente_pago >= hoyIso;
        }
        return pagoForm.cuotas_credito.length > 0
            && creditoAbiertoCoincideTotal.value
            && pagoForm.cuotas_credito.every((c) => Number(c.monto) > 0 && Boolean(c.fecha_vencimiento));
    }
    if (pagoForm.forma_pago === 'contado') {
        if (excedeMontoContado.value || pagoForm.pagos_contado.length === 0) {
            return false;
        }
        return pagoForm.pagos_contado.every((p) => Boolean(p.comprobante) && Number(p.monto) > 0);
    }
    if (pagoForm.forma_pago === 'cuotas') {
        if (excedeMontoObjetivo.value || pagoForm.cuotas.length === 0) {
            return false;
        }
        return pagoForm.cuotas.every((c) => Boolean(c.comprobante) && Number(c.monto) > 0);
    }
    return false;
});

const enviarFormaPago = () => {
    if (!puedeEnviarFormaPago.value) return;

    pagoForm
        .transform((data) => {
            if (data.forma_pago === 'credito') {
                const baseCredito = {
                    forma_pago: 'credito',
                    id_tasa_interes: data.id_tasa_interes || null,
                    monto_inicial: data.monto_inicial || 0,
                    metodo_pago_inicial: data.metodo_pago_inicial,
                    comprobante_inicial: data.comprobante_inicial,
                    observaciones: data.observaciones,
                };
                if (data.modalidad_credito === 'mayor_6_meses') {
                    return {
                        ...baseCredito,
                        modalidad_credito: 'mayor_6_meses',
                        cuota_mensual: cuotaMensualReferencia.value || null,
                        meses_credito: data.meses_credito,
                        fecha_siguiente_pago: data.fecha_siguiente_pago,
                    };
                }
                return {
                    ...baseCredito,
                    modalidad_credito: 'abierto',
                    cuotas_credito: data.cuotas_credito,
                };
            }
            if (data.forma_pago === 'contado') {
                return {
                    forma_pago: 'contado',
                    pagos_contado: data.pagos_contado,
                };
            }
            return {
                forma_pago: 'cuotas',
                cuotas: data.cuotas,
            };
        })
        .post(route('mis-ventas.pago.store', props.venta.id_venta), {
            preserveScroll: true,
            forceFormData: true,
            onFinish: () => pagoForm.transform((data) => data),
        });
};

const decidirSolicitudCredito = (decision) => {
    decisionCreditoForm.decision = decision;
    decisionCreditoForm.patch(route('mis-ventas.credito.decidir', props.venta.id_venta), {
        preserveScroll: true,
    });
};

const cuotasPendientesCredito = computed(() =>
    (props.venta.credito?.cuotas || []).filter((c) => c.estado !== 'pagada'),
);

const preseleccionarCuotaCobro = (cuota) => {
    cobroCreditoForm.id_cuota_credito = cuota.id_cuota_credito;
    cobroCreditoForm.monto_pagado = String(cuota.saldo_cuota || cuota.valor_cuota || '');
};

const registrarCobroCredito = () => {
    if (!cobroCreditoForm.comprobante || Number(cobroCreditoForm.monto_pagado) <= 0) {
        return;
    }

    cobroCreditoForm.post(route('mis-ventas.credito.pagos.store', props.venta.id_venta), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            cobroCreditoForm.reset();
            cobroCreditoForm.metodo_pago = 'transferencia';
            cobroCreditoForm.fecha_pago = hoyIso;
            if (cobroCreditoInputRef.value) {
                cobroCreditoInputRef.value.value = '';
            }
        },
    });
};

const estadoCuotaClases = (estado) => ({
    pendiente: 'bg-gray-100 text-gray-700',
    parcial: 'bg-amber-100 text-amber-900',
    pagada: 'bg-green-100 text-green-800',
    vencida: 'bg-red-100 text-red-800',
}[estado] || 'bg-gray-100 text-gray-700');

const registrarAbonoAdicional = () => {
    if (!abonoForm.comprobante || Number(abonoForm.monto) <= 0 || abonoExcedeSaldo.value) {
        return;
    }
    abonoForm.post(route('mis-ventas.pago.abono', props.venta.id_venta), {
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

// 3. Escaneo de Cédula con IA en segundo plano, Datos Faltantes y Generación de Constancia Word (.docx)
const archivoCedula = ref(null);
const escaneandoCedula = ref(false);
const errorCedula = ref('');
const avisoCedula = ref('');
const exitoCedula = ref('');
const generandoConstancia = ref(false);
const errorConstancia = ref('');

const datosCedula = reactive({
    nombre_completo: props.venta.datos_cedula_comprador?.nombre_completo || props.venta.cliente_nombre || '',
    cedula: props.venta.datos_cedula_comprador?.cedula || props.venta.cliente_cedula || '',
    direccion: props.venta.datos_cedula_comprador?.direccion || props.venta.cliente_direccion || '',
    correo: props.venta.datos_cedula_comprador?.correo || props.venta.cliente_correo || '',
    telefono: props.venta.datos_cedula_comprador?.telefono || props.venta.cliente_telefono || '',
    estado_civil: props.venta.datos_cedula_comprador?.estado_civil || '',
    es_casado: Boolean(props.venta.datos_cedula_comprador?.es_casado),
    nombre_conyuge: props.venta.datos_cedula_comprador?.nombre_conyuge || '',
    cedula_conyuge: props.venta.datos_cedula_comprador?.cedula_conyuge || '',
});

const datosVendedorAnterior = reactive({
    nombre_cliente_vendedor: props.venta.vendedor_anterior?.nombre_completo || '',
    cedula_cliente_vendedor: props.venta.vendedor_anterior?.cedula || '',
});

const requiereClienteVendedor = computed(() =>
    Boolean(props.venta.constancia_estrategia?.requiere_cliente_vendedor),
);

const camposFaltantesPagare = computed(() => {
    const faltantes = [];
    if (!String(datosCedula.nombre_completo || '').trim()) {
        faltantes.push({ key: 'nombre_completo', label: 'Nombre completo' });
    }
    if (!String(datosCedula.cedula || '').trim()) {
        faltantes.push({ key: 'cedula', label: 'Cédula / RUC' });
    }
    if (!String(datosCedula.direccion || '').trim()) {
        faltantes.push({ key: 'direccion', label: 'Dirección domiciliaria' });
    }
    if (!String(datosCedula.correo || '').trim()) {
        faltantes.push({ key: 'correo', label: 'Correo electrónico' });
    }
    return faltantes;
});

const camposFaltantesConstancia = computed(() => {
    const faltantes = [...camposFaltantesPagare.value];
    if (!String(datosCedula.telefono || '').trim()) {
        faltantes.push({ key: 'telefono', label: 'Teléfono del comprador' });
    }
    if (requiereClienteVendedor.value) {
        if (!String(datosVendedorAnterior.nombre_cliente_vendedor || '').trim()) {
            faltantes.push({ key: 'nombre_cliente_vendedor', label: 'Nombre del propietario anterior' });
        }
        if (!String(datosVendedorAnterior.cedula_cliente_vendedor || '').trim()) {
            faltantes.push({ key: 'cedula_cliente_vendedor', label: 'Cédula del propietario anterior' });
        }
    }
    return faltantes;
});

const camposFaltantesActuales = computed(() =>
    modoModalFaltantes.value === 'pagare'
        ? camposFaltantesPagare.value
        : camposFaltantesConstancia.value,
);

const mostrarCampoEnModal = (key) =>
    mostrarTodosLosCamposModal.value
    || modoModalFaltantes.value === 'editar'
    || camposFaltantesActuales.value.some((c) => c.key === key);

const descargarArchivo = (url) => {
    if (!url) return;
    const enlace = document.createElement('a');
    enlace.href = url;
    enlace.setAttribute('download', '');
    document.body.appendChild(enlace);
    enlace.click();
    document.body.removeChild(enlace);
};

const irAPasoUnoCedula = () => {
    paso1CedulaRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

const seleccionarCedula = (event) => {
    const archivo = event.target.files[0] || null;
    archivoCedula.value = archivo;
    errorCedula.value = '';
    avisoCedula.value = '';
    exitoCedula.value = '';

    // Escaneo automático con Gemini Vision en segundo plano al subir la cédula
    if (archivo) {
        escanearCedulaConGemini();
    }
};

const escanearCedulaConGemini = async () => {
    if (!archivoCedula.value) {
        errorCedula.value = 'Selecciona primero una foto o PDF de la cédula del comprador.';
        return;
    }

    escaneandoCedula.value = true;
    errorCedula.value = '';
    avisoCedula.value = '';
    exitoCedula.value = '';

    const formData = new FormData();
    formData.append('documento_cedula', archivoCedula.value);

    try {
        const res = await axios.post(
            route('mis-ventas.escanear.cedula', props.venta.id_venta),
            formData,
            {
                headers: {
                    'Content-Type': 'multipart/form-data',
                    Accept: 'application/json',
                },
                timeout: 65000,
            },
        );

        if (res.data?.cedula_url) {
            cedulaUrlActual.value = res.data.cedula_url;
        }
        if (res.data?.pagare_url) {
            pagareUrlActual.value = res.data.pagare_url;
        }

        if (res.data?.ocr_fallo) {
            avisoCedula.value = res.data.warning
                || 'Gemini no pudo analizar la cédula, pero el archivo sí se almacenó correctamente. Los datos faltantes se solicitarán al emitir el Pagaré o la Constancia.';
        } else if (res.data?.success) {
            const d = res.data.datos_cedula_comprador || {};
            if (d.nombre_completo) datosCedula.nombre_completo = d.nombre_completo;
            if (d.cedula) datosCedula.cedula = d.cedula;
            if (d.direccion) datosCedula.direccion = d.direccion;
            if (d.correo) datosCedula.correo = d.correo;
            if (d.telefono) datosCedula.telefono = d.telefono;
            if (d.estado_civil) datosCedula.estado_civil = d.estado_civil;
            datosCedula.es_casado = Boolean(d.es_casado);
            if (d.nombre_conyuge) datosCedula.nombre_conyuge = d.nombre_conyuge;
            if (d.cedula_conyuge !== undefined) {
                datosCedula.cedula_conyuge = d.cedula_conyuge || '';
            }
            exitoCedula.value = res.data.pagare_url
                ? 'Cédula procesada con IA en segundo plano, cliente sincronizado y Pagaré (.docx) actualizado.'
                : 'Cédula procesada con IA en segundo plano y datos del comprador sincronizados en el expediente.';
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

const iniciarDescargaPagare = () => {
    if (!pagareUrlActual.value) {
        return;
    }
    if (camposFaltantesPagare.value.length > 0) {
        modoModalFaltantes.value = 'pagare';
        mostrarTodosLosCamposModal.value = false;
        errorModalFaltantes.value = '';
        mostrarModalFaltantes.value = true;
        return;
    }
    descargarArchivo(pagareUrlActual.value);
};

const iniciarGeneracionConstancia = () => {
    if (!puedeGenerarConstancia.value || generandoConstancia.value) {
        return;
    }
    if (camposFaltantesConstancia.value.length > 0) {
        modoModalFaltantes.value = 'constancia';
        mostrarTodosLosCamposModal.value = false;
        errorModalFaltantes.value = '';
        mostrarModalFaltantes.value = true;
        return;
    }
    generarYDescargarConstancia();
};

const abrirEdicionComprador = () => {
    modoModalFaltantes.value = 'editar';
    mostrarTodosLosCamposModal.value = true;
    errorModalFaltantes.value = '';
    mostrarModalFaltantes.value = true;
};

const completarDatosYContinuar = async () => {
    errorModalFaltantes.value = '';

    if (modoModalFaltantes.value === 'constancia') {
        await generarYDescargarConstancia();
        return;
    }

    guardandoComprador.value = true;
    try {
        const res = await axios.patch(
            route('mis-ventas.comprador.update', props.venta.id_venta),
            {
                nombre_completo: datosCedula.nombre_completo,
                cedula: datosCedula.cedula,
                direccion: datosCedula.direccion,
                correo: datosCedula.correo,
                telefono: datosCedula.telefono,
                estado_civil: datosCedula.estado_civil,
                es_casado: datosCedula.es_casado,
                nombre_conyuge: datosCedula.es_casado ? datosCedula.nombre_conyuge : '',
                cedula_conyuge: datosCedula.es_casado ? datosCedula.cedula_conyuge : '',
            },
        );

        if (res.data?.pagare_url) {
            pagareUrlActual.value = res.data.pagare_url;
        }
        mostrarModalFaltantes.value = false;
        exitoCedula.value = modoModalFaltantes.value === 'pagare'
            ? 'Datos completados y Pagaré (.docx) regenerado correctamente.'
            : 'Datos del comprador actualizados correctamente.';

        if (modoModalFaltantes.value === 'pagare' && pagareUrlActual.value) {
            descargarArchivo(pagareUrlActual.value);
        }
    } catch (error) {
        const errores = Object.values(error.response?.data?.errors || {}).flat();
        errorModalFaltantes.value = errores[0] || error.response?.data?.message || 'Error al guardar los datos del comprador.';
    } finally {
        guardandoComprador.value = false;
    }
};

const generarYDescargarConstancia = async () => {
    generandoConstancia.value = true;
    errorConstancia.value = '';
    errorModalFaltantes.value = '';
    mensajeConstancia.value = '';

    try {
        const res = await axios.post(
            route('mis-ventas.generar.constancia', props.venta.id_venta),
            {
                nombre_completo: datosCedula.nombre_completo,
                cedula: datosCedula.cedula,
                direccion: datosCedula.direccion,
                correo: datosCedula.correo,
                telefono: datosCedula.telefono,
                estado_civil: datosCedula.estado_civil,
                es_casado: datosCedula.es_casado,
                nombre_conyuge: datosCedula.es_casado ? datosCedula.nombre_conyuge : '',
                cedula_conyuge: datosCedula.es_casado ? datosCedula.cedula_conyuge : '',
                nombre_cliente_vendedor: datosVendedorAnterior.nombre_cliente_vendedor,
                cedula_cliente_vendedor: datosVendedorAnterior.cedula_cliente_vendedor,
            },
        );

        if (res.data?.success && res.data.constancia_url) {
            constanciaUrlActual.value = res.data.constancia_url;
            mensajeConstancia.value = 'Constancia de venta generada correctamente en almacenamiento privado.';
            mostrarModalFaltantes.value = false;
            descargarArchivo(res.data.constancia_url);
        } else {
            const msg = res.data?.message || 'No se pudo generar la constancia.';
            errorConstancia.value = msg;
            errorModalFaltantes.value = msg;
        }
    } catch (error) {
        const errores = Object.values(error.response?.data?.errors || {}).flat();
        const msg = errores[0] || error.response?.data?.message || 'Error al generar la constancia.';
        errorConstancia.value = msg;
        errorModalFaltantes.value = msg;
        if (error.response?.status === 422 && !mostrarModalFaltantes.value) {
            modoModalFaltantes.value = 'constancia';
            mostrarModalFaltantes.value = true;
        }
    } finally {
        generandoConstancia.value = false;
    }
};

const creditoPendienteAprobacion = computed(() =>
    Boolean(props.venta.requiere_aprobacion_credito)
    && props.venta.estado_aprobacion_credito !== 'aprobado',
);

const puedeGenerarConstancia = computed(() =>
    precioAutorizadoParaPago.value && !creditoPendienteAprobacion.value,
);

const estadoVisual = computed(() => {
    if (props.venta.estado_aprobacion_descuento === 'pendiente') {
        return 'pendiente_aprobacion';
    }
    if (props.venta.estado_aprobacion_descuento === 'rechazado') {
        return 'descuento_rechazado';
    }
    if (props.venta.estado_aprobacion_credito === 'pendiente') {
        return 'pendiente_aprobacion_credito';
    }
    if (props.venta.estado_aprobacion_credito === 'rechazado') {
        return 'credito_rechazado';
    }
    if ((props.venta.estado === 'pagada' || props.venta.forma_pago) && !constanciaUrlActual.value) {
        return 'pendiente_constancia';
    }
    if (constanciaUrlActual.value) {
        return 'aprobada';
    }
    return props.venta.estado || 'en_proceso';
});

const estadoTexto = (estado) => ({
    en_proceso: 'En proceso comercial',
    pendiente_aprobacion: 'Pendiente aprobación de descuento',
    descuento_rechazado: 'Descuento rechazado',
    pendiente_aprobacion_credito: 'Pendiente de aprobación de crédito',
    credito_rechazado: 'Crédito rechazado',
    pagada: 'Pago registrado',
    pendiente_constancia: 'Pendiente generar constancia',
    aprobada: 'Completada · Constancia emitida',
}[estado] || estado);

const estadoClases = (estado) => ({
    en_proceso: 'bg-amber-100 text-amber-900',
    pendiente_aprobacion: 'bg-yellow-100 text-yellow-900',
    descuento_rechazado: 'bg-red-100 text-red-900',
    pendiente_aprobacion_credito: 'bg-purple-100 text-purple-900',
    credito_rechazado: 'bg-red-100 text-red-900',
    pagada: 'bg-blue-100 text-blue-900',
    pendiente_constancia: 'bg-indigo-100 text-indigo-900',
    aprobada: 'bg-green-100 text-green-900',
}[estado] || 'bg-gray-100 text-gray-800');
</script>

<template>
    <Head :title="`Gestión Comercial ${venta.placa}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                <div>
                    <Link
                        :href="route('mis-ventas.index')"
                        class="text-sm font-medium text-indigo-700 hover:text-indigo-900"
                    >
                        ← Volver a Mis Ventas
                    </Link>
                    <h2 class="mt-2 text-xl font-semibold leading-tight text-gray-800">
                        Gestión de {{ operacionForm.tipo_operacion === 'reserva' ? 'Reserva' : 'Venta' }}:
                        {{ venta.vehiculo?.marca }} {{ venta.vehiculo?.modelo }} ({{ venta.placa }})
                    </h2>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="w-fit rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide"
                        :class="operacionForm.tipo_operacion === 'reserva'
                            ? 'bg-indigo-100 text-indigo-800'
                            : 'bg-slate-800 text-yellow-400'"
                    >
                        {{ operacionForm.tipo_operacion === 'reserva' ? 'Reserva' : 'Venta' }}
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

                <!-- BANNER PENDIENTE GENERAR CONSTANCIA -->
                <section
                    v-if="estadoVisual === 'pendiente_constancia'"
                    class="flex flex-col justify-between gap-4 rounded-lg border border-indigo-300 bg-indigo-50 p-5 shadow-sm sm:flex-row sm:items-center"
                >
                    <div>
                        <p class="text-sm font-bold text-indigo-950">
                            Comprobante / pago registrado · Falta generar la constancia de venta (.docx)
                        </p>
                        <p class="mt-1 text-xs text-indigo-800">
                            Verifica que la cédula esté cargada en el Paso 1 y genera la constancia en el Paso 3 según el tipo de contrato del vehículo.
                        </p>
                    </div>
                    <button
                        type="button"
                        :disabled="!puedeGenerarConstancia || generandoConstancia"
                        class="shrink-0 rounded-md bg-yellow-500 px-5 py-2.5 text-sm font-bold text-gray-900 shadow-sm hover:bg-yellow-400 disabled:opacity-50"
                        @click="iniciarGeneracionConstancia"
                    >
                        {{ generandoConstancia ? 'Generando...' : 'Generar Constancia' }}
                    </button>
                </section>

                <!-- RESUMEN DEL VEHÍCULO Y DESCARGAS PRIVADAS -->
                <section class="grid grid-cols-1 gap-4 rounded-lg border border-gray-200 bg-white p-5 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Vehículo</p>
                        <p class="mt-1 text-sm font-bold text-gray-900">
                            {{ venta.vehiculo?.marca }} {{ venta.vehiculo?.modelo }} ({{ venta.vehiculo?.anio }})
                        </p>
                        <p class="text-xs text-gray-500">
                            Placa: <span class="font-mono font-semibold">{{ venta.placa }}</span> · Color: {{ venta.vehiculo?.color }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Vendedor asignado</p>
                        <p class="mt-1 text-sm text-gray-900">{{ venta.vendedor }}</p>
                        <p class="text-xs text-gray-500">Iniciado: {{ fechaTexto(venta.created_at) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Estado / Contrato de ingreso</p>
                        <div class="mt-1 flex flex-wrap items-center gap-1.5">
                            <span class="rounded-full bg-yellow-100 px-2.5 py-0.5 text-xs font-bold text-yellow-800">
                                {{ venta.vehiculo?.estado }}
                            </span>
                            <span
                                v-if="venta.vehiculo?.tipo_contrato_nombre"
                                class="rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-700"
                            >
                                {{ venta.vehiculo.tipo_contrato_nombre }}
                            </span>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-end gap-2">
                        <a
                            v-if="cedulaUrlActual"
                            :href="cedulaUrlActual"
                            class="inline-flex rounded-md bg-slate-700 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-600"
                        >
                            Descargar cédula
                        </a>
                        <a
                            v-if="constanciaUrlActual"
                            :href="constanciaUrlActual"
                            class="inline-flex rounded-md bg-blue-700 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-600"
                        >
                            Descargar constancia (.docx)
                        </a>
                    </div>
                </section>

                <!-- PASO 1: IDENTIFICACIÓN DEL COMPRADOR (SUBIDA Y ESCANEO DE CÉDULA CON IA EN SEGUNDO PLANO) -->
                <section ref="paso1CedulaRef" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex flex-col justify-between gap-3 border-b border-gray-200 bg-gray-900 px-5 py-4 text-white sm:flex-row sm:items-center">
                        <div>
                            <h3 class="text-sm font-bold uppercase tracking-wide text-yellow-400">
                                Paso 1 · Identificación del Comprador (Cédula + IA)
                            </h3>
                            <p class="mt-0.5 text-xs text-gray-300">
                                Sube la cédula del comprador. Gemini IA extraerá sus datos automáticamente en segundo plano sin interrumpir tu flujo.
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span
                                v-if="escaneandoCedula"
                                class="rounded-full bg-amber-400/20 px-3 py-1 text-xs font-bold text-amber-300"
                            >
                                ⏳ Analizando con IA...
                            </span>
                            <span
                                v-else-if="cedulaUrlActual"
                                class="rounded-full bg-emerald-500/20 px-3 py-1 text-xs font-bold text-emerald-300"
                            >
                                ✓ Cédula cargada
                            </span>
                            <span
                                v-else
                                class="rounded-full bg-red-500/20 px-3 py-1 text-xs font-bold text-red-300"
                            >
                                Pendiente de subir
                            </span>
                        </div>
                    </div>

                    <div class="space-y-4 p-5">
                        <div class="flex flex-col gap-3 rounded-lg border border-gray-200 bg-gray-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex-1">
                                <label for="paso1-documento-cedula" class="block text-xs font-bold uppercase tracking-wide text-gray-700">
                                    Archivo de cédula del comprador (JPG, PNG, WEBP o PDF · Privado)
                                </label>
                                <input
                                    id="paso1-documento-cedula"
                                    type="file"
                                    :disabled="escaneandoCedula"
                                    accept="image/jpeg,image/png,image/webp,.pdf"
                                    class="mt-1.5 block w-full text-xs text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-900 file:px-3.5 file:py-2 file:font-semibold file:text-yellow-400 hover:file:bg-gray-800 disabled:opacity-50"
                                    @change="seleccionarCedula"
                                />
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <a
                                    v-if="cedulaUrlActual"
                                    :href="cedulaUrlActual"
                                    class="rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-100"
                                >
                                    Ver cédula guardada
                                </a>
                                <button
                                    v-if="cedulaUrlActual || datosCedula.nombre_completo"
                                    type="button"
                                    class="rounded-md bg-slate-800 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-700"
                                    @click="abrirEdicionComprador"
                                >
                                    Revisar / editar datos
                                </button>
                            </div>
                        </div>

                        <div
                            v-if="escaneandoCedula"
                            role="status"
                            class="flex items-center gap-3 rounded-md border border-indigo-200 bg-indigo-50 px-4 py-3 text-xs font-medium text-indigo-900"
                        >
                            <span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-indigo-600 border-t-transparent" />
                            <span>Subiendo cédula de forma privada y extrayendo datos del comprador con Gemini Vision en segundo plano...</span>
                        </div>

                        <div
                            v-if="exitoCedula"
                            role="status"
                            class="rounded-md border border-emerald-300 bg-emerald-50 p-3 text-xs font-medium text-emerald-900"
                        >
                            ✅ {{ exitoCedula }}
                        </div>
                        <div
                            v-if="avisoCedula"
                            role="alert"
                            class="rounded-md border-2 border-amber-400 bg-amber-50 p-3 text-xs font-medium text-amber-950"
                        >
                            ⚠️ {{ avisoCedula }}
                        </div>
                        <p v-if="errorCedula" role="alert" class="rounded-md border border-red-200 bg-red-50 p-3 text-xs font-medium text-red-700">
                            {{ errorCedula }}
                        </p>

                        <!-- Resumen compacto de datos sincronizados del comprador -->
                        <div
                            v-if="datosCedula.nombre_completo || datosCedula.cedula || cedulaUrlActual"
                            class="grid grid-cols-1 gap-3 rounded-lg border border-gray-200 bg-white p-3.5 text-xs sm:grid-cols-2 lg:grid-cols-4"
                        >
                            <div>
                                <span class="block text-[10px] font-semibold uppercase text-gray-500">Comprador</span>
                                <span class="font-bold text-gray-900">{{ datosCedula.nombre_completo || 'Por completar' }}</span>
                            </div>
                            <div>
                                <span class="block text-[10px] font-semibold uppercase text-gray-500">Cédula / RUC</span>
                                <span class="font-mono font-bold text-gray-900">{{ datosCedula.cedula || 'Por completar' }}</span>
                            </div>
                            <div>
                                <span class="block text-[10px] font-semibold uppercase text-gray-500">Dirección</span>
                                <span class="text-gray-800">{{ datosCedula.direccion || 'Se pedirá al emitir documento' }}</span>
                            </div>
                            <div>
                                <span class="block text-[10px] font-semibold uppercase text-gray-500">Correo electrónico</span>
                                <span class="text-gray-800">{{ datosCedula.correo || 'Se pedirá al emitir documento' }}</span>
                            </div>
                        </div>

                        <p
                            v-if="cedulaUrlActual && camposFaltantesPagare.length > 0"
                            class="text-[11px] text-gray-600"
                        >
                            Nota: Si faltan campos no visibles en la cédula (<strong>{{ camposFaltantesPagare.map((c) => c.label).join(', ') }}</strong>), puedes continuar configurando el pago y te los solicitaremos únicamente al emitir el Pagaré o la Constancia.
                        </p>
                    </div>
                </section>

                <!-- PASO 2A: CONFIGURACIÓN COMERCIAL (TIPO DE OPERACIÓN Y PRECIO DE VENTA ACORDADO) -->
                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex flex-col justify-between gap-3 border-b border-gray-200 bg-gray-900 px-5 py-4 text-white sm:flex-row sm:items-center">
                        <div>
                            <h3 class="text-sm font-bold uppercase tracking-wide text-yellow-400">
                                Paso 2A · Tipo de Operación y Precio de Venta Acordado
                            </h3>
                            <p class="mt-0.5 text-xs text-gray-300">
                                Selecciona si la operación corresponde a una Venta o Reserva y define el precio acordado con el cliente.
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-stretch gap-2">
                            <div class="rounded-md border border-gray-700 bg-gray-800 px-3.5 py-2 text-right">
                                <span class="block text-[10px] font-semibold uppercase tracking-wider text-gray-400">
                                    Precio de Lista
                                </span>
                                <span class="font-mono text-base font-extrabold text-white">
                                    {{ precioLista > 0 ? formatearMoneda(precioLista) : 'Sin definir' }}
                                </span>
                            </div>
                            <div class="rounded-md border border-yellow-500/50 bg-gray-800 px-3.5 py-2 text-right">
                                <span class="block text-[10px] font-semibold uppercase tracking-wider text-yellow-300">
                                    Precio Final (Mínimo)
                                </span>
                                <span class="font-mono text-base font-extrabold text-yellow-400">
                                    {{ tienePrecioFinal ? formatearMoneda(precioFinal) : 'No definido' }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4 p-5">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label for="tipo-operacion-select" class="block text-sm font-semibold text-gray-800">
                                    Tipo de Operación *
                                </label>
                                <select
                                    id="tipo-operacion-select"
                                    v-model="operacionForm.tipo_operacion"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-yellow-500 focus:ring-yellow-500"
                                >
                                    <option value="venta">Venta</option>
                                    <option value="reserva">Reserva</option>
                                </select>
                            </div>

                            <div>
                                <label for="precio-acordado-input" class="block text-sm font-semibold text-gray-800">
                                    Precio de Venta Acordado ($) *
                                </label>
                                <input
                                    id="precio-acordado-input"
                                    v-model="operacionForm.precio_acordado"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    required
                                    placeholder="0.00"
                                    class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm font-bold text-gray-900 shadow-sm focus:border-yellow-500 focus:ring-yellow-500"
                                />
                                <p v-if="operacionForm.errors.precio_acordado" role="alert" class="mt-1 text-xs font-medium text-red-600">
                                    {{ operacionForm.errors.precio_acordado }}
                                </p>
                            </div>
                        </div>

                        <!-- ALERTA BLOQUEANTE CUANDO EL PRECIO ES MENOR AL PRECIO FINAL ESTABLECIDO -->
                        <div
                            v-if="requiereEnviarAprobacion"
                            role="alert"
                            class="space-y-3 rounded-lg border-2 border-red-300 bg-red-50 p-4 text-sm text-red-900"
                        >
                            <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-center">
                                <div>
                                    <p class="font-bold text-red-800">
                                        ⚠️ Precio inferior al {{ etiquetaPrecioMinimo }} establecido (Diferencia de {{ formatearMoneda(diferenciaDescuento) }})
                                    </p>
                                    <p class="mt-1 text-xs text-red-700">
                                        El monto ingresado ({{ formatearMoneda(precioAcordadoNum) }}) es menor al {{ etiquetaPrecioMinimo.toLowerCase() }} autorizado ({{ formatearMoneda(precioMinimoAutorizado) }}).
                                        No es posible cerrar la venta directamente; debes enviar el precio a aprobación de Gerencia/Administración.
                                    </p>
                                </div>
                                <span
                                    v-if="venta.estado_aprobacion_descuento === 'pendiente'"
                                    class="w-fit shrink-0 rounded-full bg-amber-200 px-3 py-1 text-xs font-bold uppercase text-amber-900"
                                >
                                    ⏳ Solicitud enviada · En espera de aprobación
                                </span>
                            </div>

                            <div>
                                <label for="motivo-descuento" class="block text-xs font-semibold text-red-900">
                                    Motivo o justificación del descuento (opcional)
                                </label>
                                <textarea
                                    id="motivo-descuento"
                                    v-model="operacionForm.motivo_descuento"
                                    rows="2"
                                    maxlength="1000"
                                    placeholder="Explica la negociación o motivo del descuento solicitado..."
                                    class="mt-1 block w-full rounded-md border-red-300 bg-white text-xs text-gray-900 shadow-sm focus:border-red-500 focus:ring-red-500"
                                />
                            </div>

                            <div class="flex justify-end">
                                <button
                                    type="button"
                                    :disabled="operacionForm.processing"
                                    class="rounded-md bg-amber-600 px-5 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-amber-700 disabled:opacity-50"
                                    @click="enviarDescuentoAAprobacion"
                                >
                                    {{ operacionForm.processing
                                        ? 'Enviando solicitud...'
                                        : venta.estado_aprobacion_descuento === 'pendiente'
                                            ? 'Actualizar solicitud de aprobación'
                                            : 'Enviar a aprobación' }}
                                </button>
                            </div>
                        </div>

                        <!-- ESTADO DE DESCUENTO APROBADO -->
                        <div
                            v-else-if="esPrecioMenorAlMinimo && descuentoAprobadoParaMontoActual"
                            class="rounded-lg border border-emerald-300 bg-emerald-50 p-3 text-xs text-emerald-900"
                        >
                            <p class="font-bold">
                                ✅ Descuento autorizado por {{ venta.aprobado_por || 'Administración' }}
                            </p>
                            <p v-if="venta.comentario_aprobacion_descuento" class="mt-1">
                                Comentario: "{{ venta.comentario_aprobacion_descuento }}"
                            </p>
                        </div>

                        <!-- PANEL DE APROBACIÓN / RECHAZO DE DESCUENTO (SOLO ADMINISTRADOR / ADMINISTRACIÓN) -->
                        <div
                            v-if="venta.puede_aprobar_descuento && venta.estado_aprobacion_descuento === 'pendiente'"
                            class="space-y-3 rounded-lg border-2 border-indigo-300 bg-indigo-50/70 p-4"
                        >
                            <p class="text-xs font-bold uppercase tracking-wide text-indigo-950">
                                Panel de Autorización de Descuento (Administración / Gerencia)
                            </p>
                            <p class="text-xs text-indigo-900">
                                El vendedor solicita autorizar un precio de <strong>{{ formatearMoneda(venta.precio_acordado) }}</strong>
                                <span v-if="tienePrecioFinal">
                                    por debajo del <strong>Precio Final mínimo de {{ formatearMoneda(precioFinal) }}</strong>
                                    (Precio de Lista: {{ formatearMoneda(precioLista) }}).
                                </span>
                                <span v-else>
                                    sobre el <strong>Precio de Lista de {{ formatearMoneda(precioLista) }}</strong>.
                                </span>
                                <span v-if="venta.motivo_descuento"> Motivo: "{{ venta.motivo_descuento }}"</span>
                            </p>
                            <div>
                                <label for="comentario-aprobacion-descuento" class="block text-xs font-semibold text-indigo-950">
                                    Comentario de revisión (opcional)
                                </label>
                                <input
                                    id="comentario-aprobacion-descuento"
                                    v-model="decisionDescuentoForm.comentario_aprobacion_descuento"
                                    type="text"
                                    maxlength="1000"
                                    placeholder="Observación para el vendedor..."
                                    class="mt-1 block w-full rounded-md border-indigo-300 bg-white text-xs text-gray-900 shadow-sm"
                                />
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    :disabled="decisionDescuentoForm.processing"
                                    class="rounded-md bg-emerald-700 px-4 py-1.5 text-xs font-bold text-white hover:bg-emerald-800 disabled:opacity-50"
                                    @click="decidirSolicitudDescuento('aprobado')"
                                >
                                    Aprobar descuento
                                </button>
                                <button
                                    type="button"
                                    :disabled="decisionDescuentoForm.processing"
                                    class="rounded-md bg-red-700 px-4 py-1.5 text-xs font-bold text-white hover:bg-red-800 disabled:opacity-50"
                                    @click="decidirSolicitudDescuento('rechazado')"
                                >
                                    Rechazar descuento
                                </button>
                            </div>
                        </div>

                        <div v-if="!requiereEnviarAprobacion" class="flex justify-end">
                            <button
                                type="button"
                                :disabled="operacionForm.processing || precioAcordadoNum <= 0"
                                class="rounded-md bg-gray-900 px-5 py-2 text-xs font-bold text-yellow-400 shadow-sm transition hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="guardarOperacionYPrecio"
                            >
                                {{ operacionForm.processing ? 'Guardando...' : 'Guardar operación y precio' }}
                            </button>
                        </div>
                    </div>
                </section>

                <!-- PASO 2B: FORMA DE PAGO, COMPROBANTES Y CRÉDITO (ALMACENAMIENTO PRIVADO LOCAL) -->
                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex flex-col justify-between gap-3 border-b border-gray-200 bg-gray-900 px-5 py-4 text-white sm:flex-row sm:items-center">
                        <div>
                            <h3 class="text-sm font-bold uppercase tracking-wide text-yellow-400">
                                Paso 2B · Forma de Pago, Comprobantes y Crédito
                            </h3>
                            <p class="mt-0.5 text-xs text-gray-300">
                                Todos los comprobantes se almacenan de forma privada y segura en el servidor.
                            </p>
                        </div>
                        <div class="shrink-0 rounded-md border border-yellow-500/40 bg-gray-800 px-4 py-2 text-right">
                            <span class="block text-[10px] font-semibold uppercase tracking-wider text-gray-400">
                                Precio Acordado
                            </span>
                            <span class="font-mono text-lg font-extrabold text-yellow-400">
                                {{ formatearMoneda(montoObjetivoVenta) }}
                            </span>
                        </div>
                    </div>

                    <div v-if="!precioAutorizadoParaPago" class="p-5 text-sm text-amber-900 bg-amber-50">
                        Para habilitar la carga de comprobantes de pago, primero guarda un precio de venta válido o espera la aprobación del descuento solicitado.
                    </div>

                    <form
                        v-else-if="mostrarFormularioPago"
                        class="space-y-5 p-5"
                        @submit.prevent="enviarFormaPago"
                    >
                        <div
                            v-if="venta.estado_aprobacion_credito === 'rechazado'"
                            role="alert"
                            class="rounded-md border-2 border-red-300 bg-red-50 p-3 text-xs text-red-900"
                        >
                            <p class="font-bold text-red-800">
                                ❌ El plan de {{ venta.modalidad_credito === 'mayor_6_meses' ? 'Crédito > 3 meses' : 'Crédito abierto / personalizado' }} anterior fue rechazado por {{ venta.aprobado_credito_por || 'Administración' }}.
                            </p>
                            <p v-if="venta.comentario_aprobacion_credito" class="mt-1">
                                Motivo: "{{ venta.comentario_aprobacion_credito }}"
                            </p>
                            <p class="mt-1 text-red-700">
                                Puedes configurar una nueva forma de pago o ajustar el plan de crédito a continuación.
                            </p>
                        </div>

                        <div
                            v-if="Object.keys(pagoForm.errors).length"
                            role="alert"
                            class="rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-700"
                        >
                            <p v-for="(err, key) in pagoForm.errors" :key="key">{{ err }}</p>
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label for="venta-forma-pago" class="block text-sm font-semibold text-gray-800">
                                    Forma de Pago *
                                </label>
                                <select
                                    id="venta-forma-pago"
                                    v-model="pagoForm.forma_pago"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                >
                                    <option value="contado">Contado (pago total)</option>
                                    <option value="cuotas">Cuotas / Abonos múltiples</option>
                                    <option value="credito">Créditos</option>
                                </select>
                            </div>
                        </div>

                        <!-- CRÉDITOS (DOS MODALIDADES) -->
                        <div
                            v-if="pagoForm.forma_pago === 'credito'"
                            class="space-y-4 rounded-lg border border-indigo-200 bg-indigo-50/40 p-4"
                        >
                            <!-- Bloqueo obligatorio si no se ha subido la cédula del comprador -->
                            <div
                                v-if="!tieneCedulaComprador"
                                role="alert"
                                class="flex flex-col justify-between gap-3 rounded-lg border-2 border-red-300 bg-red-50 p-4 text-xs text-red-900 sm:flex-row sm:items-center"
                            >
                                <div>
                                    <p class="font-bold text-red-800">
                                        ⛔ Requisito obligatorio: Cédula del cliente no cargada
                                    </p>
                                    <p class="mt-1 text-red-700">
                                        No es posible generar un crédito ni emitir el pagaré sin haber subido previamente la cédula del comprador.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="shrink-0 rounded-md bg-yellow-500 px-4 py-2 text-xs font-bold text-gray-900 shadow-xs hover:bg-yellow-400"
                                    @click="irAPasoUnoCedula"
                                >
                                    Ir al Paso 1: Subir cédula
                                </button>
                            </div>

                            <!-- Selector de modalidad de crédito -->
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <button
                                    type="button"
                                    class="rounded-lg border-2 px-4 py-3 text-left transition"
                                    :class="pagoForm.modalidad_credito === 'abierto'
                                        ? 'border-indigo-600 bg-white shadow-sm'
                                        : 'border-gray-200 bg-gray-50/70 hover:bg-white'"
                                    @click="pagoForm.modalidad_credito = 'abierto'"
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-xs font-bold uppercase tracking-wide text-indigo-900">
                                            Crédito abierto / personalizado
                                        </p>
                                        <span class="rounded-full bg-purple-100 px-2 py-0.5 text-[10px] font-bold text-purple-800">
                                            Requiere aprobación
                                        </span>
                                    </div>
                                    <p class="mt-0.5 text-[11px] text-gray-600">
                                        Define manualmente cada cuota y su fecha de vencimiento hasta completar el total.
                                    </p>
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg border-2 px-4 py-3 text-left transition"
                                    :class="pagoForm.modalidad_credito === 'mayor_6_meses'
                                        ? 'border-purple-600 bg-white shadow-sm'
                                        : 'border-gray-200 bg-gray-50/70 hover:bg-white'"
                                    @click="pagoForm.modalidad_credito = 'mayor_6_meses'"
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-xs font-bold uppercase tracking-wide text-purple-900">
                                            Crédito &gt; 3 meses
                                        </p>
                                        <span class="rounded-full bg-purple-100 px-2 py-0.5 text-[10px] font-bold text-purple-800">
                                            Requiere aprobación
                                        </span>
                                    </div>
                                    <p class="mt-0.5 text-[11px] text-gray-600">
                                        Financiamiento mayor a 3 meses con tasa fija 1.70% mensual (1.50% desde mes 13).
                                    </p>
                                </button>
                            </div>

                            <div class="rounded-md border border-purple-300 bg-purple-50 p-3 text-xs text-purple-950">
                                <strong>⏳ Flujo de aprobación obligatorio:</strong> Todas las modalidades de crédito (<strong>Crédito abierto</strong> y <strong>Crédito &gt; 3 meses</strong>) requieren autorización de <strong>Administración / Administrador</strong> y quedan en estado <strong>Pendiente de aprobación de crédito</strong> al registrarse.
                            </div>

                            <!-- Parámetros financieros base del crédito: Entrada y Saldo a financiar -->
                            <div class="grid grid-cols-1 gap-3 rounded-md border border-indigo-200 bg-white p-3.5 sm:grid-cols-2">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-800">
                                        Entrada / Monto inicial ($)
                                    </label>
                                    <input
                                        v-model="pagoForm.monto_inicial"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        placeholder="0.00"
                                        class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm"
                                    />
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-800">
                                        Saldo a financiar
                                    </label>
                                    <input
                                        type="text"
                                        :value="formatearMoneda(montoFinanciadoCredito)"
                                        disabled
                                        class="mt-1 block w-full rounded-md border-gray-200 bg-gray-100 font-mono text-sm font-bold text-indigo-900"
                                    />
                                </div>

                                <template v-if="montoInicialNum > 0">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700">Método pago entrada</label>
                                        <select
                                            v-model="pagoForm.metodo_pago_inicial"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-xs shadow-sm"
                                        >
                                            <option value="transferencia">Transferencia</option>
                                            <option value="efectivo">Efectivo</option>
                                            <option value="deposito">Depósito</option>
                                            <option value="cheque">Cheque</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700">Comprobante de entrada (opcional, privado)</label>
                                        <input
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp,.pdf"
                                            class="mt-1 block w-full text-xs text-gray-600 file:mr-2 file:rounded file:border-0 file:bg-indigo-700 file:px-2.5 file:py-1 file:text-white"
                                            @change="pagoForm.comprobante_inicial = $event.target.files[0] || null"
                                        />
                                    </div>
                                </template>
                            </div>

                            <!-- MODALIDAD 1: CRÉDITO ABIERTO / PERSONALIZADO -->
                            <div v-if="pagoForm.modalidad_credito === 'abierto'" class="space-y-3">
                                <div class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-indigo-200 bg-white px-3.5 py-2.5 text-xs">
                                    <div class="flex flex-wrap items-center gap-4">
                                        <span class="text-gray-600">
                                            Saldo a financiar:
                                            <strong class="font-mono text-gray-900">{{ formatearMoneda(montoFinanciadoCredito) }}</strong>
                                        </span>
                                        <span class="text-gray-600">
                                            Total cuotas:
                                            <strong
                                                class="font-mono"
                                                :class="creditoAbiertoCoincideTotal ? 'text-emerald-700' : creditoAbiertoExcedeTotal ? 'text-red-700' : 'text-amber-700'"
                                            >
                                                {{ formatearMoneda(sumaCuotasCreditoAbierto) }}
                                            </strong>
                                        </span>
                                        <span class="text-gray-600">
                                            Saldo restante:
                                            <strong
                                                class="font-mono"
                                                :class="creditoAbiertoCoincideTotal ? 'text-emerald-700' : creditoAbiertoExcedeTotal ? 'text-red-700' : 'text-gray-900'"
                                            >
                                                {{ formatearMoneda(saldoRestanteCreditoAbierto) }}
                                            </strong>
                                        </span>
                                    </div>
                                    <button
                                        type="button"
                                        class="rounded-md bg-gray-900 px-3 py-1.5 text-xs font-bold text-yellow-400 hover:bg-gray-800"
                                        @click="agregarCuotaCredito"
                                    >
                                        + Añadir cuota
                                    </button>
                                </div>

                                <div class="space-y-2.5">
                                    <div
                                        v-for="(cuota, idx) in pagoForm.cuotas_credito"
                                        :key="idx"
                                        class="grid grid-cols-1 items-end gap-3 rounded-md border border-gray-200 bg-white p-3 sm:grid-cols-12"
                                    >
                                        <div class="sm:col-span-2">
                                            <span class="inline-flex rounded bg-indigo-100 px-2.5 py-1 text-xs font-bold text-indigo-900">
                                                Cuota #{{ idx + 1 }}
                                            </span>
                                        </div>
                                        <div class="sm:col-span-5">
                                            <label class="block text-xs font-medium text-gray-700">Valor de la cuota ($) *</label>
                                            <input
                                                v-model="cuota.monto"
                                                type="number"
                                                step="0.01"
                                                min="0.01"
                                                required
                                                placeholder="0.00"
                                                class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm"
                                            />
                                        </div>
                                        <div class="sm:col-span-4">
                                            <label class="block text-xs font-medium text-gray-700">Fecha de vencimiento *</label>
                                            <input
                                                v-model="cuota.fecha_vencimiento"
                                                type="date"
                                                required
                                                class="mt-1 block w-full rounded-md border-gray-300 text-xs shadow-sm"
                                            />
                                        </div>
                                        <div class="flex justify-end sm:col-span-1">
                                            <button
                                                v-if="pagoForm.cuotas_credito.length > 1"
                                                type="button"
                                                class="rounded bg-red-100 px-2.5 py-1.5 text-xs font-bold text-red-700 hover:bg-red-200"
                                                @click="eliminarCuotaCredito(idx)"
                                            >
                                                ✕
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <p
                                    v-if="!creditoAbiertoCoincideTotal && sumaCuotasCreditoAbierto > 0"
                                    role="alert"
                                    class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-900"
                                >
                                    {{
                                        creditoAbiertoExcedeTotal
                                            ? `La suma de cuotas excede el valor total de la venta por ${formatearMoneda(Math.abs(saldoRestanteCreditoAbierto))}.`
                                            : `Faltan ${formatearMoneda(saldoRestanteCreditoAbierto)} por asignar para completar el valor total de la venta.`
                                    }}
                                </p>
                            </div>

                            <!-- MODALIDAD 2: CRÉDITO > 3 MESES -->
                            <div v-else class="space-y-4">
                                <div class="rounded-md border border-indigo-200 bg-indigo-50/70 px-3.5 py-2.5 text-xs text-indigo-950">
                                    <strong>Tasa de interés fija:</strong>
                                    <strong>{{ tasaBaseMensualPct.toFixed(2) }}% mensual</strong> sobre el monto financiado del mes 1 al 12
                                    (ej. {{ formatearMoneda(montoFinanciadoCredito * (tasaBaseMensualPct / 100)) }}/mes) y baja automáticamente a
                                    <strong class="text-emerald-800">{{ tasaMes13MensualPct.toFixed(2) }}% mensual</strong> a partir del mes 13
                                    ({{ formatearMoneda(montoFinanciadoCredito * (tasaMes13MensualPct / 100)) }}/mes).
                                </div>

                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-800">
                                            Plazo en meses (&gt; 3 meses) *
                                        </label>
                                        <input
                                            v-model.number="pagoForm.meses_credito"
                                            type="number"
                                            min="4"
                                            max="120"
                                            step="1"
                                            required
                                            class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm font-bold shadow-sm"
                                        />
                                        <p v-if="mesesCreditoNum > 0 && mesesCreditoNum <= 3" class="mt-1 text-[11px] font-medium text-red-600">
                                            Debe ser mayor a 3 meses (mínimo 4).
                                        </p>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-800">
                                            Fecha del siguiente pago *
                                        </label>
                                        <input
                                            v-model="pagoForm.fecha_siguiente_pago"
                                            type="date"
                                            :min="hoyIso"
                                            required
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                                        />
                                        <p class="mt-1 text-[11px] text-gray-500">
                                            Referencia desde hoy ({{ hoyIso }}).
                                        </p>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-800">
                                            Cuota mes 1 (estimada)
                                        </label>
                                        <input
                                            type="text"
                                            :value="formatearMoneda(cuotaMensualReferencia)"
                                            disabled
                                            class="mt-1 block w-full rounded-md border-gray-200 bg-gray-100 font-mono text-sm font-bold text-purple-900"
                                        />
                                        <p v-if="mesesCreditoNum > 12" class="mt-1 text-[11px] font-medium text-emerald-700">
                                            Desde mes 13: {{ formatearMoneda(tablaCuotasMayor6Meses[12]?.monto || 0) }}/mes
                                        </p>
                                    </div>
                                </div>

                                <!-- Resumen comparativo y proyección mes a mes -->
                                <div v-if="tablaCuotasMayor6Meses.length" class="space-y-2.5">
                                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-purple-200 bg-white px-4 py-2.5 text-xs">
                                        <div class="flex flex-wrap items-center gap-4">
                                            <span class="text-gray-600">
                                                Capital financiado:
                                                <strong class="font-mono text-sm text-gray-900">{{ formatearMoneda(montoFinanciadoCredito) }}</strong>
                                            </span>
                                            <span class="text-gray-600">
                                                Total intereses:
                                                <strong class="font-mono text-sm text-amber-800">{{ formatearMoneda(totalInteresMayor6Meses) }}</strong>
                                            </span>
                                            <span class="text-gray-600">
                                                Total a pagar ({{ mesesCreditoNum }} meses):
                                                <strong class="font-mono text-sm text-purple-900">{{ formatearMoneda(totalProyectadoMayor6Meses) }}</strong>
                                            </span>
                                        </div>
                                        <span
                                            v-if="mesesCreditoNum > 12"
                                            class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-bold text-emerald-800"
                                        >
                                            Baja a {{ tasaMes13MensualPct.toFixed(2) }}% desde mes 13
                                        </span>
                                    </div>

                                    <div class="max-h-60 overflow-y-auto rounded-lg border border-gray-200 bg-white">
                                        <table class="min-w-full divide-y divide-gray-200 text-xs">
                                            <thead class="sticky top-0 bg-gray-50 text-left font-semibold uppercase tracking-wide text-gray-600">
                                                <tr>
                                                    <th class="px-3 py-2">Mes</th>
                                                    <th class="px-3 py-2">Vencimiento</th>
                                                    <th class="px-3 py-2 text-center">Tasa</th>
                                                    <th class="px-3 py-2 text-right">Capital</th>
                                                    <th class="px-3 py-2 text-right">Interés</th>
                                                    <th class="px-3 py-2 text-right">Cuota Total</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100">
                                                <tr
                                                    v-for="fila in tablaCuotasMayor6Meses"
                                                    :key="fila.numero"
                                                    :class="fila.numero >= 13 ? 'bg-emerald-50/40' : ''"
                                                >
                                                    <td class="px-3 py-1.5 font-semibold text-gray-800">Mes {{ fila.numero }}</td>
                                                    <td class="px-3 py-1.5 font-mono text-gray-700">{{ fila.fecha_vencimiento }}</td>
                                                    <td class="px-3 py-1.5 text-center">
                                                        <span
                                                            class="rounded-full px-2 py-0.5 text-[10px] font-bold"
                                                            :class="fila.numero >= 13 ? 'bg-emerald-100 text-emerald-800' : 'bg-indigo-100 text-indigo-800'"
                                                        >
                                                            {{ fila.tasa_aplicada.toFixed(2) }}%
                                                        </span>
                                                    </td>
                                                    <td class="px-3 py-1.5 text-right font-mono text-gray-700">{{ formatearMoneda(fila.monto_capital) }}</td>
                                                    <td class="px-3 py-1.5 text-right font-mono text-amber-800">{{ formatearMoneda(fila.monto_interes) }}</td>
                                                    <td class="px-3 py-1.5 text-right font-mono font-bold text-gray-900">{{ formatearMoneda(fila.monto) }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-800">
                                    Observaciones del crédito (opcional)
                                </label>
                                <textarea
                                    v-model="pagoForm.observaciones"
                                    rows="2"
                                    maxlength="1000"
                                    placeholder="Notas adicionales sobre el acuerdo de crédito..."
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                                />
                            </div>
                        </div>

                        <!-- CUOTAS -->
                        <div
                            v-else-if="pagoForm.forma_pago === 'cuotas'"
                            class="space-y-4 rounded-lg border border-amber-200 bg-amber-50/40 p-4"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                <div class="flex flex-wrap items-center gap-4">
                                    <span class="font-semibold text-gray-700">
                                        Acumulado:
                                        <strong class="font-mono text-sm" :class="excedeMontoObjetivo ? 'text-red-700' : 'text-emerald-700'">
                                            {{ formatearMoneda(sumaCuotas) }}
                                        </strong>
                                    </span>
                                    <span class="font-semibold text-gray-700">
                                        Saldo restante:
                                        <strong class="font-mono text-sm text-gray-900">{{ formatearMoneda(saldoRestanteCuotas) }}</strong>
                                    </span>
                                </div>
                                <button
                                    type="button"
                                    :disabled="!puedeAgregarCuota"
                                    class="rounded-md bg-gray-900 px-3 py-1.5 text-xs font-bold text-yellow-400 hover:bg-gray-800 disabled:opacity-40"
                                    @click="agregarCuota"
                                >
                                    + Agregar cuota/comprobante
                                </button>
                            </div>

                            <div class="space-y-3">
                                <div
                                    v-for="(cuota, idx) in pagoForm.cuotas"
                                    :key="idx"
                                    class="grid grid-cols-1 items-end gap-3 rounded-md border border-gray-200 bg-white p-3 sm:grid-cols-12"
                                >
                                    <div class="sm:col-span-5">
                                        <label class="block text-xs font-medium text-gray-700">Comprobante #{{ idx + 1 }} *</label>
                                        <input
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp,.pdf"
                                            required
                                            class="mt-1 block w-full text-xs text-gray-600 file:mr-2 file:rounded file:border-0 file:bg-emerald-700 file:px-2.5 file:py-1.5 file:text-white"
                                            @change="cuota.comprobante = $event.target.files[0] || null"
                                        />
                                    </div>
                                    <div class="sm:col-span-3">
                                        <label class="block text-xs font-medium text-gray-700">Valor del abono ($) *</label>
                                        <input
                                            v-model="cuota.monto"
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            required
                                            class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm"
                                        />
                                    </div>
                                    <div class="sm:col-span-3">
                                        <label class="block text-xs font-medium text-gray-700">Fecha de pago</label>
                                        <input
                                            v-model="cuota.fecha_pago"
                                            type="date"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-xs shadow-sm"
                                        />
                                    </div>
                                    <div class="flex justify-end sm:col-span-1">
                                        <button
                                            v-if="pagoForm.cuotas.length > 1"
                                            type="button"
                                            class="rounded bg-red-100 px-2.5 py-1.5 text-xs font-bold text-red-700"
                                            @click="eliminarCuota(idx)"
                                        >
                                            ✕
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- CONTADO (1 pago total o varios comprobantes que completen el valor; unificado en cuotas_credito) -->
                        <div
                            v-else
                            class="space-y-4 rounded-lg border border-emerald-200 bg-emerald-50/50 p-4"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                <div class="flex flex-wrap items-center gap-4">
                                    <span class="font-semibold text-gray-700">
                                        Total contado registrado:
                                        <strong class="font-mono text-sm" :class="excedeMontoContado ? 'text-red-700' : 'text-emerald-700'">
                                            {{ formatearMoneda(sumaPagosContado) }}
                                        </strong>
                                    </span>
                                    <span class="font-semibold text-gray-700">
                                        Saldo restante:
                                        <strong class="font-mono text-sm text-gray-900">{{ formatearMoneda(saldoRestanteContado) }}</strong>
                                    </span>
                                </div>
                                <button
                                    type="button"
                                    :disabled="saldoRestanteContado <= 0.009"
                                    class="rounded-md bg-gray-900 px-3 py-1.5 text-xs font-bold text-yellow-400 hover:bg-gray-800 disabled:opacity-40"
                                    @click="agregarPagoContado"
                                >
                                    + Añadir otro comprobante de contado
                                </button>
                            </div>

                            <div class="space-y-3">
                                <div
                                    v-for="(pagoItem, idx) in pagoForm.pagos_contado"
                                    :key="idx"
                                    class="grid grid-cols-1 items-end gap-3 rounded-md border border-emerald-200 bg-white p-3 sm:grid-cols-12"
                                >
                                    <div class="sm:col-span-4">
                                        <label class="block text-xs font-medium text-gray-700">
                                            Comprobante de pago #{{ idx + 1 }} (privado) *
                                        </label>
                                        <input
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp,.pdf"
                                            required
                                            class="mt-1 block w-full text-xs text-gray-600 file:mr-2 file:rounded file:border-0 file:bg-emerald-700 file:px-2.5 file:py-1.5 file:text-white"
                                            @change="pagoItem.comprobante = $event.target.files[0] || null"
                                        />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="block text-xs font-medium text-gray-700">Método *</label>
                                        <select
                                            v-model="pagoItem.metodo_pago"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-xs shadow-sm"
                                        >
                                            <option value="transferencia">Transferencia</option>
                                            <option value="efectivo">Efectivo</option>
                                            <option value="deposito">Depósito</option>
                                            <option value="cheque">Cheque</option>
                                        </select>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="block text-xs font-medium text-gray-700">N° Comprobante</label>
                                        <input
                                            v-model="pagoItem.numero_referencia"
                                            type="text"
                                            maxlength="100"
                                            placeholder="Ref. opcional"
                                            class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs shadow-sm"
                                        />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="block text-xs font-medium text-gray-700">Valor ($) *</label>
                                        <input
                                            v-model="pagoItem.monto"
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            required
                                            class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm font-bold shadow-sm"
                                        />
                                    </div>
                                    <div class="sm:col-span-2 flex items-end gap-1.5">
                                        <div class="flex-1">
                                            <label class="block text-xs font-medium text-gray-700">Fecha</label>
                                            <input
                                                v-model="pagoItem.fecha_pago"
                                                type="date"
                                                class="mt-1 block w-full rounded-md border-gray-300 text-xs shadow-sm"
                                            />
                                        </div>
                                        <button
                                            v-if="pagoForm.pagos_contado.length > 1"
                                            type="button"
                                            class="rounded bg-red-100 px-2 py-1.5 text-xs font-bold text-red-700"
                                            @click="eliminarPagoContado(idx)"
                                        >
                                            ✕
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <p v-if="excedeMontoContado" role="alert" class="text-xs font-medium text-red-700">
                                La suma de los comprobantes de contado excede el precio acordado de la venta.
                            </p>
                        </div>

                        <div class="flex justify-end">
                            <button
                                type="submit"
                                :disabled="!puedeEnviarFormaPago"
                                class="rounded-md bg-emerald-700 px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {{
                                    pagoForm.processing
                                        ? 'Registrando...'
                                        : pagoForm.forma_pago === 'credito'
                                            ? 'Enviar plan de crédito a aprobación'
                                            : 'Registrar comprobante(s) de pago'
                                }}
                            </button>
                        </div>
                    </form>

                    <!-- RESUMEN DE PAGOS / CRÉDITO YA REGISTRADOS -->
                    <div v-else class="space-y-4 p-5 text-sm">
                        <!-- Estado y aprobación para Crédito (abierto y > 3 meses) -->
                        <div
                            v-if="venta.forma_pago === 'credito' && venta.estado_aprobacion_credito === 'pendiente'"
                            role="alert"
                            class="space-y-3 rounded-lg border-2 border-purple-300 bg-purple-50 p-4 text-xs text-purple-950"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="font-bold text-purple-900">
                                    ⏳ Pendiente de aprobación de crédito ({{ venta.modalidad_credito === 'mayor_6_meses' ? 'Crédito > 3 meses' : 'Crédito abierto / personalizado' }})
                                </p>
                                <span class="rounded-full bg-purple-200 px-2.5 py-0.5 text-[11px] font-bold uppercase text-purple-900">
                                    En revisión administrativa
                                </span>
                            </div>
                            <p v-if="venta.modalidad_credito === 'mayor_6_meses'">
                                Plan configurado: <strong>{{ venta.meses_credito }} cuotas mensuales de {{ formatearMoneda(venta.cuota_mensual_credito) }}</strong>
                                (Total proyectado: <strong>{{ formatearMoneda(totalPlanCreditoRegistrado) }}</strong> vs. saldo venta <strong>{{ formatearMoneda(montoObjetivoVenta) }}</strong>).
                                Siguiente pago: <strong>{{ venta.fecha_siguiente_pago_credito }}</strong>.
                            </p>
                            <p v-else>
                                Plan configurado: <strong>{{ venta.plan_cuotas_credito?.length || venta.meses_credito || 0 }} cuota(s) manual(es)</strong>
                                por un total de <strong>{{ formatearMoneda(totalPlanCreditoRegistrado) }}</strong>.
                                Primer vencimiento: <strong>{{ venta.fecha_siguiente_pago_credito }}</strong>.
                            </p>

                            <div
                                v-if="venta.puede_aprobar_credito"
                                class="space-y-2.5 border-t border-purple-200 pt-3"
                            >
                                <p class="font-bold uppercase tracking-wide text-purple-950">
                                    Panel de Autorización de Crédito (Administración / Administrador)
                                </p>
                                <input
                                    v-model="decisionCreditoForm.comentario_aprobacion_credito"
                                    type="text"
                                    maxlength="1000"
                                    placeholder="Comentario u observación para el vendedor (opcional)..."
                                    class="block w-full rounded-md border-purple-300 bg-white text-xs text-gray-900 shadow-sm"
                                />
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        :disabled="decisionCreditoForm.processing"
                                        class="rounded-md bg-emerald-700 px-4 py-1.5 text-xs font-bold text-white hover:bg-emerald-800 disabled:opacity-50"
                                        @click="decidirSolicitudCredito('aprobado')"
                                    >
                                        Aprobar crédito
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="decisionCreditoForm.processing"
                                        class="rounded-md bg-red-700 px-4 py-1.5 text-xs font-bold text-white hover:bg-red-800 disabled:opacity-50"
                                        @click="decidirSolicitudCredito('rechazado')"
                                    >
                                        Rechazar crédito
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div
                            v-else-if="venta.forma_pago === 'credito' && venta.estado_aprobacion_credito === 'aprobado'"
                            class="rounded-lg border border-emerald-300 bg-emerald-50 p-3 text-xs text-emerald-900"
                        >
                            <p class="font-bold">
                                ✅ {{ venta.modalidad_credito === 'mayor_6_meses' ? 'Crédito > 3 meses' : 'Crédito abierto / personalizado' }} aprobado por {{ venta.aprobado_credito_por || 'Administración' }}
                            </p>
                            <p v-if="venta.comentario_aprobacion_credito" class="mt-1">
                                Comentario: "{{ venta.comentario_aprobacion_credito }}"
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold uppercase text-emerald-800">
                                    Forma de pago: {{
                                        venta.forma_pago === 'credito'
                                            ? (venta.modalidad_credito === 'mayor_6_meses' ? 'Crédito > 3 meses' : 'Crédito abierto')
                                            : venta.forma_pago
                                    }}
                                </span>
                                <span class="text-xs text-gray-600">
                                    Total pagado: <strong class="font-mono text-gray-900">{{ formatearMoneda(totalPagadoRegistrado) }}</strong>
                                    / {{ formatearMoneda(montoObjetivoVenta) }}
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

                        <!-- CABECERA FINANCIERA NORMALIZADA, TABLA DE AMORTIZACIÓN, PAGARÉ PRIVADO Y COBRANZA (CAJA) -->
                        <div
                            v-if="venta.forma_pago === 'credito' && venta.credito"
                            class="space-y-4 rounded-lg border border-indigo-200 bg-indigo-50/30 p-4"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-indigo-200 pb-3">
                                <div>
                                    <p class="text-xs font-extrabold uppercase tracking-wide text-indigo-950">
                                        Expediente de Crédito #{{ venta.credito.id_credito }}
                                        <span v-if="venta.credito.tasa_interes_nombre" class="font-semibold text-indigo-700">
                                            · {{ venta.credito.tasa_interes_nombre }}
                                        </span>
                                    </p>
                                    <p class="mt-0.5 text-[11px] text-gray-600">
                                        Inicio: {{ venta.credito.fecha_inicio }} · Vencimiento final: {{ venta.credito.fecha_vencimiento_final }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        class="rounded-full px-2.5 py-0.5 text-xs font-bold uppercase"
                                        :class="{
                                            'bg-purple-100 text-purple-900': venta.credito.estado === 'pendiente_aprobacion_credito',
                                            'bg-emerald-100 text-emerald-900': venta.credito.estado === 'activo',
                                            'bg-red-100 text-red-900': venta.credito.estado === 'en_mora',
                                            'bg-gray-200 text-gray-800': venta.credito.estado === 'cancelado',
                                        }"
                                    >
                                        {{ venta.credito.estado.replaceAll('_', ' ') }}
                                    </span>
                                    <button
                                        v-if="pagareUrlActual"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-md bg-gray-900 px-3 py-1.5 text-xs font-bold text-yellow-400 hover:bg-gray-800"
                                        @click="iniciarDescargaPagare"
                                    >
                                        <span>📄 Descargar Pagaré / Amortización (.docx)</span>
                                        <span
                                            v-if="camposFaltantesPagare.length > 0"
                                            class="rounded bg-amber-400 px-1.5 py-0.5 text-[10px] font-extrabold text-gray-900"
                                        >
                                            +{{ camposFaltantesPagare.length }} dato(s)
                                        </span>
                                    </button>
                                </div>
                            </div>

                            <!-- Indicadores financieros del crédito -->
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <div class="rounded-md border border-gray-200 bg-white p-2.5">
                                    <span class="block text-[10px] font-semibold uppercase text-gray-500">Precio Venta</span>
                                    <span class="font-mono text-sm font-bold text-gray-900">{{ formatearMoneda(venta.credito.monto_total) }}</span>
                                </div>
                                <div class="rounded-md border border-gray-200 bg-white p-2.5">
                                    <span class="block text-[10px] font-semibold uppercase text-gray-500">Entrada Inicial</span>
                                    <span class="font-mono text-sm font-bold text-emerald-700">{{ formatearMoneda(venta.credito.monto_inicial) }}</span>
                                </div>
                                <div class="rounded-md border border-gray-200 bg-white p-2.5">
                                    <span class="block text-[10px] font-semibold uppercase text-gray-500">Monto Financiado</span>
                                    <span class="font-mono text-sm font-bold text-indigo-900">{{ formatearMoneda(venta.credito.monto_financiado) }}</span>
                                </div>
                                <div class="rounded-md border border-gray-200 bg-white p-2.5">
                                    <span class="block text-[10px] font-semibold uppercase text-gray-500">Saldo Pendiente Crédito</span>
                                    <span class="font-mono text-sm font-extrabold text-amber-800">{{ formatearMoneda(venta.credito.saldo_pendiente) }}</span>
                                </div>
                            </div>

                            <!-- Tabla de amortización detallada (cuotas_credito) -->
                            <div class="max-h-72 overflow-y-auto rounded-lg border border-indigo-200 bg-white">
                                <table class="min-w-full divide-y divide-gray-200 text-xs">
                                    <thead class="sticky top-0 bg-indigo-50 text-left font-semibold uppercase tracking-wide text-indigo-900">
                                        <tr>
                                            <th class="px-3 py-2">N°</th>
                                            <th class="px-3 py-2">Vencimiento</th>
                                            <th class="px-3 py-2 text-right">Capital</th>
                                            <th class="px-3 py-2 text-right">Interés</th>
                                            <th class="px-3 py-2 text-right">Valor Cuota</th>
                                            <th class="px-3 py-2 text-right">Pagado</th>
                                            <th class="px-3 py-2">Estado</th>
                                            <th class="px-3 py-2 text-right">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr v-for="cuota in venta.credito.cuotas" :key="cuota.id_cuota_credito">
                                            <td class="px-3 py-2 font-semibold text-gray-800">#{{ cuota.numero_cuota }}</td>
                                            <td class="px-3 py-2 font-mono text-gray-700">{{ cuota.fecha_vencimiento }}</td>
                                            <td class="px-3 py-2 text-right font-mono text-gray-700">{{ formatearMoneda(cuota.monto_capital) }}</td>
                                            <td class="px-3 py-2 text-right font-mono text-gray-700">{{ formatearMoneda(cuota.monto_interes) }}</td>
                                            <td class="px-3 py-2 text-right font-mono font-bold text-gray-900">{{ formatearMoneda(cuota.valor_cuota) }}</td>
                                            <td class="px-3 py-2 text-right font-mono text-emerald-700">{{ formatearMoneda(cuota.monto_pagado) }}</td>
                                            <td class="px-3 py-2">
                                                <span
                                                    :class="estadoCuotaClases(cuota.estado)"
                                                    class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase"
                                                >
                                                    {{ cuota.estado }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-2 text-right">
                                                <button
                                                    v-if="cuota.estado !== 'pagada' && ['activo', 'en_mora'].includes(venta.credito.estado)"
                                                    type="button"
                                                    class="rounded bg-emerald-700 px-2 py-1 text-[11px] font-bold text-white hover:bg-emerald-800"
                                                    @click="preseleccionarCuotaCobro(cuota)"
                                                >
                                                    Cobrar
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Formulario de Cobranza de Crédito (Asiento automático en Caja) -->
                            <form
                                v-if="['activo', 'en_mora'].includes(venta.credito.estado) && venta.credito.saldo_pendiente > 0.009"
                                class="space-y-3 rounded-lg border border-emerald-200 bg-white p-4"
                                @submit.prevent="registrarCobroCredito"
                            >
                                <p class="text-xs font-bold uppercase tracking-wide text-emerald-900">
                                    Registrar Cobro de Cuota / Abono (Se refleja automáticamente en Caja)
                                </p>

                                <div
                                    v-if="Object.keys(cobroCreditoForm.errors).length"
                                    role="alert"
                                    class="rounded border border-red-200 bg-red-50 p-2 text-xs text-red-700"
                                >
                                    <p v-for="(err, key) in cobroCreditoForm.errors" :key="key">{{ err }}</p>
                                </div>

                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700">Cuota a abonar</label>
                                        <select
                                            v-model="cobroCreditoForm.id_cuota_credito"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-xs shadow-sm"
                                        >
                                            <option value="">Siguiente cuota pendiente automática</option>
                                            <option
                                                v-for="c in cuotasPendientesCredito"
                                                :key="c.id_cuota_credito"
                                                :value="c.id_cuota_credito"
                                            >
                                                Cuota #{{ c.numero_cuota }} ({{ c.fecha_vencimiento }} · Saldo {{ formatearMoneda(c.saldo_cuota) }})
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700">Monto pagado ($) *</label>
                                        <input
                                            v-model="cobroCreditoForm.monto_pagado"
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            :max="venta.credito.saldo_pendiente"
                                            required
                                            placeholder="0.00"
                                            class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm font-bold shadow-sm"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700">Método de pago *</label>
                                        <select
                                            v-model="cobroCreditoForm.metodo_pago"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-xs shadow-sm"
                                        >
                                            <option value="transferencia">Transferencia</option>
                                            <option value="efectivo">Efectivo</option>
                                            <option value="deposito">Depósito</option>
                                            <option value="cheque">Cheque</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700">N° Referencia / Comprobante</label>
                                        <input
                                            v-model="cobroCreditoForm.numero_referencia"
                                            type="text"
                                            maxlength="100"
                                            placeholder="Ej. TRF-009281"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-xs shadow-sm"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700">Fecha de pago *</label>
                                        <input
                                            v-model="cobroCreditoForm.fecha_pago"
                                            type="date"
                                            required
                                            class="mt-1 block w-full rounded-md border-gray-300 text-xs shadow-sm"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700">Comprobante privado *</label>
                                        <input
                                            ref="cobroCreditoInputRef"
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp,.pdf"
                                            required
                                            class="mt-1 block w-full text-xs text-gray-600 file:mr-2 file:rounded file:border-0 file:bg-emerald-700 file:px-2.5 file:py-1 file:text-white"
                                            @change="cobroCreditoForm.comprobante = $event.target.files[0] || null"
                                        />
                                    </div>
                                </div>

                                <div class="flex justify-end">
                                    <button
                                        type="submit"
                                        :disabled="cobroCreditoForm.processing || !cobroCreditoForm.comprobante || Number(cobroCreditoForm.monto_pagado) <= 0"
                                        class="rounded-md bg-emerald-700 px-4 py-2 text-xs font-bold text-white hover:bg-emerald-800 disabled:opacity-50"
                                    >
                                        {{ cobroCreditoForm.processing ? 'Registrando en Cobranzas y Caja...' : 'Registrar cobro y asentar en Caja' }}
                                    </button>
                                </div>
                            </form>

                            <!-- Historial de Cobros del Crédito (pagos_credito + vínculo con Caja) -->
                            <div v-if="venta.credito.pagos && venta.credito.pagos.length" class="space-y-2">
                                <p class="text-xs font-bold uppercase tracking-wide text-gray-800">
                                    Historial de Abonos de Crédito y Asientos de Caja
                                </p>
                                <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                                    <table class="min-w-full divide-y divide-gray-200 text-xs">
                                        <thead class="bg-gray-50 text-left font-semibold uppercase text-gray-600">
                                            <tr>
                                                <th class="px-3 py-2">Fecha</th>
                                                <th class="px-3 py-2">Cuota</th>
                                                <th class="px-3 py-2">Método / Ref.</th>
                                                <th class="px-3 py-2">Caja</th>
                                                <th class="px-3 py-2 text-right">Monto</th>
                                                <th class="px-3 py-2 text-right">Comprobante</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            <tr v-for="pc in venta.credito.pagos" :key="pc.id_pago_credito">
                                                <td class="px-3 py-2 font-mono text-gray-700">{{ pc.fecha_pago }}</td>
                                                <td class="px-3 py-2 text-gray-800">{{ pc.numero_cuota ? `Cuota #${pc.numero_cuota}` : 'General' }}</td>
                                                <td class="px-3 py-2 uppercase text-gray-700">
                                                    {{ pc.metodo_pago }}
                                                    <span v-if="pc.numero_referencia" class="font-mono text-[11px] text-gray-500">({{ pc.numero_referencia }})</span>
                                                </td>
                                                <td class="px-3 py-2 font-mono text-[11px] text-indigo-700">
                                                    {{ pc.id_transaccion_caja ? `Asiento #${pc.id_transaccion_caja}` : 'Pendiente' }}
                                                </td>
                                                <td class="px-3 py-2 text-right font-mono font-bold text-gray-900">{{ formatearMoneda(pc.monto_pagado) }}</td>
                                                <td class="px-3 py-2 text-right">
                                                    <a
                                                        v-if="pc.comprobante_url"
                                                        :href="pc.comprobante_url"
                                                        class="font-semibold text-emerald-700 hover:text-emerald-900"
                                                    >
                                                        Descargar
                                                    </a>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Respaldo visual si aún no existe registro normalizado en creditos -->
                        <div
                            v-else-if="venta.forma_pago === 'credito' && venta.plan_cuotas_credito && venta.plan_cuotas_credito.length"
                            class="space-y-2"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                <p class="font-bold uppercase tracking-wide text-indigo-900">
                                    Cronograma de Cuotas del Crédito ({{ venta.plan_cuotas_credito.length }} cuotas)
                                </p>
                                <span class="font-mono font-bold text-gray-800">
                                    Total plan: {{ formatearMoneda(totalPlanCreditoRegistrado) }}
                                </span>
                            </div>
                            <div class="max-h-60 overflow-y-auto rounded-lg border border-indigo-200">
                                <table class="min-w-full divide-y divide-gray-200 text-xs">
                                    <thead class="sticky top-0 bg-indigo-50 text-left font-semibold uppercase tracking-wide text-indigo-900">
                                        <tr>
                                            <th class="px-4 py-2">Cuota</th>
                                            <th class="px-4 py-2">Fecha de vencimiento</th>
                                            <th class="px-4 py-2 text-right">Valor</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 bg-white">
                                        <tr v-for="cuota in venta.plan_cuotas_credito" :key="cuota.numero">
                                            <td class="px-4 py-2 font-semibold text-gray-800">Cuota #{{ cuota.numero }}</td>
                                            <td class="px-4 py-2 font-mono text-gray-700">{{ cuota.fecha_vencimiento }}</td>
                                            <td class="px-4 py-2 text-right font-mono font-bold text-gray-900">{{ formatearMoneda(cuota.monto) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- TABLA UNIFICADA DE PAGOS / CUOTAS DE CONTADO (desde cuotas_credito + pagos_credito) -->
                        <div
                            v-if="venta.forma_pago !== 'credito' && venta.credito && venta.credito.cuotas && venta.credito.cuotas.length"
                            class="space-y-2"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                <p class="font-bold uppercase tracking-wide text-emerald-900">
                                    Pagos registrados ({{ venta.credito.cuotas.length }} registro(s) en cuotas_credito)
                                </p>
                                <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-bold uppercase text-emerald-800">
                                    {{ venta.credito.estado }}
                                </span>
                            </div>
                            <div class="overflow-x-auto rounded-lg border border-emerald-200 bg-white">
                                <table class="min-w-full divide-y divide-gray-200 text-xs">
                                    <thead class="bg-emerald-50 text-left font-semibold uppercase tracking-wide text-emerald-900">
                                        <tr>
                                            <th class="px-3 py-2.5">N°</th>
                                            <th class="px-3 py-2.5">Fecha de pago</th>
                                            <th class="px-3 py-2.5 text-right">Capital</th>
                                            <th class="px-3 py-2.5 text-right">Interés</th>
                                            <th class="px-3 py-2.5 text-right">Valor</th>
                                            <th class="px-3 py-2.5 text-right">Pagado</th>
                                            <th class="px-3 py-2.5">Estado</th>
                                            <th class="px-3 py-2.5">Caja</th>
                                            <th class="px-3 py-2.5 text-right">Comprobante privado</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 bg-white">
                                        <tr v-for="cuota in venta.credito.cuotas" :key="cuota.id_cuota_credito">
                                            <td class="px-3 py-2 font-semibold text-gray-800">#{{ cuota.numero_cuota }}</td>
                                            <td class="px-3 py-2 font-mono text-gray-700">{{ cuota.fecha_pago_real || cuota.fecha_vencimiento }}</td>
                                            <td class="px-3 py-2 text-right font-mono text-gray-700">{{ formatearMoneda(cuota.monto_capital) }}</td>
                                            <td class="px-3 py-2 text-right font-mono text-gray-500">{{ formatearMoneda(cuota.monto_interes) }}</td>
                                            <td class="px-3 py-2 text-right font-mono font-bold text-gray-900">{{ formatearMoneda(cuota.valor_cuota) }}</td>
                                            <td class="px-3 py-2 text-right font-mono font-bold text-emerald-700">{{ formatearMoneda(cuota.monto_pagado) }}</td>
                                            <td class="px-3 py-2">
                                                <span
                                                    :class="estadoCuotaClases(cuota.estado)"
                                                    class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase"
                                                >
                                                    {{ cuota.estado }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-2 font-mono text-[11px] text-indigo-700">
                                                {{ cuota.id_transaccion_caja ? `Asiento #${cuota.id_transaccion_caja}` : 'Registrado' }}
                                            </td>
                                            <td class="px-3 py-2 text-right">
                                                <a
                                                    v-if="cuota.comprobante_url"
                                                    :href="cuota.comprobante_url"
                                                    class="font-semibold text-emerald-700 hover:text-emerald-900"
                                                >
                                                    Descargar comprobante
                                                </a>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div v-else-if="venta.pagos && venta.pagos.length" class="overflow-x-auto rounded-lg border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200 text-xs">
                                <thead class="bg-gray-50 text-left font-semibold uppercase tracking-wide text-gray-600">
                                    <tr>
                                        <th class="px-4 py-2.5">Tipo</th>
                                        <th class="px-4 py-2.5">Fecha</th>
                                        <th class="px-4 py-2.5 text-right">Monto</th>
                                        <th class="px-4 py-2.5">Registrado por</th>
                                        <th class="px-4 py-2.5 text-right">Comprobante privado</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    <tr v-for="pago in venta.pagos" :key="pago.id_pago">
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

                        <form
                            v-if="saldoPendienteRegistrado > 0.009 && venta.forma_pago !== 'credito'"
                            class="space-y-3 rounded-lg border border-amber-200 bg-amber-50/50 p-4"
                            @submit.prevent="registrarAbonoAdicional"
                        >
                            <p class="text-xs font-bold uppercase tracking-wide text-amber-900">
                                + Añadir otro comprobante / abono (pendiente {{ formatearMoneda(saldoPendienteRegistrado) }})
                            </p>
                            <div class="grid grid-cols-1 items-end gap-3 sm:grid-cols-12">
                                <div class="sm:col-span-5">
                                    <label class="block text-xs font-medium text-gray-700">Nuevo comprobante *</label>
                                    <input
                                        ref="abonoInputRef"
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp,.pdf"
                                        required
                                        class="mt-1 block w-full text-xs text-gray-600 file:mr-2 file:rounded file:border-0 file:bg-emerald-700 file:px-2.5 file:py-1.5 file:text-white"
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
                                        class="w-full rounded-md bg-emerald-700 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-800 disabled:opacity-50"
                                    >
                                        {{ abonoForm.processing ? 'Guardando...' : 'Agregar abono' }}
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </section>

                <!-- PASO 3: NUEVO MÓDULO DE GENERACIÓN DE CONSTANCIA DE VENTA (BASADO EN tipo_contrato DEL VEHÍCULO) -->
                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex flex-col justify-between gap-3 border-b border-gray-200 bg-gray-900 px-5 py-4 text-white sm:flex-row sm:items-center">
                        <div>
                            <h3 class="text-sm font-bold uppercase tracking-wide text-yellow-400">
                                Paso 3 · Generación de Constancia de {{ operacionForm.tipo_operacion === 'reserva' ? 'Reserva' : 'Venta' }}
                            </h3>
                            <p class="mt-0.5 text-xs text-gray-300">
                                Emisión documental automatizada según el tipo de contrato con el que ingresó el vehículo en Compras.
                            </p>
                        </div>
                        <span
                            v-if="constanciaUrlActual"
                            class="w-fit rounded-full bg-emerald-500/20 px-3 py-1 text-xs font-bold text-emerald-300"
                        >
                            ✓ Constancia generada
                        </span>
                        <span
                            v-else
                            class="w-fit rounded-full bg-indigo-500/20 px-3 py-1 text-xs font-bold text-indigo-200"
                        >
                            Pendiente de emisión
                        </span>
                    </div>

                    <div class="space-y-4 p-5">
                        <!-- Matriz 2x2 de selección automática de los 4 modelos de constancia -->
                        <div class="grid grid-cols-1 gap-4 rounded-lg border border-indigo-200 bg-indigo-50/40 p-4 sm:grid-cols-3">
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-wide text-indigo-900">
                                    Tipo de contrato del vehículo (BD)
                                </p>
                                <p class="mt-1 text-sm font-extrabold text-gray-900">
                                    {{ venta.vehiculo?.tipo_contrato_nombre || 'Propietario (Por defecto)' }}
                                </p>
                                <p class="mt-0.5 text-xs text-gray-600">
                                    Categoría:
                                    <span class="rounded bg-indigo-100 px-1.5 py-0.5 font-mono text-[11px] font-bold text-indigo-900">
                                        {{ venta.constancia_estrategia?.categoria || 'PROPIO' }}
                                    </span>
                                    · Modalidad:
                                    <span class="rounded bg-slate-200 px-1.5 py-0.5 font-mono text-[11px] font-bold text-slate-900">
                                        {{ venta.constancia_estrategia?.modalidad || 'CONTADO' }}
                                    </span>
                                </p>
                            </div>
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-wide text-indigo-900">
                                    Modelo Word seleccionado
                                </p>
                                <p class="mt-1 text-sm font-extrabold text-indigo-950">
                                    {{ venta.constancia_estrategia?.etiqueta || 'Constancia de Compra-Venta' }}
                                </p>
                                <p class="mt-0.5 font-mono text-xs font-semibold text-indigo-700">
                                    {{ venta.constancia_estrategia?.archivo_plantilla || 'Contado_propio.docx' }}
                                </p>
                            </div>
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-wide text-indigo-900">
                                    {{ requiereClienteVendedor ? 'Propietario anterior (Requerido PODER)' : 'Titular / Vendedor en documento' }}
                                </p>
                                <p v-if="requiereClienteVendedor" class="mt-1 text-sm font-bold text-gray-900">
                                    {{ datosVendedorAnterior.nombre_cliente_vendedor || 'Pendiente de completar al emitir' }}
                                </p>
                                <p v-else class="mt-1 text-sm font-bold text-gray-900">
                                    HUGO DANIEL SORIA ROBALINO (Propio)
                                </p>
                                <p v-if="requiereClienteVendedor && datosVendedorAnterior.cedula_cliente_vendedor" class="mt-0.5 font-mono text-xs text-gray-600">
                                    CI/RUC: {{ datosVendedorAnterior.cedula_cliente_vendedor }}
                                </p>
                            </div>
                        </div>

                        <!-- Indicadores de verificación previa -->
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span
                                class="rounded-full px-3 py-1 font-semibold"
                                :class="cedulaUrlActual ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900'"
                            >
                                {{ cedulaUrlActual ? '✓ Cédula cargada' : '• Cédula pendiente (Paso 1)' }}
                            </span>
                            <span
                                class="rounded-full px-3 py-1 font-semibold"
                                :class="puedeGenerarConstancia ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900'"
                            >
                                {{ puedeGenerarConstancia ? '✓ Precio y crédito autorizados' : '• Pendiente autorización de precio o crédito (Paso 2)' }}
                            </span>
                            <span
                                v-if="camposFaltantesConstancia.length > 0"
                                class="rounded-full bg-amber-100 px-3 py-1 font-semibold text-amber-900"
                            >
                                • Datos por completar al emitir: {{ camposFaltantesConstancia.map((c) => c.label).join(', ') }}
                            </span>
                            <span
                                v-else
                                class="rounded-full bg-emerald-100 px-3 py-1 font-semibold text-emerald-800"
                            >
                                ✓ Datos del comprador completos
                            </span>
                        </div>

                        <p v-if="errorConstancia" role="alert" class="rounded-md border border-red-200 bg-red-50 p-3 text-xs font-medium text-red-700">
                            {{ errorConstancia }}
                        </p>

                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <a
                                    v-if="constanciaUrlActual"
                                    :href="constanciaUrlActual"
                                    class="inline-flex items-center rounded-md bg-blue-700 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-blue-600"
                                >
                                    📄 Descargar constancia actual (.docx)
                                </a>
                            </div>
                            <button
                                type="button"
                                :disabled="!puedeGenerarConstancia || generandoConstancia"
                                class="rounded-md bg-yellow-500 px-5 py-2.5 text-sm font-bold text-gray-900 shadow-sm hover:bg-yellow-400 disabled:cursor-not-allowed disabled:opacity-40"
                                @click="iniciarGeneracionConstancia"
                            >
                                {{
                                    generandoConstancia
                                        ? 'Generando constancia...'
                                        : constanciaUrlActual
                                            ? 'Regenerar Constancia (.docx)'
                                            : 'Generar Constancia (.docx)'
                                }}
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <!-- MODAL RÁPIDO: COMPLETAR SOLO DATOS FALTANTES DEL COMPRADOR (PARA PAGARÉ O CONSTANCIA) -->
        <Modal :show="mostrarModalFaltantes" max-width="lg" @close="mostrarModalFaltantes = false">
            <div class="p-6">
                <div class="flex items-start justify-between gap-4 border-b border-gray-200 pb-3">
                    <div>
                        <h3 class="text-base font-bold text-gray-900">
                            {{
                                modoModalFaltantes === 'pagare'
                                    ? 'Completar datos requeridos para el Pagaré'
                                    : modoModalFaltantes === 'constancia'
                                        ? 'Completar datos requeridos para la Constancia'
                                        : 'Datos del Comprador'
                            }}
                        </h3>
                        <p class="mt-1 text-xs text-gray-600">
                            {{
                                modoModalFaltantes === 'editar'
                                    ? 'Revisa o actualiza los datos sincronizados del comprador. Si existe un crédito, el Pagaré se regenerará automáticamente.'
                                    : 'Solo necesitamos completar los siguientes campos faltantes para emitir el documento (.docx).'
                            }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-md px-2 py-1 text-sm font-semibold text-gray-500 hover:bg-gray-100 hover:text-gray-800"
                        @click="mostrarModalFaltantes = false"
                    >
                        ✕
                    </button>
                </div>

                <form class="mt-4 space-y-4" @submit.prevent="completarDatosYContinuar">
                    <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-gray-700">Nombre completo del comprador *</label>
                            <input
                                v-model="datosCedula.nombre_completo"
                                type="text"
                                required
                                maxlength="200"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700">Número de cédula / RUC *</label>
                            <input
                                v-model="datosCedula.cedula"
                                type="text"
                                required
                                maxlength="30"
                                class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700">Correo electrónico (citación COGEP) *</label>
                            <input
                                v-model="datosCedula.correo"
                                type="email"
                                required
                                maxlength="120"
                                placeholder="cliente@correo.com"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                            />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-gray-700">Dirección domiciliaria *</label>
                            <input
                                v-model="datosCedula.direccion"
                                type="text"
                                required
                                maxlength="200"
                                placeholder="Provincia / Cantón / Parroquia / Dirección"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700">
                                Teléfono del comprador {{ modoModalFaltantes === 'constancia' ? '*' : '(opcional)' }}
                            </label>
                            <input
                                v-model="datosCedula.telefono"
                                type="text"
                                :required="modoModalFaltantes === 'constancia'"
                                maxlength="30"
                                placeholder="Ej: 0999999999"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700">Estado civil</label>
                            <input
                                v-model="datosCedula.estado_civil"
                                type="text"
                                maxlength="60"
                                placeholder="Ej: SOLTERO, CASADO, DIVORCIADO"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                            />
                        </div>
                    </div>

                    <!-- Datos del propietario anterior (solo cuando el modelo es PODER) -->
                    <div
                        v-if="requiereClienteVendedor && modoModalFaltantes !== 'pagare'"
                        class="space-y-3 rounded-md border border-amber-300 bg-amber-50/70 p-3.5"
                    >
                        <p class="text-xs font-bold text-amber-950">
                            Datos del Propietario Anterior (Requerido para modelo PODER / Mandato / Firma Directa)
                        </p>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label class="block text-xs font-semibold text-gray-800">Nombre completo propietario anterior *</label>
                                <input
                                    v-model="datosVendedorAnterior.nombre_cliente_vendedor"
                                    type="text"
                                    required
                                    maxlength="200"
                                    placeholder="Nombres y apellidos"
                                    class="mt-1 block w-full rounded-md border-gray-300 bg-white text-sm shadow-sm"
                                />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-800">Cédula / RUC propietario anterior *</label>
                                <input
                                    v-model="datosVendedorAnterior.cedula_cliente_vendedor"
                                    type="text"
                                    required
                                    maxlength="30"
                                    placeholder="Ej: 1801234567"
                                    class="mt-1 block w-full rounded-md border-gray-300 bg-white font-mono text-sm shadow-sm"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <input
                            id="venta-es-casado"
                            v-model="datosCedula.es_casado"
                            type="checkbox"
                            class="h-4 w-4 rounded border-gray-300 text-indigo-600"
                        />
                        <label for="venta-es-casado" class="text-xs font-medium text-gray-800">
                            El comprador es casado / unión de hecho (incluir cónyuge en la constancia)
                        </label>
                    </div>

                    <div
                        v-if="datosCedula.es_casado"
                        class="grid grid-cols-1 gap-3 rounded-md border border-indigo-200 bg-indigo-50/50 p-3 sm:grid-cols-2"
                    >
                        <div>
                            <label class="block text-xs font-semibold text-gray-700">Nombre completo del cónyuge *</label>
                            <input
                                v-model="datosCedula.nombre_conyuge"
                                type="text"
                                :required="datosCedula.es_casado"
                                maxlength="200"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700">Cédula del cónyuge (opcional)</label>
                            <input
                                v-model="datosCedula.cedula_conyuge"
                                type="text"
                                maxlength="30"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                            />
                        </div>
                    </div>

                    <p v-if="errorModalFaltantes" role="alert" class="rounded-md border border-red-200 bg-red-50 p-3 text-xs font-medium text-red-700">
                        {{ errorModalFaltantes }}
                    </p>

                    <div class="flex flex-col-reverse justify-end gap-3 border-t border-gray-200 pt-4 sm:flex-row">
                        <button
                            type="button"
                            class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            @click="mostrarModalFaltantes = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="guardandoComprador || generandoConstancia || !datosCedula.nombre_completo.trim() || !datosCedula.cedula.trim()"
                            class="rounded-md bg-gray-900 px-5 py-2 text-sm font-semibold text-yellow-400 hover:bg-gray-800 disabled:opacity-50"
                        >
                            {{
                                guardandoComprador || generandoConstancia
                                    ? 'Procesando...'
                                    : modoModalFaltantes === 'pagare'
                                        ? 'Guardar y descargar Pagaré (.docx)'
                                        : modoModalFaltantes === 'constancia'
                                            ? 'Guardar y generar Constancia (.docx)'
                                            : 'Guardar datos del comprador'
                            }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
