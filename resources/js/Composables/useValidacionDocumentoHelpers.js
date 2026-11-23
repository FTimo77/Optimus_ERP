export const estadoTexto = (estado) => ({
    pendiente: 'Pendiente de revisión',
    pendiente_comprobante: 'Pendiente subir comprobante de pago',
    pendiente_constancia: 'Pendiente generar constancia',
    aprobada: 'Aprobado',
    rechazada: 'Rechazada',
    registrada: 'Registrada en inventario',
}[estado] || estado);

export const estadoClases = (estado) => ({
    pendiente: 'bg-amber-100 text-amber-900',
    pendiente_comprobante: 'bg-yellow-100 text-yellow-900',
    pendiente_constancia: 'bg-indigo-100 text-indigo-900',
    aprobada: 'bg-green-100 text-green-900',
    rechazada: 'bg-red-100 text-red-900',
    registrada: 'bg-blue-100 text-blue-900',
}[estado] || 'bg-gray-100 text-gray-800');

export const fechaTexto = (fecha) => (
    fecha ? new Date(fecha).toLocaleString('es-EC') : 'No disponible'
);

export const valorTexto = (valor) => {
    if (valor === null || valor === undefined || valor === '') {
        return 'No disponible';
    }

    return typeof valor === 'object' ? JSON.stringify(valor) : String(valor);
};

export const formatearMoneda = (valor) =>
    `$${Number(valor || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export const campoIngresoTexto = (campo) => ({
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
