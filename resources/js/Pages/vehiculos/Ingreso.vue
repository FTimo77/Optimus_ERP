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

                <!-- BARRA DE AUTOCOMPLETADO POR PLACA (LIMPIA PARA EL USUARIO) -->
                <div class="bg-gray-900 text-white p-5 rounded-lg shadow-md mb-6">
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
                <form @submit.prevent="enviarFormulario" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-6">
                    
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