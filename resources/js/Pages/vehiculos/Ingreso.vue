<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import axios from 'axios';

// 1. Catálogos iniciales que envía Laravel
const props = defineProps({
    marcas: Array,
    clases: Array,
    paises: Array,
    colores: Array,
    combustibles: Array,
    transmisiones: Array,
    success: String,
});

// 2. Copias reactivas de los catálogos (por si el buscador agrega una marca, color o país nuevo en vivo)
const listaMarcas = ref([...props.marcas]);
const listaClases = ref([...props.clases]);
const listaPaises = ref([...props.paises]);
const listaColores = ref([...props.colores]);
const imagenesInput = ref(null);

// 3. Estado del buscador por placa
const cargandoPlaca = ref(false);
const mensajePlaca = ref('');
const tipoMensaje = ref(''); // 'exito' o 'error'
const categoriaSeleccionada = ref('');
const mostrarFormularioIngreso = computed(() => ['particular', 'empresa'].includes(categoriaSeleccionada.value));

// 4. Formulario conectado con las columnas de tu modelo Vehiculo.php
const form = useForm({
    id_vehiculo: '',
    id_marca: '',
    id_clase: '',
    id_pais: '',
    id_color: '',
    id_tipo_combustible: '',
    id_transmision: '',
    modelo: '',
    anio: new Date().getFullYear(),
    motor: '',
    chasis: '',
    cilindraje: '',
    servicio: '',
    tipo_vehiculo: '',
    costo_adquisicion: '',
    imagenes: [],
});

const erroresImagenes = computed(() =>
    Object.entries(form.errors)
        .filter(([campo]) => campo.startsWith('imagenes.'))
        .map(([, error]) => error),
);

// 5. Función que consulta la placa en segundo plano
const buscarPorPlaca = async () => {
    if (!form.id_vehiculo.trim()) {
        tipoMensaje.value = 'error';
        mensajePlaca.value = 'Por favor escribe un número de placa primero.';
        return;
    }

    cargandoPlaca.value = true;
    mensajePlaca.value = '';

    try {
        const res = await axios.post(route('vehiculos.consultar.axis'), {
            placa: form.id_vehiculo,
        });

        if (res.data.success) {
            const v = res.data.vehiculo;

            // Actualizamos las listas de los <select> por si se creó una marca/color/país nuevo
            listaMarcas.value = res.data.catalogos.marcas;
            listaPaises.value = res.data.catalogos.paises;
            listaColores.value = res.data.catalogos.colores;
            listaClases.value = res.data.catalogos.clases;

            // Rellenamos automáticamente los campos del formulario
            if (v.chasis) form.chasis = v.chasis;
            if (v.motor) form.motor = v.motor;
            if (v.modelo) form.modelo = v.modelo;
            if (v.anio) form.anio = v.anio;
            if (v.cilindraje) form.cilindraje = String(v.cilindraje);
            if (v.id_marca) form.id_marca = v.id_marca;
            if (v.id_pais) form.id_pais = v.id_pais;
            if (v.id_color) form.id_color = v.id_color;
            if (v.id_clase) form.id_clase = v.id_clase;
            if (v.servicio) form.servicio = v.servicio;
            if (v.tipo_vehiculo) form.tipo_vehiculo = v.tipo_vehiculo;

            tipoMensaje.value = 'exito';
            mensajePlaca.value = '¡Datos del vehículo cargados automáticamente! Completa los campos restantes.';
        }
    } catch (error) {
        tipoMensaje.value = 'error';
        mensajePlaca.value = error.response?.data?.message || 'No se encontraron datos para esta placa. Llena el formulario manualmente.';
    } finally {
        cargandoPlaca.value = false;
    }
};

// 6. Envío del formulario a MySQL
const enviarFormulario = () => {
    form.post(route('vehiculos.store'), {
        onSuccess: () => {
            form.reset();
            if (imagenesInput.value) {
                imagenesInput.value.value = '';
            }
            mensajePlaca.value = '';
        },
    });
};

const archivoMatricula = ref(null);
const escaneandoIA = ref(false);
const resultadoAuditoria = ref(null);
const errorAuditoria = ref('');

const seleccionarMatricula = (e) => {
    archivoMatricula.value = e.target.files[0];
};

