import { computed, ref } from 'vue';

export const ITEMS_CUSTODIA = [
    { key: 'matricula', label: 'Documento de matrícula' },
    { key: 'rtv', label: 'Revisión Técnica Vehicular (RTV)' },
    { key: 'contrato_legalizado', label: 'Contrato legalizado' },
    { key: 'llaves', label: 'Llaves del vehículo' },
];

export const formatearFecha = (fecha) => {
    if (!fecha) return 'No registrada';
    return new Date(fecha).toLocaleString('es-EC', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

export const formatearFechaSolo = (fecha) => {
    if (!fecha) return 'No registrada';
    const match = String(fecha).match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (match) {
        return `${match[3]}/${match[2]}/${match[1]}`;
    }
    return new Date(fecha).toLocaleDateString('es-EC');
};

export const formatearMoneda = (valor) =>
    `$${Number(valor || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export const infoMetodoIngreso = (metodo) => {
    const valor = String(metodo || '').trim().toLowerCase();

    if (valor === 'particular') {
        return {
            clave: 'particular',
            etiqueta: 'Particular',
            clasesBadge: 'bg-sky-100 text-sky-800 border-sky-200',
            clasesHeader: 'bg-sky-700 text-sky-100',
        };
    }

    if (valor === 'empresa') {
        return {
            clave: 'empresa',
            etiqueta: 'Empresa',
            clasesBadge: 'bg-indigo-100 text-indigo-800 border-indigo-200',
            clasesHeader: 'bg-indigo-700 text-indigo-100',
        };
    }

    if (valor === 'nuevos') {
        return {
            clave: 'nuevos',
            etiqueta: 'Nuevos',
            clasesBadge: 'bg-emerald-100 text-emerald-800 border-emerald-200',
            clasesHeader: 'bg-emerald-700 text-emerald-100',
        };
    }

    return {
        clave: '',
        etiqueta: 'No registrado',
        clasesBadge: 'bg-gray-100 text-gray-600 border-gray-200',
        clasesHeader: 'bg-gray-700 text-gray-200',
    };
};

export const estadoCaducidadRtv = (fechaCaducidad) => {
    if (!fechaCaducidad) {
        return {
            estado: 'sin_dato',
            etiqueta: 'RTV sin fecha',
            detalle: 'Sin fecha de caducidad registrada',
            clasesTabla: 'border-gray-200 bg-gray-50 text-gray-600',
            clasesHeader: 'bg-gray-700 text-gray-200',
        };
    }

    const match = String(fechaCaducidad).match(/^(\d{4})-(\d{2})-(\d{2})/);
    const fechaObjetivo = match
        ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
        : new Date(fechaCaducidad);

    if (Number.isNaN(fechaObjetivo.getTime())) {
        return {
            estado: 'sin_dato',
            etiqueta: 'RTV sin fecha',
            detalle: 'Fecha no válida',
            clasesTabla: 'border-gray-200 bg-gray-50 text-gray-600',
            clasesHeader: 'bg-gray-700 text-gray-200',
        };
    }

    const hoy = new Date();
    hoy.setHours(0, 0, 0, 0);
    fechaObjetivo.setHours(0, 0, 0, 0);

    const diffDias = Math.round((fechaObjetivo.getTime() - hoy.getTime()) / (1000 * 60 * 60 * 24));
    const fechaTexto = formatearFechaSolo(fechaCaducidad);

    if (diffDias < 0) {
        return {
            estado: 'vencida',
            etiqueta: 'RTV Vencida',
            detalle: `Vencida hace ${Math.abs(diffDias)} día(s) (${fechaTexto})`,
            clasesTabla: 'border-red-300 bg-red-100 text-red-800',
            clasesHeader: 'bg-red-600 text-white',
        };
    }

    if (diffDias <= 30) {
        return {
            estado: 'proxima',
            etiqueta: 'RTV Próxima a vencer',
            detalle: diffDias === 0
                ? `Vence hoy (${fechaTexto})`
                : `Vence en ${diffDias} día(s) (${fechaTexto})`,
            clasesTabla: 'border-amber-300 bg-amber-100 text-amber-900',
            clasesHeader: 'bg-amber-500 text-gray-900',
        };
    }

    return {
        estado: 'vigente',
        etiqueta: 'RTV Vigente',
        detalle: `Vigente hasta ${fechaTexto}`,
        clasesTabla: 'border-green-200 bg-green-50 text-green-700',
        clasesHeader: 'bg-emerald-700 text-emerald-100',
    };
};

export const documentosFaltantes = (auto) => {
    const faltantes = [];
    const custodias = Array.isArray(auto.custodia_actual) ? auto.custodia_actual : [];
    const estadoItem = (clave) => custodias.find((c) => c.item === clave)?.estado || 'sin_asignar';

    const estadoMatricula = estadoItem('matricula');
    if (estadoMatricula === 'pendiente_recepcion') {
        faltantes.push({ texto: 'Matrícula (pend. recibir)', severidad: 'amber' });
    } else if (estadoMatricula !== 'recibido') {
        faltantes.push({ texto: 'Matrícula', severidad: 'red' });
    }

    const estadoRtvCustodia = estadoItem('rtv');
    const rtv = estadoCaducidadRtv(auto.fecha_caducidad_revision);
    if (rtv.estado === 'vencida') {
        faltantes.push({ texto: 'RTV vencida', severidad: 'red' });
    } else if (estadoRtvCustodia === 'pendiente_recepcion') {
        faltantes.push({ texto: 'RTV (pend. recibir)', severidad: 'amber' });
    } else if (estadoRtvCustodia !== 'recibido') {
        faltantes.push({ texto: 'RTV', severidad: 'red' });
    } else if (rtv.estado === 'proxima') {
        faltantes.push({ texto: 'RTV por vencer', severidad: 'amber' });
    }

    const tipoContratoNombre = (auto.tipo_contrato?.nombre || auto.tipo_contrato_nombre || '').trim().toLowerCase();
    const esFirmaDirecta = tipoContratoNombre === 'firma directa' || auto.requiere_documento_contrato === false;
    if (!esFirmaDirecta) {
        const estadoContrato = estadoItem('contrato_legalizado');
        if (auto.contrato_pendiente) {
            faltantes.push({ texto: 'Contrato (sin archivo)', severidad: 'amber' });
        } else if (estadoContrato === 'pendiente_recepcion') {
            faltantes.push({ texto: 'Contrato (pend. recibir)', severidad: 'amber' });
        } else if (estadoContrato !== 'recibido') {
            faltantes.push({ texto: 'Contrato', severidad: 'red' });
        }
    }

    return faltantes;
};

export const tieneAlertaPagosMultas = (auto) => {
    const tieneMultas = Number(auto.multas_pendientes_count || 0) > 0;
    const tieneSaldoPendiente = Number(auto.saldo_pendiente_pago || 0) > 0.009;
    return tieneMultas || Boolean(auto.credito_vencido) || tieneSaldoPendiente;
};

export const tieneAlertaVehiculo = (auto) =>
    tieneAlertaPagosMultas(auto) || documentosFaltantes(auto).length > 0;

export const useInventarioFiltros = (obtenerVehiculos) => {
    const busqueda = ref('');
    const filtroMarca = ref('');
    const filtroEstado = ref('');
    const filtroPatio = ref('');
    const filtroAlerta = ref('');
    const filtroMetodoIngreso = ref('');

    const marcasDisponibles = computed(() => {
        const unicas = new Set();
        for (const auto of obtenerVehiculos() || []) {
            if (auto.marca?.nombre) unicas.add(auto.marca.nombre);
        }
        return Array.from(unicas).sort((a, b) => a.localeCompare(b, 'es'));
    });

    const estadosDisponibles = computed(() => {
        const unicos = new Set();
        for (const auto of obtenerVehiculos() || []) {
            if (auto.estado?.nombre) unicos.add(auto.estado.nombre);
        }
        return Array.from(unicos).sort((a, b) => a.localeCompare(b, 'es'));
    });

    const patiosDisponibles = computed(() => {
        const unicos = new Set();
        for (const auto of obtenerVehiculos() || []) {
            unicos.add(auto.patio?.nombre || 'Sin asignar');
        }
        return Array.from(unicos).sort((a, b) => a.localeCompare(b, 'es'));
    });

    const hayFiltrosActivos = computed(() => Boolean(
        busqueda.value.trim()
        || filtroMarca.value
        || filtroEstado.value
        || filtroPatio.value
        || filtroAlerta.value
        || filtroMetodoIngreso.value,
    ));

    const limpiarFiltros = () => {
        busqueda.value = '';
        filtroMarca.value = '';
        filtroEstado.value = '';
        filtroPatio.value = '';
        filtroAlerta.value = '';
        filtroMetodoIngreso.value = '';
    };

    const vehiculosFiltrados = computed(() => {
        const termino = busqueda.value.trim().toLowerCase();

        return (obtenerVehiculos() || []).filter((auto) => {
            if (termino) {
                const nombreVehiculo = `${auto.marca?.nombre || ''} ${auto.modelo || ''}`.toLowerCase();
                const placa = String(auto.id_vehiculo || auto.placa || '').toLowerCase();
                if (!nombreVehiculo.includes(termino) && !placa.includes(termino)) {
                    return false;
                }
            }

            if (filtroMarca.value && (auto.marca?.nombre || '') !== filtroMarca.value) {
                return false;
            }

            if (filtroEstado.value && (auto.estado?.nombre || '') !== filtroEstado.value) {
                return false;
            }

            if (filtroPatio.value && (auto.patio?.nombre || 'Sin asignar') !== filtroPatio.value) {
                return false;
            }

            if (filtroMetodoIngreso.value) {
                const metodo = String(auto.metodo_ingreso || '').trim().toLowerCase();
                if (metodo !== filtroMetodoIngreso.value.toLowerCase()) {
                    return false;
                }
            }

            if (filtroAlerta.value === 'en_alerta' && !tieneAlertaVehiculo(auto)) {
                return false;
            }

            if (filtroAlerta.value === 'sin_alerta' && tieneAlertaVehiculo(auto)) {
                return false;
            }

            return true;
        });
    });

    return {
        busqueda,
        filtroMarca,
        filtroEstado,
        filtroPatio,
        filtroAlerta,
        filtroMetodoIngreso,
        marcasDisponibles,
        estadosDisponibles,
        patiosDisponibles,
        hayFiltrosActivos,
        limpiarFiltros,
        vehiculosFiltrados,
    };
};
