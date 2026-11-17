<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
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

// 4. Formulario conectado con las columnas de tu modelo Vehiculo.php
const form = useForm({
    id_vehiculo: '',
    validacion_id: null,
    comentario_solicitud: '',
    documento_matricula: null,
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
            if (matriculaInput.value) {
                matriculaInput.value.value = '';
            }
            mensajePlaca.value = '';
            archivoMatricula.value = null;
            resultadoAuditoria.value = null;
            camposAxisPendientes.value = [];
            errorAuditoria.value = '';
            erroresValidacionAuditoria.value = [];
            puedeEnviarRevisionManual.value = false;
        },
    });
};

const archivoMatricula = ref(null);
const matriculaInput = ref(null);
const escaneandoIA = ref(false);
const resultadoAuditoria = ref(null);
const errorAuditoria = ref('');
const erroresValidacionAuditoria = ref([]);
const puedeEnviarRevisionManual = ref(false);
const camposAxisPendientes = ref([]);
const camposAxisFormulario = [
    { key: 'id_vehiculo', label: 'Placa', field: 'placa' },
    { key: 'id_marca', label: 'Marca', field: 'marca' },
    { key: 'id_clase', label: 'Clase', field: 'clase' },
    { key: 'id_pais', label: 'País', field: 'pais' },
    { key: 'id_color', label: 'Color', field: 'color' },
    { key: 'modelo', label: 'Modelo', field: 'modelo' },
    { key: 'anio', label: 'Año de fabricación', field: 'anio' },
    { key: 'motor', label: 'Motor', field: 'motor' },
    { key: 'chasis', label: 'Chasis (VIN)', field: 'chasis' },
    { key: 'cilindraje', label: 'Cilindraje', field: 'cilindraje' },
];
const camposAxisParaCompletar = computed(() =>
    camposAxisFormulario.filter(({ key }) => camposAxisPendientes.value.includes(key)),
);
const camposAxisInformativos = computed(() => [
    ...camposAxisFormulario,
    { key: 'tipo_vehiculo', label: 'Tipo de vehículo', field: 'tipo_vehiculo' },
    { key: 'servicio', label: 'Servicio', field: 'servicio' },
].filter(({ key }) => !camposAxisPendientes.value.includes(key)));
const mostrarFormularioIngreso = computed(() =>
    categoriaSeleccionada.value === 'empresa'
    || (categoriaSeleccionada.value === 'particular' && Boolean(errorAuditoria.value)),
);

const seleccionarMatricula = (e) => {
    archivoMatricula.value = e.target.files[0] || null;
    errorAuditoria.value = '';
    erroresValidacionAuditoria.value = [];
    resultadoAuditoria.value = null;
    puedeEnviarRevisionManual.value = false;
    camposAxisPendientes.value = [];
    form.validacion_id = null;
    form.comentario_solicitud = '';
    form.documento_matricula = null;
    form.clearErrors();
};