const escanearYComparar = async () => {
    if (!archivoMatricula.value) {
        alert('Selecciona primero una foto o PDF de la matrícula.');
        return;
    }

    escaneandoIA.value = true;
    errorAuditoria.value = '';
    resultadoAuditoria.value = null;

    const formData = new FormData();
    formData.append('documento', archivoMatricula.value);

    try {
        const res = await axios.post(route('vehiculos.escanear.matricula'), formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        });

        if (res.data && res.data.success) {
            resultadoAuditoria.value = res.data;
        } else {
            errorAuditoria.value = res.data?.message || 'El servidor no devolvió una respuesta válida.';
        }
    } catch (error) {
        errorAuditoria.value = error.response?.data?.message || error.message || 'Error al escanear el documento.';
    } finally {
        escaneandoIA.value = false;
    }
};
</script>

<template>
    <Head title="Ingreso de Vehículo" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Módulo de Compras: Ingreso de Vehículos
            </h2>
        </template>

        <div class="py-8">
            <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
                <div class="mb-6 rounded-lg bg-white p-5 shadow-sm">
                    <label for="categoria-formulario" class="block text-sm font-medium text-gray-700">
                        Categoría de ingreso
                    </label>
                    <select
                        id="categoria-formulario"
                        v-model="categoriaSeleccionada"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:max-w-sm"
                    >
                        <option value="" disabled>Seleccione una categoría...</option>
                        <option value="particular">Particular</option>
                        <option value="empresa">Empresa</option>
                    </select>
                </div>

                <!-- MÓDULO DE ESCANEO IA Y AUDITORÍA CRUZADA -->
                <div
                    v-if="categoriaSeleccionada === 'particular'"
                    class="mb-6 rounded-lg border border-slate-700 bg-slate-900 p-5 text-white shadow-md"
                >
                    <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
                        <div>
                            <h3 class="text-sm font-extrabold uppercase tracking-wider text-yellow-400">
                                Escáner IA de Matrícula vs. AxisCloud
                            </h3>
                            <p class="mt-1 text-xs text-gray-300">
                                Sube la foto o PDF de la matrícula para extraer sus datos con IA y compararlos con AxisCloud.
                            </p>
                            <p class="mt-1 text-xs text-gray-400">
                                El documento se enviará a Google Gemini para su análisis. Revisa los resultados antes de registrar el vehículo.
                            </p>
                        </div>

                        <div class="flex w-full items-center gap-3 md:w-auto">
                            <input
                                type="file"
                                @change="seleccionarMatricula"
                                accept="image/jpeg,image/png,image/webp,.pdf"
                                aria-label="Seleccionar matrícula en PDF o imagen"
                                class="text-xs text-gray-300 file:mr-3 file:rounded file:border-0 file:bg-gray-700 file:px-3 file:py-1.5 file:text-white hover:file:bg-gray-600"
                            />
                            <button
                                type="button"
                                @click="escanearYComparar"
                                :disabled="escaneandoIA"
                                class="whitespace-nowrap rounded bg-yellow-500 px-4 py-2 text-xs font-bold text-black shadow hover:bg-yellow-600 disabled:opacity-50"
                            >
                                {{ escaneandoIA ? 'Analizando con IA y AxisCloud...' : 'Auditar Matrícula' }}
                            </button>
                        </div>
                    </div>

                    <div
                        v-if="errorAuditoria"
                        role="alert"
                        class="mt-3 rounded border border-red-500 bg-red-900/50 p-3 text-xs text-red-200"
                    >
                        {{ errorAuditoria }}
                    </div>

                    <div
                        v-if="resultadoAuditoria"
                        class="mt-5 rounded-lg border border-gray-700 bg-gray-800 p-4"
                    >
                        <div class="mb-3 flex flex-col justify-between gap-2 md:flex-row">
                            <span
                                class="text-xs font-bold uppercase tracking-wider"
                                :class="resultadoAuditoria.alertas_criticas > 0
                                    ? 'text-red-400'
                                    : resultadoAuditoria.comparacion.some((item) => item.estado !== 'coincide')
                                        ? 'text-amber-400'
                                        : 'text-green-400'"
                            >
                                {{ resultadoAuditoria.alertas_criticas > 0
                                    ? `¡Atención! Se detectaron ${resultadoAuditoria.alertas_criticas} discrepancias críticas (VIN/Motor/Placa)`
                                    : resultadoAuditoria.comparacion.some((item) => item.estado !== 'coincide')
                                        ? 'Revisión parcial: hay datos diferentes o no verificables'
                                        : 'Verificación exitosa: datos comparados coinciden con AxisCloud' }}
                            </span>
                            <div class="text-xs text-gray-400">
                                <p>
                                    Propietario en documento:
                                    <strong class="text-white">
                                        {{ resultadoAuditoria.propietario_doc.nombre }}
                                        ({{ resultadoAuditoria.propietario_doc.cedula }})
                                    </strong>
                                </p>
                                <p>
                                    Emisión: {{ resultadoAuditoria.datos_documento.fecha_emision || 'No detectada' }}
                                    · Caducidad: {{ resultadoAuditoria.datos_documento.fecha_caduca || 'No detectada' }}
                                </p>
                                <p>
                                    Última revisión en AxisCloud:
                                    {{ resultadoAuditoria.datos_oficiales.fecha_ultima_revision_desde || 'No disponible' }}
                                    · Vigente hasta:
                                    {{ resultadoAuditoria.datos_oficiales.fecha_ultima_revision_hasta || 'No disponible' }}
                                    · Año:
                                    {{ resultadoAuditoria.datos_oficiales.anio_ultima_revision || 'No disponible' }}
                                </p>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs text-gray-200">
                                <thead class="bg-gray-900 uppercase text-gray-400">
                                    <tr>
                                        <th class="px-3 py-2">Campo</th>
                                        <th class="px-3 py-2">Leído en matrícula (IA)</th>
                                        <th class="px-3 py-2">Extraído de AxisCloud</th>
                                        <th class="px-3 py-2 text-center">Estado</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-700">
                                    <tr
                                        v-for="item in resultadoAuditoria.comparacion"
                                        :key="item.clave"
                                        :class="item.estado === 'diferente' && item.critico
                                            ? 'bg-red-950/40'
                                            : item.estado === 'casi_coincide' && item.critico
                                                ? 'bg-amber-950/40'
                                                : ''"
                                    >
                                        <td class="px-3 py-2 font-semibold">{{ item.campo }}</td>
                                        <td class="px-3 py-2 font-mono">{{ item.documento }}</td>
                                        <td class="px-3 py-2 font-mono">{{ item.sistema_web }}</td>
                                        <td class="px-3 py-2 text-center">
                                            <!-- Exact or accepted equivalent match -->
                                            <span
                                                v-if="item.estado === 'coincide' || item.coincide"
                                                class="rounded-full bg-green-900 px-2.5 py-0.5 text-[11px] font-bold text-green-300"
                                            >
                                                ✓ Coincide
                                            </span>

                                            <!-- Possible OCR difference in a serial number; manual review is required. -->
                                            <div
                                                v-else-if="item.estado === 'casi_coincide'"
                                                class="flex flex-col items-center"
                                            >
                                                <span class="rounded-full bg-amber-700 px-2.5 py-0.5 text-[11px] font-bold text-yellow-200">
                                                    ⚠ Casi idéntico
                                                </span>
                                                <span class="mt-0.5 text-[10px] text-yellow-400">
                                                    {{ item.nota }}
                                                </span>
                                            </div>

                                            <div
                                                v-else-if="item.estado === 'no_verificable'"
                                                class="flex flex-col items-center"
                                            >
                                                <span class="rounded-full bg-gray-700 px-2.5 py-0.5 text-[11px] font-bold text-gray-200">
                                                    No verificable
                                                </span>
                                                <span v-if="item.nota" class="mt-0.5 text-center text-[10px] text-gray-400">
                                                    {{ item.nota }}
                                                </span>
                                            </div>

                                            <span
                                                v-else
                                                class="rounded-full bg-red-900 px-2.5 py-0.5 text-[11px] font-bold text-red-300"
                                            >
                                                ✕ Diferente
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4 flex flex-col justify-between gap-3 rounded-md border border-blue-700 bg-blue-950/40 p-3 text-sm text-blue-100 sm:flex-row sm:items-center">
                            <p>
                                Expediente #{{ resultadoAuditoria.validacion_id }} creado y pendiente de revisión humana.
                            </p>
                            <Link
                                :href="route('validacion-documentos.show', resultadoAuditoria.validacion_id)"
                                class="inline-flex items-center justify-center rounded bg-blue-600 px-4 py-2 text-xs font-bold text-white hover:bg-blue-500"
                            >
                                Abrir expediente
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- BARRA DE AUTOCOMPLETADO POR PLACA (LIMPIA PARA EL USUARIO) -->
                <div
                    v-if="mostrarFormularioIngreso"
                    class="bg-gray-900 text-white p-5 rounded-lg shadow-md mb-6"
                >
                    <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                        <div>
                            <h3 class="text-sm font-extrabold text-yellow-400 uppercase tracking-wider">
                                Autocompletar Datos por Placa
                            </h3>
                            <p class="text-xs text-gray-300 mt-0.5">
                                Ingresa la placa del vehículo para rellenar Chasis, Motor, Marca, Modelo, Año y País automáticamente.
                            </p>
                        </div>

                        <div class="flex items-center gap-2 w-full md:w-auto">
                            <input
                                v-model="form.id_vehiculo"
                                @keyup.enter="buscarPorPlaca"
                                type="text"
                                placeholder="Placa (ej. PFI0582)"
                                :disabled="cargandoPlaca"
                                aria-label="Placa del vehículo"
                                class="w-full md:w-48 rounded-md border-gray-600 bg-gray-800 text-white uppercase font-mono px-3 py-2 text-sm focus:ring-yellow-500 focus:border-yellow-500"
                            />
                            <button
                                type="button"
                                @click="buscarPorPlaca"
                                :disabled="cargandoPlaca"
                                class="px-5 py-2 bg-yellow-500 hover:bg-yellow-600 text-black font-bold text-sm rounded-md shadow disabled:opacity-50 transition whitespace-nowrap"
                            >
                                {{ cargandoPlaca ? 'Buscando datos...' : 'Buscar Placa' }}
                            </button>
                        </div>
                    </div>

                    <div
                        v-if="cargandoPlaca"
                        role="progressbar"
                        aria-label="Buscando información de la placa"
                        class="mt-4 h-1.5 overflow-hidden rounded-full bg-gray-700"
                    >
                        <div class="loading-bar__indicator h-full w-1/3 rounded-full bg-yellow-400"></div>
                    </div>

                    <!-- Mensaje de estado de la búsqueda -->
                    <div v-if="mensajePlaca" class="mt-3 pt-3 border-t border-gray-700 text-xs font-semibold" :class="tipoMensaje === 'exito' ? 'text-green-400' : 'text-amber-400'">
                        {{ mensajePlaca }}
                    </div>
                </div>

                <div
                    v-if="success && mostrarFormularioIngreso"
                    role="status"
                    aria-live="polite"
                    class="mb-6 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900 shadow-sm"
                >
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-6 w-6"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2"
                            aria-hidden="true"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                    </div>
                    <div class="pt-1">
                        <p class="font-semibold">Vehículo registrado</p>
                        <p class="mt-1 text-sm text-emerald-800">{{ success }}</p>
                    </div>
                </div>

                <!-- FORMULARIO PRINCIPAL -->
                <form
                    v-if="mostrarFormularioIngreso"
                    @submit.prevent="enviarFormulario"
                    class="bg-white shadow-sm sm:rounded-lg p-6 space-y-6"
                >
                    
                    <!-- Alertas de error de validación en Laravel -->
                    <div v-if="Object.keys(form.errors).length > 0" class="bg-red-50 border-l-4 border-red-500 p-4 mb-4">
                        <p class="font-bold text-red-700">Por favor corrige los siguientes errores:</p>
                        <ul class="list-disc list-inside text-sm text-red-600">
                            <li v-for="(error, campo) in form.errors" :key="campo">{{ error }}</li>
                        </ul>
                    </div>

                    <!-- SECCIÓN 1: Identificación de serie -->
                    <div>
                        <h3 class="text-lg font-bold text-gray-800 border-b pb-2 mb-4">1. Identificación Técnica</h3>
                        <div class="mb-4">
                            <label for="imagenes" class="block text-sm font-medium text-gray-700">
                                Imágenes del vehículo (opcional, máximo 10)
                            </label>
                            <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center">
                                <input
                                    id="imagenes"
                                    ref="imagenesInput"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    multiple
                                    aria-describedby="imagenes-ayuda"
                                    class="peer sr-only"
                                    @input="form.imagenes = Array.from($event.target.files ?? [])"
                                />
                                <label
                                    for="imagenes"
                                    class="inline-flex w-fit cursor-pointer items-center gap-2 rounded-lg bg-yellow-500 px-4 py-2.5 text-sm font-bold text-gray-900 shadow-sm transition hover:bg-yellow-400 peer-focus-visible:ring-2 peer-focus-visible:ring-yellow-500 peer-focus-visible:ring-offset-2"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-5 w-5"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        stroke-width="1.8"
                                        aria-hidden="true"
                                    >
                                        <rect x="3" y="3" width="18" height="18" rx="2" />
                                        <circle cx="8.5" cy="8.5" r="1.5" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 15-5-5L5 21" />
                                    </svg>
                                    Añadir imágenes
                                </label>
                                <p id="imagenes-ayuda" class="text-xs text-gray-500">
                                    JPG, PNG o WebP; máximo 5 MB por imagen.
                                </p>
                            </div>
                            <p v-if="form.imagenes.length" class="mt-1 text-xs text-gray-600">
                                {{ form.imagenes.length }} imagen(es) seleccionada(s).
                            </p>
                            <p v-if="form.errors.imagenes" class="mt-1 text-sm text-red-600">
                                {{ form.errors.imagenes }}
                            </p>
                            <p
                                v-for="(error, index) in erroresImagenes"
                                :key="index"
                                class="mt-1 text-sm text-red-600"
                            >
                                {{ error }}
                            </p>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Chasis (VIN)</label>
                                <input v-model="form.chasis" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm uppercase font-mono" required />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Número de Motor</label>
                                <input v-model="form.motor" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm uppercase font-mono" required />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Cilindraje (cc)</label>
                                <input v-model="form.cilindraje" type="text" placeholder="Ej: 1600" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required />
                            </div>
                        </div>
                    </div>

                    <!-- SECCIÓN 2: Catálogos y Características -->
                    <div>
                        <h3 class="text-lg font-bold text-gray-800 border-b pb-2 mb-4">2. Características del Vehículo</h3>
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Marca</label>
                                <select v-model="form.id_marca" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required>
                                    <option value="" disabled>Seleccione...</option>
                                    <option v-for="item in listaMarcas" :key="item.id_marca" :value="item.id_marca">
                                        {{ item.nombre }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Modelo</label>
                                <input v-model="form.modelo" type="text" placeholder="Ej: Hilux / Gol" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Tipo de Vehículo</label>
                                <input v-model="form.tipo_vehiculo" type="text" placeholder="Ej: SEDAN / COUPE" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm uppercase" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Tipo de Servicio</label>
                                <input v-model="form.servicio" type="text" placeholder="Ej: USO PARTICULAR" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm uppercase" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Clase</label>
                                <select v-model="form.id_clase" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required>
                                    <option value="" disabled>Seleccione...</option>
                                    <option v-for="item in listaClases" :key="item.id_clase" :value="item.id_clase">
                                        {{ item.nombre }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Año</label>
                                <input v-model="form.anio" type="number" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Color</label>
                                <select v-model="form.id_color" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required>
                                    <option value="" disabled>Seleccione...</option>
                                    <option v-for="item in listaColores" :key="item.id_color" :value="item.id_color">
                                        {{ item.nombre }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">País de Origen</label>
                                <select v-model="form.id_pais" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required>
                                    <option value="" disabled>Seleccione...</option>
                                    <option v-for="item in listaPaises" :key="item.id_pais" :value="item.id_pais">
                                        {{ item.nombre }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Combustible</label>
                                <select v-model="form.id_tipo_combustible" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required>
                                    <option value="" disabled>Seleccione...</option>
                                    <option v-for="item in combustibles" :key="item.id_tipo_combustible" :value="item.id_tipo_combustible">
                                        {{ item.nombre }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Transmisión</label>
                                <select v-model="form.id_transmision" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required>
                                    <option value="" disabled>Seleccione...</option>
                                    <option v-for="item in transmisiones" :key="item.id_transmision" :value="item.id_transmision">
                                        {{ item.nombre }}
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- SECCIÓN 3: Negociación y Ubicación -->
                    <div>
                        <h3 class="text-lg font-bold text-gray-800 border-b pb-2 mb-4">3. Datos de Compra y Recepción</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Costo Adquisición ($)</label>
                                <input v-model="form.costo_adquisicion" type="number" step="0.01" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required />
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-4 border-t">
                        <button type="submit" :disabled="form.processing" class="px-6 py-2.5 bg-yellow-500 hover:bg-yellow-600 text-black font-bold rounded-md shadow disabled:opacity-50 transition">
                            {{ form.processing ? 'Guardando...' : 'Registrar Adquisición' }}
                        </button>
                    </div>
                </form>

                <div
                    v-else
                    class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500"
                >
                    Seleccione una categoría para mostrar el formulario correspondiente.
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
@keyframes loading-bar {
    from {
        transform: translateX(-100%);
    }

    to {
        transform: translateX(300%);
    }
}

.loading-bar__indicator {
    animation: loading-bar 1.4s ease-in-out infinite;
}

@media (prefers-reduced-motion: reduce) {
    .loading-bar__indicator {
        animation: none;
        width: 100%;
    }
}
</style>