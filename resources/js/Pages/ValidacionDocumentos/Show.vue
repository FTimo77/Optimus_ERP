<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

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
const puedeReenviar = computed(() => form.comentario_reenvio.trim().length > 0);

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
                            v-if="validacion.cuv_url"
                            :href="validacion.cuv_url"
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
                    v-if="validacion.resumen_auditoria"
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
                            <input
                                id="documento-cuv"
                                type="file"
                                accept="image/jpeg,image/png,image/webp,.pdf"
                                class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-700 file:px-3 file:py-2 file:text-white hover:file:bg-indigo-600"
                                @input="form.documento_cuv = $event.target.files[0] || null"
                            />
                            <p class="mt-1 text-xs text-gray-500">
                                PDF o imagen, máximo 5 MB. Se adjuntará al expediente al aprobarlo o rechazarlo.
                            </p>
                            <p
                                v-if="form.errors.documento_cuv"
                                class="mt-1 text-sm text-red-600"
                                role="alert"
                            >
                                {{ form.errors.documento_cuv }}
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