const escanearYComparar = async () => {
    if (!archivoMatricula.value) {
        errorAuditoria.value = 'Selecciona primero una foto o PDF de la matrícula.';
        erroresValidacionAuditoria.value = [];
        return;
    }

    escaneandoIA.value = true;
    errorAuditoria.value = '';
    erroresValidacionAuditoria.value = [];
    resultadoAuditoria.value = null;
    puedeEnviarRevisionManual.value = false;
    camposAxisPendientes.value = [];
    form.validacion_id = null;
    form.documento_matricula = null;

    const formData = new FormData();
    formData.append('documento', archivoMatricula.value);

    const mostrarError = (respuesta, mensajeAlternativo) => {
        errorAuditoria.value = respuesta?.message || mensajeAlternativo;
        erroresValidacionAuditoria.value = Object.values(respuesta?.errors || {})
            .flat()
            .filter((mensaje) => typeof mensaje === 'string');
        puedeEnviarRevisionManual.value = respuesta?.manual_review_available === true;
        form.documento_matricula = puedeEnviarRevisionManual.value ? archivoMatricula.value : null;
    };

    try {
        const res = await axios.post(route('vehiculos.escanear.matricula'), formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        });

        if (res.data && res.data.success) {
            resultadoAuditoria.value = res.data;
            form.validacion_id = res.data.validacion_id;
            const camposDisponibles = new Set(camposAxisFormulario.map(({ key }) => key));
            camposAxisPendientes.value = Array.isArray(res.data.campos_axis_pendientes)
                ? res.data.campos_axis_pendientes.filter((campo) => camposDisponibles.has(campo))
                : [];
            for (const { key } of camposAxisFormulario) {
                form[key] = '';
            }
            if (camposAxisPendientes.value.includes('id_vehiculo')) {
                form.id_vehiculo = res.data.datos_documento?.placa || '';
            }
            puedeEnviarRevisionManual.value = false;
            form.documento_matricula = null;
        } else {
            mostrarError(res.data, 'El servidor no devolvió una respuesta válida.');
        }
    } catch (error) {
        mostrarError(error.response?.data, error.message || 'Error al escanear el documento.');
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

                <div
                    v-if="success"
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
                    v-if="categoriaSeleccionada === 'empresa'"
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
                                <label class="block text-sm font-medium text-gray-700">Fecha de Revisión</label>
                                <input v-model="form.fecha_revision" type="date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Lugar Revision</label>
                                <input v-model="form.motor" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm uppercase font-mono" required />
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
                </form>

                <!-- MÓDULO DE ESCANEO IA Y AUDITORÍA CRUZADA (4 FUENTES) -->
                <div
                    v-if="categoriaSeleccionada === 'particular'"
                    class="mb-6 rounded-lg border border-slate-700 bg-slate-900 p-5 text-white shadow-md"
                >
                    <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
                        <div>
                            <h3 class="text-sm font-extrabold uppercase tracking-wider text-yellow-400">
                                Escáner IA de Matrícula + Auditoría Oficial (AxisCloud · SRI · Autority · Propietario)
                            </h3>
                            <p class="mt-1 text-xs text-gray-300">
                                Sube la foto o PDF de la matrícula para extraer sus datos con IA y validarlos contra las fuentes oficiales en tiempo real.
                            </p>
                            <p class="mt-1 text-xs text-gray-400">
                                El documento se enviará a Google Gemini y se consultarán simultáneamente Propietario, Multas, Valores SRI y Ficha Técnica.
                            </p>
                        </div>

                        <div class="flex w-full flex-col gap-3 md:w-auto md:flex-row md:items-end">
                            <div>
                                <label for="documento-matricula" class="mb-1 block text-xs font-semibold text-gray-200">
                                    Matrícula
                                </label>
                                <input
                                    id="documento-matricula"
                                    ref="matriculaInput"
                                    type="file"
                                    @change="seleccionarMatricula"
                                    :disabled="escaneandoIA"
                                    accept="image/jpeg,image/png,image/webp,.pdf"
                                    aria-label="Seleccionar matrícula en PDF o imagen"
                                    class="text-xs text-gray-300 file:mr-3 file:rounded file:border-0 file:bg-gray-700 file:px-3 file:py-1.5 file:text-white hover:file:bg-gray-600"
                                />
                            </div>
                            <button
                                type="button"
                                @click="escanearYComparar"
                                :disabled="escaneandoIA"
                                class="whitespace-nowrap rounded bg-yellow-500 px-4 py-2 text-xs font-bold text-black shadow hover:bg-yellow-600 disabled:opacity-50"
                            >
                                {{ escaneandoIA ? 'Auditando IA + 4 Fuentes Web...' : 'Auditar Matrícula' }}
                            </button>
                        </div>
                    </div>

                    <div
                        v-if="escaneandoIA"
                        role="progressbar"
                        aria-label="Auditando matrícula"
                        class="mt-4 h-1.5 overflow-hidden rounded-full bg-gray-700"
                    >
                        <div class="loading-bar__indicator h-full w-1/3 rounded-full bg-yellow-400"></div>
                    </div>

                    <div
                        v-if="errorAuditoria"
                        role="alert"
                        aria-live="assertive"
                        class="mt-3 rounded border border-red-500 bg-red-900/50 p-3 text-xs text-red-200"
                    >
                        <p>{{ errorAuditoria }}</p>
                        <ul
                            v-if="erroresValidacionAuditoria.length"
                            class="mt-2 list-inside list-disc space-y-1"
                        >
                            <li v-for="(error, index) in erroresValidacionAuditoria" :key="index">
                                {{ error }}
                            </li>
                        </ul>
                        <div
                            v-if="puedeEnviarRevisionManual"
                            class="mt-3 rounded border border-amber-400 bg-amber-950/50 p-3"
                        >
                            <p class="font-semibold text-amber-100">
                                Puedes enviar la matrícula a revisión manual sin completar la auditoría automática.
                            </p>
                            <p class="mt-1 text-amber-200">
                                Completa los datos de adquisición en el formulario y envía el documento; el vehículo quedará pendiente de aprobación.
                            </p>
                            <label for="comentario-solicitud-error" class="mt-3 block font-semibold text-amber-100">
                                Comentario para quien revisa (opcional)
                            </label>
                            <textarea
                                id="comentario-solicitud-error"
                                v-model="form.comentario_solicitud"
                                rows="3"
                                maxlength="2000"
                                class="mt-1 block w-full rounded-md border-gray-600 bg-gray-800 text-sm text-white shadow-sm focus:border-yellow-500 focus:ring-yellow-500"
                                placeholder="Agrega información útil para la revisión..."
                            />
                            <p v-if="form.errors.comentario_solicitud" class="mt-1 text-xs text-red-300">
                                {{ form.errors.comentario_solicitud }}
                            </p>
                            <div class="mt-3 flex justify-end">
                                <button
                                    type="button"
                                    @click="enviarFormulario"
                                    :disabled="form.processing"
                                    class="rounded bg-blue-600 px-4 py-2 text-xs font-bold text-white hover:bg-blue-500 disabled:opacity-50"
                                >
                                    {{ form.processing ? 'Enviando expediente...' : 'Enviar a revisión manual' }}
                                </button>
                            </div>
                        </div>
                        </div>
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
                    <div class="flex justify-end">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-md bg-indigo-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-800 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ form.processing
                                ? 'Enviando...'
                                : form.documento_matricula
                                    ? 'Enviar a revisión manual'
                                    : 'Registrar vehículo manualmente' }}
                        </button>
                    </div>
                </form>

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
                                    ? `¡Atención! Se detectaron ${resultadoAuditoria.alertas_criticas} discrepancias críticas (VIN/Motor/Placa/Propietario)`
                                    : resultadoAuditoria.comparacion.some((item) => item.estado !== 'coincide')
                                        ? 'Revisión parcial: hay datos diferentes o no verificables'
                                        : 'Verificación exitosa: datos comparados coinciden con los registros oficiales' }}
                            </span>
                            <div class="text-xs text-gray-400 space-y-0.5">
                                <p>
                                    Propietario en documento:
                                    <strong class="text-white">
                                        {{ resultadoAuditoria.propietario_doc.nombre }}
                                        ({{ resultadoAuditoria.propietario_doc.cedula }})
                                    </strong>
                                </p>
                                <p v-if="resultadoAuditoria.propietario_doc.nombre_web">
                                    Propietario actual registrado (Web):
                                    <strong class="text-yellow-300">
                                        {{ resultadoAuditoria.propietario_doc.nombre_web }}
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

                        <!-- TABLA COMPARATIVA MATRÍCULA VS REGISTROS OFICIALES -->
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs text-gray-200">
                                <thead class="bg-gray-900 uppercase text-gray-400">
                                    <tr>
                                        <th class="px-3 py-2">Campo</th>
                                        <th class="px-3 py-2">Leído en matrícula (IA)</th>
                                        <th class="px-3 py-2">Extraído de Fuentes Oficiales</th>
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
                                            <span
                                                v-if="item.estado === 'coincide' || item.coincide"
                                                class="rounded-full bg-green-900 px-2.5 py-0.5 text-[11px] font-bold text-green-300"
                                            >
                                                ✓ Coincide
                                            </span>

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

                                            <div v-else class="flex flex-col items-center">
                                                <span class="rounded-full bg-red-900 px-2.5 py-0.5 text-[11px] font-bold text-red-300">
                                                    ✕ Diferente
                                                </span>
                                                <span v-if="item.nota" class="mt-0.5 text-center text-[10px] text-red-300">
                                                    {{ item.nota }}
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- TARJETAS FINANCIERAS: MULTAS (AUTORITY) + VALORES DE MATRÍCULA (SRI) -->
                        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                            <!-- Tarjeta 1: Multas Autority -->
                            <div
                                class="rounded-lg border p-4"
                                :class="resultadoAuditoria.multas?.tiene_multas
                                    ? 'border-red-500 bg-red-950/40'
                                    : 'border-green-600 bg-green-950/30'"
                            >
                                <div class="flex items-center justify-between">
                                    <div>
                                        <h4
                                            class="text-xs font-extrabold uppercase tracking-wider"
                                            :class="resultadoAuditoria.multas?.tiene_multas ? 'text-red-400' : 'text-green-400'"
                                        >
                                            {{ resultadoAuditoria.multas?.tiene_multas
                                                ? `🚨 ${resultadoAuditoria.multas.cantidad} Multa(s) Pendiente(s)`
                                                : '✅ Sin Multas de Tránsito (Autority)' }}
                                        </h4>
                                        <p class="mt-0.5 text-[11px] text-gray-300">
                                            Fuente: multas.autority.io
                                        </p>
                                    </div>
                                    <span
                                        class="font-mono text-lg font-extrabold"
                                        :class="resultadoAuditoria.multas?.tiene_multas ? 'text-red-400' : 'text-green-400'"
                                    >
                                        ${{ Number(resultadoAuditoria.multas?.total || 0).toFixed(2) }}
                                    </span>
                                </div>

                                <div
                                    v-if="resultadoAuditoria.multas?.tiene_multas"
                                    class="mt-3 max-h-44 overflow-y-auto border-t border-red-800/60 pt-2"
                                >
                                    <table class="w-full text-left text-[11px] text-gray-200">
                                        <thead class="text-gray-400 uppercase">
                                            <tr>
                                                <th class="py-1 pr-2">Fecha / Entidad</th>
                                                <th class="py-1 pr-2">Infracción</th>
                                                <th class="py-1 text-right">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-red-900/40">
                                            <tr v-for="(multa, idx) in resultadoAuditoria.multas.listado" :key="idx">
                                                <td class="py-1.5 pr-2 whitespace-nowrap">
                                                    <div class="font-semibold">{{ multa.fecha }}</div>
                                                    <div class="text-[10px] text-gray-400">{{ multa.entidad }}</div>
                                                </td>
                                                <td class="py-1.5 pr-2">
                                                    <div class="font-mono text-[10px] text-gray-300">{{ multa.citacion }}</div>
                                                    <div class="line-clamp-2">{{ multa.descripcion }}</div>
                                                </td>
                                                <td class="py-1.5 text-right font-mono font-bold text-red-300 whitespace-nowrap">
                                                    ${{ Number(multa.total).toFixed(2) }}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Tarjeta 2: Valores de Matrícula SRI -->
                            <div
                                class="rounded-lg border p-4"
                                :class="resultadoAuditoria.sri?.tiene_deuda
                                    ? 'border-amber-500 bg-amber-950/40'
                                    : 'border-green-600 bg-green-950/30'"
                            >
                                <div class="flex items-center justify-between">
                                    <div>
                                        <h4
                                            class="text-xs font-extrabold uppercase tracking-wider"
                                            :class="resultadoAuditoria.sri?.tiene_deuda ? 'text-amber-400' : 'text-green-400'"
                                        >
                                            {{ resultadoAuditoria.sri?.tiene_deuda
                                                ? '⚠️ Valores Pendientes en el SRI'
                                                : '✅ Matrícula al Día en el SRI' }}
                                        </h4>
                                        <p class="mt-0.5 text-[11px] text-gray-300">
                                            Último año pagado:
                                            <strong class="text-white">{{ resultadoAuditoria.sri?.ultimo_anio || 'S/N' }}</strong>
                                            <span v-if="resultadoAuditoria.sri?.camv_cpn" class="ml-2 text-gray-400">
                                                · CAMV/CPN: {{ resultadoAuditoria.sri.camv_cpn }}
                                            </span>
                                        </p>
                                    </div>
                                    <span
                                        class="font-mono text-lg font-extrabold"
                                        :class="resultadoAuditoria.sri?.tiene_deuda ? 'text-amber-400' : 'text-green-400'"
                                    >
                                        ${{ Number(resultadoAuditoria.sri?.total || 0).toFixed(2) }}
                                    </span>
                                </div>

                                <ul
                                    v-if="resultadoAuditoria.sri?.rubros?.length"
                                    class="mt-3 max-h-44 space-y-1.5 overflow-y-auto border-t border-amber-800/60 pt-2 text-[11px]"
                                >
                                    <li
                                        v-for="(rubro, idx) in resultadoAuditoria.sri.rubros"
                                        :key="idx"
                                        class="flex justify-between gap-2 text-gray-200"
                                    >
                                        <span>{{ rubro.concepto }} {{ rubro.periodo ? `(${rubro.periodo})` : '' }}</span>
                                        <span class="font-mono font-bold text-amber-300">
                                            ${{ Number(rubro.valor).toFixed(2) }}
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- RESUMEN DE DEUDAS TOTALES (MULTAS + SRI) -->
                        <div class="mt-3 flex flex-col items-start justify-between gap-2 rounded-md border border-gray-700 bg-gray-900/90 px-4 py-2.5 text-xs sm:flex-row sm:items-center">
                            <div>
                                <span class="font-bold uppercase text-yellow-400">Total Deudas Detectadas (Multas + SRI):</span>
                                <span
                                    class="ml-2 font-mono text-sm font-extrabold"
                                    :class="Number(resultadoAuditoria.total_deudas || 0) > 0 ? 'text-red-400' : 'text-green-400'"
                                >
                                    ${{ Number(resultadoAuditoria.total_deudas || 0).toFixed(2) }}
                                </span>
                            </div>
                            <span class="text-[11px] text-gray-400">
                                {{ Number(resultadoAuditoria.total_deudas || 0) > 0
                                    ? 'Recomendación: Descontar o retener este valor del precio de compra.'
                                    : 'Vehículo sin valores pendientes de matrícula ni multas ($0.00).' }}
                            </span>
                        </div>
                        
                        <!-- TARJETA DE CANTIDAD DE TRANSFERENCIAS DE DOMINIO -->
                        <div class="mt-4">
                            <div class="rounded-lg border border-gray-700 bg-gray-800 p-4">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <h4 class="text-xs font-extrabold uppercase tracking-wider text-yellow-400">
                                            👥 Historial de Propietarios (Traspasos Registrados)
                                        </h4>
                                        <p class="mt-0.5 text-[11px] text-gray-300">
                                            {{ resultadoAuditoria.propietarios?.mensaje }}
                                        </p>
                                    </div>
                                    <span
                                        class="rounded px-2.5 py-1 text-xs font-bold font-mono"
                                        :class="(resultadoAuditoria.propietarios?.total_traspasos || 0) >= 3 ? 'bg-amber-600 text-white' : 'bg-blue-900 text-blue-200'"
                                    >
                                        {{ resultadoAuditoria.propietarios?.total_traspasos || 0 }} Traspaso(s)
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- TARJETA DE ANTECEDENTES JUDICIALES / FISCALÍA -->
                        <div class="mt-4">
                            <div
                                class="rounded-lg border p-4"
                                :class="resultadoAuditoria.fiscalia?.tiene_denuncias
                                    ? 'border-red-600 bg-red-950 animate-pulse'
                                    : 'border-green-600 bg-green-950/30'"
                            >
                                <div class="flex items-center justify-between">
                                    <div>
                                        <h4
                                            class="text-xs font-extrabold uppercase tracking-wider"
                                            :class="resultadoAuditoria.fiscalia?.tiene_denuncias ? 'text-red-300 font-black text-sm' : 'text-green-400'"
                                        >
                                            {{ resultadoAuditoria.fiscalia?.tiene_denuncias
                                                ? `🚨 ¡ALERTA CRÍTICA! Vehículo con ${resultadoAuditoria.fiscalia.cantidad} Denuncia(s) en Fiscalía`
                                                : '✅ Sin Registros de Denuncias o Delitos (Fiscalía FGE)' }}
                                        </h4>
                                        <p class="mt-0.5 text-[11px] text-gray-300">
                                            Fuente: Fiscalía General del Estado (Noticias del Delito)
                                        </p>
                                    </div>
                                    <span
                                        class="rounded px-2.5 py-1 text-xs font-bold font-mono"
                                        :class="resultadoAuditoria.fiscalia?.tiene_denuncias ? 'bg-red-600 text-white' : 'bg-green-800 text-green-200'"
                                    >
                                        {{ resultadoAuditoria.fiscalia?.tiene_denuncias ? 'BLOQUEAR COMPRA' : 'LIMPIO' }}
                                    </span>
                                </div>

                                <ul v-if="resultadoAuditoria.fiscalia?.tiene_denuncias" class="mt-3 space-y-2 border-t border-red-800 pt-2 text-xs text-red-100">
                                    <li v-for="(denuncia, idx) in resultadoAuditoria.fiscalia.listado" :key="idx" class="rounded bg-red-900/60 p-2">
                                        <div class="flex justify-between font-bold">
                                            <span>Expediente / NDD: {{ denuncia.noticia_delito }}</span>
                                            <span>{{ denuncia.fecha }}</span>
                                        </div>
                                        <p class="mt-1 text-[11px] text-red-200">Ubicación: {{ denuncia.localidad }}</p>
                                        <p class="mt-0.5 text-[11px] text-white">Delito / Asunto: {{ denuncia.descripcion }}</p>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <form
                            v-if="categoriaSeleccionada === 'particular' && resultadoAuditoria"
                            @submit.prevent="enviarFormulario"
                            class="mt-5 space-y-5 rounded-lg border border-emerald-700 bg-gray-900 p-4"
                        >
                            <div>
                                <h3 class="text-sm font-bold text-emerald-300">
                                    Completa los datos faltantes para registrar el vehículo
                                </h3>
                                <p class="mt-1 text-xs text-gray-300">
                                    Los datos oficiales se toman de AxisCloud. Completa solo los datos que no recibió y los datos de adquisición.
                                </p>
                            </div>

                            <div v-if="Object.keys(form.errors).length" class="rounded border border-red-500 bg-red-950/60 p-3 text-sm text-red-200" role="alert">
                                <p v-for="(error, campo) in form.errors" :key="campo">{{ error }}</p>
                            </div>

                            <div
                                v-if="camposAxisParaCompletar.length"
                                class="space-y-3 rounded-md border border-amber-600 bg-amber-950/40 p-3"
                            >
                                <p class="text-sm text-amber-100">
                                    La auditoría se completó. AxisCloud no informó estos campos; completa solo los que aparecen aquí.
                                </p>
                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                                    <div v-for="campo in camposAxisParaCompletar" :key="campo.key">
                                        <label :for="`axis-${campo.key}`" class="block text-sm font-medium text-amber-100">
                                            {{ campo.label }} (no recibido)
                                        </label>

                                        <select
                                            v-if="campo.key === 'id_marca'"
                                            :id="`axis-${campo.key}`"
                                            v-model="form[campo.key]"
                                            required
                                            class="mt-1 block w-full rounded-md border-gray-600 bg-gray-800 text-white shadow-sm"
                                        >
                                            <option value="" disabled>Seleccione...</option>
                                            <option v-for="item in listaMarcas" :key="item.id_marca" :value="item.id_marca">{{ item.nombre }}</option>
                                        </select>
                                        <select
                                            v-else-if="campo.key === 'id_clase'"
                                            :id="`axis-${campo.key}`"
                                            v-model="form[campo.key]"
                                            required
                                            class="mt-1 block w-full rounded-md border-gray-600 bg-gray-800 text-white shadow-sm"
                                        >
                                            <option value="" disabled>Seleccione...</option>
                                            <option v-for="item in listaClases" :key="item.id_clase" :value="item.id_clase">{{ item.nombre }}</option>
                                        </select>
                                        <select
                                            v-else-if="campo.key === 'id_pais'"
                                            :id="`axis-${campo.key}`"
                                            v-model="form[campo.key]"
                                            required
                                            class="mt-1 block w-full rounded-md border-gray-600 bg-gray-800 text-white shadow-sm"
                                        >
                                            <option value="" disabled>Seleccione...</option>
                                            <option v-for="item in listaPaises" :key="item.id_pais" :value="item.id_pais">{{ item.nombre }}</option>
                                        </select>
                                        <select
                                            v-else-if="campo.key === 'id_color'"
                                            :id="`axis-${campo.key}`"
                                            v-model="form[campo.key]"
                                            required
                                            class="mt-1 block w-full rounded-md border-gray-600 bg-gray-800 text-white shadow-sm"
                                        >
                                            <option value="" disabled>Seleccione...</option>
                                            <option v-for="item in listaColores" :key="item.id_color" :value="item.id_color">{{ item.nombre }}</option>
                                        </select>
                                        <input
                                            v-else
                                            :id="`axis-${campo.key}`"
                                            v-model="form[campo.key]"
                                            :type="campo.key === 'anio' ? 'number' : 'text'"
                                            :min="campo.key === 'anio' ? 1980 : undefined"
                                            :max="campo.key === 'anio' ? new Date().getFullYear() + 1 : undefined"
                                            :maxlength="campo.key === 'id_vehiculo' ? 20 : 100"
                                            :placeholder="campo.key === 'id_vehiculo' ? 'Confirma la placa leída en la matrícula' : `Ingresa ${campo.label.toLowerCase()}`"
                                            required
                                            class="mt-1 block w-full rounded-md border-gray-600 bg-gray-800 text-white shadow-sm"
                                        />
                                        <p v-if="form.errors[campo.key]" class="mt-1 text-xs text-red-300" role="alert">
                                            {{ form.errors[campo.key] }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <dl class="grid grid-cols-1 gap-3 text-sm text-gray-200 sm:grid-cols-2 lg:grid-cols-3">
                                <div v-for="campo in camposAxisInformativos" :key="campo.key">
                                    <dt class="text-xs text-gray-400">{{ campo.label }}</dt>
                                    <dd :class="['id_vehiculo', 'motor', 'chasis'].includes(campo.key) ? 'font-mono' : ''">
                                        {{ resultadoAuditoria.datos_oficiales[campo.field] || 'No informado' }}
                                    </dd>
                                </div>
                            </dl>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                <div>
                                    <label for="combustible-auditoria" class="block text-sm font-medium text-gray-200">Combustible</label>
                                    <select id="combustible-auditoria" v-model="form.id_tipo_combustible" required class="mt-1 block w-full rounded-md border-gray-600 bg-gray-800 text-white shadow-sm">
                                        <option value="" disabled>Seleccione...</option>
                                        <option v-for="item in combustibles" :key="item.id_tipo_combustible" :value="item.id_tipo_combustible">{{ item.nombre }}</option>
                                    </select>
                                    <p v-if="form.errors.id_tipo_combustible" class="mt-1 text-xs text-red-300">{{ form.errors.id_tipo_combustible }}</p>
                                </div>
                                <div>
                                    <label for="transmision-auditoria" class="block text-sm font-medium text-gray-200">Transmisión</label>
                                    <select id="transmision-auditoria" v-model="form.id_transmision" required class="mt-1 block w-full rounded-md border-gray-600 bg-gray-800 text-white shadow-sm">
                                        <option value="" disabled>Seleccione...</option>
                                        <option v-for="item in transmisiones" :key="item.id_transmision" :value="item.id_transmision">{{ item.nombre }}</option>
                                    </select>
                                    <p v-if="form.errors.id_transmision" class="mt-1 text-xs text-red-300">{{ form.errors.id_transmision }}</p>
                                </div>
                                <div>
                                    <label for="costo-auditoria" class="block text-sm font-medium text-gray-200">Costo de adquisición</label>
                                    <input id="costo-auditoria" v-model="form.costo_adquisicion" type="number" min="0" step="0.01" required class="mt-1 block w-full rounded-md border-gray-600 bg-gray-800 text-white shadow-sm" />
                                    <p v-if="form.errors.costo_adquisicion" class="mt-1 text-xs text-red-300">{{ form.errors.costo_adquisicion }}</p>
                                </div>
                            </div>

                            <div>
                                <label for="imagenes-auditoria" class="block text-sm font-medium text-gray-200">Imágenes del vehículo (opcional)</label>
                                <input
                                    id="imagenes-auditoria"
                                    ref="imagenesInput"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    multiple
                                    class="mt-1 block w-full text-sm text-gray-300"
                                    @input="form.imagenes = Array.from($event.target.files ?? [])"
                                />
                                <p v-if="form.errors.imagenes" class="mt-1 text-xs text-red-300">{{ form.errors.imagenes }}</p>
                                <p v-for="(error, index) in erroresImagenes" :key="index" class="mt-1 text-xs text-red-300">{{ error }}</p>
                            </div>

                            <div class="flex justify-end">
                                <button
                                    type="submit"
                                    :disabled="form.processing"
                                    class="rounded-md bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {{ form.processing ? 'Registrando vehículo...' : 'Guardar directamente en inventario' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

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