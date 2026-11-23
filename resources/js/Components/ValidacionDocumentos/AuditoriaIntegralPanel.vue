<script setup>
import { computed } from 'vue';

const props = defineProps({
    validacion: {
        type: Object,
        required: true,
    },
});

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
</script>

<template>
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
</template>
