<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

// 1. Recibimos los catálogos desde Laravel
const props = defineProps({
    marcas: Array,
    clases: Array,
    paises: Array,
    colores: Array,
    tipos_combustible: Array,
    transmisiones: Array,
    patios: Array,
    estados: Array,
    proveedores: Array,
    success: String,
});

// 2. Conectamos los campos exactos de tu Modelo Vehiculo.php
const form = useForm({
    id_marca: '',
    id_clase: '',
    id_pais: '',
    id_color: '',
    id_tipo_combustible: '',
    id_transmision: '',
    id_patio: '',
    id_estado: '',
    id_proveedor: '',
    modelo: '',
    anio: new Date().getFullYear(),
    motor: '',
    chasis: '',
    cilindraje: '',
    kilometraje: 0,
    costo_adquisicion: '',
    precio_venta: '',
});

const enviarFormulario = () => {
    form.post(route('vehiculos.store'), {
        onSuccess: () => {
            form.reset(); // Limpia todas las cajas de texto al guardar
            alert('¡Vehículo guardado exitosamente en la base de datos!');
        },
    });
};
</script>

<template>
    <Head title="Ingreso de Vehículo" />
    <!-- Bloque de errores de validación -->
        <div v-if="Object.keys(form.errors).length > 0" class="bg-red-50 border-l-4 border-red-500 p-4 mb-4">
            <p class="font-bold text-red-700">Por favor corrige los siguientes errores:</p>
            <ul class="list-disc list-inside text-sm text-red-600">
                <li v-for="(error, campo) in form.errors" :key="campo">{{ error }}</li>
            </ul>
        </div>

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Módulo de Compras: Ingreso de Vehículos
            </h2>
        </template>

        <div class="py-8">
            <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
                <div
                    v-if="success"
                    class="mb-4 rounded-md bg-green-100 p-4 text-green-800"
                    role="status"
                >
                    {{ success }}
                </div>

                <form @submit.prevent="enviarFormulario" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-6">
                    <div
                        v-if="Object.keys(form.errors).length"
                        class="rounded-md bg-red-100 p-4 text-red-800"
                        role="alert"
                    >
                        <p v-for="(message, field) in form.errors" :key="field">
                            {{ message }}
                        </p>
                    </div>
                    
                    <!-- SECCIÓN 1: Identificación de serie -->
                    <div>
                        <h3 class="text-lg font-bold text-gray-800 border-b pb-2 mb-4">1. Identificación Técnica</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Chasis (VIN)</label>
                                <input v-model="form.chasis" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm uppercase" required />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Número de Motor</label>
                                <input v-model="form.motor" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm uppercase" required />
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
                                    <option v-for="item in marcas" :key="item.id_marca" :value="item.id_marca">
                                        {{ item.nombre }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Modelo</label>
                                <input v-model="form.modelo" type="text" placeholder="Ej: Hilux / Aveo" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Clase</label>
                                <select v-model="form.id_clase" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required>
                                    <option value="" disabled>Seleccione...</option>
                                    <option v-for="item in clases" :key="item.id_clase" :value="item.id_clase">
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
                                    <option v-for="item in colores" :key="item.id_color" :value="item.id_color">
                                        {{ item.nombre }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">País de Origen</label>
                                <select v-model="form.id_pais" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required>
                                    <option value="" disabled>Seleccione...</option>
                                    <option v-for="item in paises" :key="item.id_pais" :value="item.id_pais">
                                        {{ item.nombre }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Combustible</label>
                                <select v-model="form.id_tipo_combustible" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required>
                                    <option value="" disabled>Seleccione...</option>
                                    <option v-for="item in tipos_combustible" :key="item.id_tipo_combustible" :value="item.id_tipo_combustible">
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
                        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Kilometraje</label>
                                <input v-model="form.kilometraje" type="number" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Costo Adquisición ($)</label>
                                <input v-model="form.costo_adquisicion" type="number" step="0.01" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Precio Venta Sugerido ($)</label>
                                <input v-model="form.precio_venta" type="number" step="0.01" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Proveedor</label>
                                <select v-model="form.id_proveedor" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required>
                                    <option value="" disabled>Seleccione...</option>
                                    <option v-for="item in proveedores" :key="item.id_proveedor" :value="item.id_proveedor">
                                        {{ item.nombre }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Patio / Ubicación</label>
                                <select v-model="form.id_patio" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required>
                                    <option value="" disabled>Seleccione...</option>
                                    <option v-for="item in patios" :key="item.id_patio" :value="item.id_patio">
                                        {{ item.nombre }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Estado</label>
                                <select v-model="form.id_estado" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required>
                                    <option value="" disabled>Seleccione...</option>
                                    <option v-for="item in estados" :key="item.id_estado" :value="item.id_estado">
                                        {{ item.nombre }}
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-4 border-t">
                        <button 
                            type="submit" 
                            :disabled="form.processing" 
                            class="px-6 py-2.5 bg-yellow-500 hover:bg-yellow-600 text-black font-bold rounded-md shadow disabled:opacity-50"
                        >
                            {{ form.processing ? 'Guardando...' : 'Registrar Adquisición' }}
                        </button>
                        
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>