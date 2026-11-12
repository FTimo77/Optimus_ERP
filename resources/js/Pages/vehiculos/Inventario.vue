<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    vehiculos: Array,
});

// Auto seleccionado para mostrar en la ventana desplegada
const vehiculoSeleccionado = ref(null);

const abrirDetalles = (auto) => {
    vehiculoSeleccionado.value = auto;
};

const cerrarDetalles = () => {
    vehiculoSeleccionado.value = null;
};

// Permitir cerrar la ventana presionando la tecla ESC
const manejarTecla = (e) => {
    if (e.key === 'Escape' && vehiculoSeleccionado.value) {
        cerrarDetalles();
    }
};

onMounted(() => window.addEventListener('keydown', manejarTecla));
onUnmounted(() => window.removeEventListener('keydown', manejarTecla));

// Función auxiliar para calcular el margen estimado en vivo
const calcularMargen = (auto) => {
    const venta = Number(auto.precio_venta) || 0;
    const costo = Number(auto.costo_adquisicion) || 0;
    return venta - costo;
};

// Formatear fecha de registro
const formatearFecha = (fecha) => {
    if (!fecha) return 'No registrada';
    return new Date(fecha).toLocaleDateString('es-EC', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};
</script>

<template>
    <Head title="Inventario de Vehículos" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                        Inventario General de Vehículos
                    </h2>
                    <p class="text-xs text-gray-500 mt-1">
                        Haz clic sobre cualquier vehículo de la lista para desplegar su expediente completo.
                    </p>
                </div>
                <Link 
                    :href="route('vehiculos.create')" 
                    class="px-4 py-2 bg-yellow-500 hover:bg-yellow-600 text-black text-sm font-bold rounded-md shadow transition"
                >
                    + Nuevo Ingreso
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden border border-gray-200">
                    
                    <!-- Tabla Interactiva de Vehículos -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-900 text-white">
                                <tr>
                                    <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider">ID</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Vehículo</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Año / Color</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Chasis (VIN)</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Ubicación</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Precio Venta</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Estado</th>
                                    <th class="px-6 py-3.5 text-center text-xs font-bold uppercase tracking-wider">Detalle</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <!-- Al hacer clic en cualquier parte de la fila (@click="abrirDetalles(auto)") se abre la ventana -->
                                <tr 
                                    v-for="auto in vehiculos" 
                                    :key="auto.id_vehiculo" 
                                    @click="abrirDetalles(auto)"
                                    class="hover:bg-yellow-50 cursor-pointer transition duration-150"
                                >
                                    <td class="px-6 py-4 text-sm font-bold text-gray-900">
                                        #{{ auto.id_vehiculo }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900">
                                        <div class="font-bold text-base">{{ auto.marca?.nombre }} {{ auto.modelo }}</div>
                                        <div class="text-xs text-gray-500">{{ auto.clase?.nombre }} • {{ auto.transmision?.nombre }}</div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        <span class="font-semibold">{{ auto.anio }}</span>
                                        <span class="block text-xs text-gray-500">{{ auto.color?.nombre }}</span>
                                    </td>
                                    <td class="px-6 py-4 text-sm font-mono text-gray-600">
                                        {{ auto.chasis }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        {{ auto.patio?.nombre }}
                                    </td>
                                    <td class="px-6 py-4 text-sm font-bold text-green-700">
                                        {{ auto.precio_venta != null ? `$${Number(auto.precio_venta).toLocaleString('en-US', { minimumFractionDigits: 2 })}` : 'No definido' }}
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-yellow-100 text-yellow-800 border border-yellow-300">
                                            {{ auto.estado?.nombre }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="inline-flex items-center px-3 py-1 bg-gray-900 text-yellow-400 text-xs font-bold rounded hover:bg-gray-700">
                                            Abrir Ficha &rarr;
                                        </span>
                                    </td>
                                </tr>

                                <tr v-if="!vehiculos || vehiculos.length === 0">
                                    <td colspan="8" class="px-6 py-10 text-center text-gray-500">
                                        No hay vehículos registrados en el inventario todavía.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </div>

        <!-- VENTANA DESPLEGADA CON EL EXPEDIENTE DETALLADO DEL VEHÍCULO -->
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div 
                v-if="vehiculoSeleccionado" 
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4"
                @click.self="cerrarDetalles"
            >
                <div class="bg-white rounded-xl shadow-2xl max-w-4xl w-full overflow-hidden border border-gray-200">
                    
                    <!-- Cabecera Destacada -->
                    <div class="bg-gray-900 text-white px-6 py-5 flex justify-between items-start border-b-4 border-yellow-500">
                        <div>
                            <div class="flex items-center gap-3">
                                <span class="px-2.5 py-0.5 bg-yellow-500 text-black text-xs font-extrabold rounded uppercase">
                                    Unidad #{{ vehiculoSeleccionado.id_vehiculo }}
                                </span>
                                <span class="px-2.5 py-0.5 bg-gray-700 text-yellow-300 text-xs font-semibold rounded-full">
                                    Estado: {{ vehiculoSeleccionado.estado?.nombre }}
                                </span>
                            </div>
                            <h3 class="text-2xl font-extrabold mt-2">
                                {{ vehiculoSeleccionado.marca?.nombre }} {{ vehiculoSeleccionado.modelo }} 
                                <span class="text-yellow-400 font-normal">({{ vehiculoSeleccionado.anio }})</span>
                            </h3>
                            <p class="text-xs text-gray-400 mt-1">
                                Ingresado al sistema: {{ formatearFecha(vehiculoSeleccionado.created_at) }}
                            </p>
                        </div>
                        <button 
                            @click="cerrarDetalles" 
                            class="text-gray-400 hover:text-white bg-gray-800 hover:bg-gray-700 rounded-lg w-9 h-9 flex items-center justify-center text-xl font-bold transition"
                            title="Cerrar (Esc)"
                        >
                            &times;
                        </button>
                    </div>

                    <!-- Cuerpo Desplegable -->
                    <div class="p-6 space-y-6 max-h-[75vh] overflow-y-auto">

                        <div class="overflow-hidden rounded-lg border border-gray-200 bg-gray-50">
                            <img
                                v-if="vehiculoSeleccionado.imagen_url"
                                :src="vehiculoSeleccionado.imagen_url"
                                :alt="`Imagen de ${vehiculoSeleccionado.marca?.nombre ?? ''} ${vehiculoSeleccionado.modelo}`"
                                class="max-h-96 w-full object-contain"
                            />
                            <div
                                v-else
                                class="flex h-48 items-center justify-center text-sm text-gray-500"
                            >
                                Este vehículo no tiene una imagen registrada.
                            </div>
                        </div>
                        
                        <!-- Resumen Financiero Rápido (Tarjetas superiores) -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                                <span class="text-xs font-bold uppercase text-red-600 block">Costo de Adquisición</span>
                                <span class="text-2xl font-extrabold text-red-700">
                                    ${{ Number(vehiculoSeleccionado.costo_adquisicion).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}
                                </span>
                                <span class="text-xs text-red-500 block mt-1">Valor de toma / compra inicial</span>
                            </div>

                            <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                                <span class="text-xs font-bold uppercase text-green-700 block">Precio Venta Sugerido</span>
                                <span class="text-2xl font-extrabold text-green-800">
                                    ${{ Number(vehiculoSeleccionado.precio_venta).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}
                                </span>
                                <span class="text-xs text-green-600 block mt-1">Precio en vitrina / patio</span>
                            </div>

                            <div class="bg-yellow-50 border border-yellow-300 rounded-lg p-4">
                                <span class="text-xs font-bold uppercase text-yellow-800 block">Margen Bruto Estimado</span>
                                <span class="text-2xl font-extrabold text-gray-900">
                                    ${{ calcularMargen(vehiculoSeleccionado).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}
                                </span>
                                <span class="text-xs text-gray-600 block mt-1">Antes de gastos de alistamiento</span>
                            </div>
                        </div>

                        <!-- Sección 1: Identificación Legal y Series -->
                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                            <h4 class="text-xs font-extrabold uppercase text-gray-700 border-b border-gray-300 pb-2 mb-3">
                                1. Series de Identificación Legal
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                                <div>
                                    <span class="text-gray-500 text-xs block">Número de Chasis (VIN):</span>
                                    <strong class="font-mono text-base text-gray-900 bg-white px-2 py-1 rounded border inline-block mt-0.5">
                                        {{ vehiculoSeleccionado.chasis }}
                                    </strong>
                                </div>
                                <div>
                                    <span class="text-gray-500 text-xs block">Número de Motor:</span>
                                    <strong class="font-mono text-base text-gray-900 bg-white px-2 py-1 rounded border inline-block mt-0.5">
                                        {{ vehiculoSeleccionado.motor }}
                                    </strong>
                                </div>
                                <div>
                                    <span class="text-gray-500 text-xs block">Cilindraje del Motor:</span>
                                    <strong class="text-base text-gray-900 block mt-1">
                                        {{ vehiculoSeleccionado.cilindraje }} cc
                                    </strong>
                                </div>
                            </div>
                        </div>

                        <!-- Sección 2: Ficha Técnica Completa -->
                        <div>
                            <h4 class="text-xs font-extrabold uppercase text-gray-700 border-b border-gray-200 pb-2 mb-3">
                                2. Especificaciones Técnicas de la Unidad
                            </h4>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                <div class="p-3 bg-gray-50 rounded border">
                                    <span class="text-gray-500 text-xs block">Marca</span>
                                    <strong class="text-gray-900">{{ vehiculoSeleccionado.marca?.nombre }}</strong>
                                </div>
                                <div class="p-3 bg-gray-50 rounded border">
                                    <span class="text-gray-500 text-xs block">Modelo</span>
                                    <strong class="text-gray-900">{{ vehiculoSeleccionado.modelo }}</strong>
                                </div>
                                <div class="p-3 bg-gray-50 rounded border">
                                    <span class="text-gray-500 text-xs block">Tipo de Vehículo</span>
                                    <strong class="text-gray-900">{{ vehiculoSeleccionado.tipo_vehiculo?.nombre || 'No especificado' }}</strong>
                                </div>
                                <div class="p-3 bg-gray-50 rounded border">
                                    <span class="text-gray-500 text-xs block">Tipo de Servicio</span>
                                    <strong class="text-gray-900">{{ vehiculoSeleccionado.servicio?.nombre || 'No especificado' }}</strong>
                                </div>
                                <div class="p-3 bg-gray-50 rounded border">
                                    <span class="text-gray-500 text-xs block">Clase de Vehículo</span>
                                    <strong class="text-gray-900">{{ vehiculoSeleccionado.clase?.nombre }}</strong>
                                </div>
                                <div class="p-3 bg-gray-50 rounded border">
                                    <span class="text-gray-500 text-xs block">Año de Fabricación</span>
                                    <strong class="text-gray-900">{{ vehiculoSeleccionado.anio }}</strong>
                                </div>
                                <div class="p-3 bg-gray-50 rounded border">
                                    <span class="text-gray-500 text-xs block">Color</span>
                                    <strong class="text-gray-900">{{ vehiculoSeleccionado.color?.nombre }}</strong>
                                </div>
                                <div class="p-3 bg-gray-50 rounded border">
                                    <span class="text-gray-500 text-xs block">País de Origen</span>
                                    <strong class="text-gray-900">{{ vehiculoSeleccionado.pais?.nombre }}</strong>
                                </div>
                                <div class="p-3 bg-gray-50 rounded border">
                                    <span class="text-gray-500 text-xs block">Tipo de Combustible</span>
                                    <strong class="text-gray-900">{{ vehiculoSeleccionado.tipo_combustible?.nombre }}</strong>
                                </div>
                                <div class="p-3 bg-gray-50 rounded border">
                                    <span class="text-gray-500 text-xs block">Transmisión</span>
                                    <strong class="text-gray-900">{{ vehiculoSeleccionado.transmision?.nombre }}</strong>
                                </div>
                                <div class="p-3 bg-gray-50 rounded border col-span-2">
                                    <span class="text-gray-500 text-xs block">Recorrido Actual (Kilometraje)</span>
                                    <strong class="text-gray-900 text-base">{{ vehiculoSeleccionado.kilometraje != null ? `${Number(vehiculoSeleccionado.kilometraje).toLocaleString()} km` : 'No definido' }}</strong>
                                </div>
                                <div class="p-3 bg-gray-50 rounded border col-span-2">
                                    <span class="text-gray-500 text-xs block">Patio / Sucursal Asignada</span>
                                    <strong class="text-gray-900 text-base">{{ vehiculoSeleccionado.patio?.nombre }}</strong>
                                </div>
                            </div>
                        </div>

                        <!-- Sección 3: Origen y Datos del Proveedor -->
                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                            <h4 class="text-xs font-extrabold uppercase text-gray-700 border-b border-gray-300 pb-2 mb-3">
                                3. Información del Proveedor / Dueño Anterior
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                                <div>
                                    <span class="text-gray-500 text-xs block">Nombre / Razón Social:</span>
                                    <strong class="text-gray-900">{{ vehiculoSeleccionado.proveedor?.nombre || 'No especificado' }}</strong>
                                </div>
                                <div>
                                    <span class="text-gray-500 text-xs block">RUC / Cédula:</span>
                                    <strong class="text-gray-900">{{ vehiculoSeleccionado.proveedor?.ruc_cedula || 'N/A' }}</strong>
                                </div>
                                <div>
                                    <span class="text-gray-500 text-xs block">Teléfono / Contacto:</span>
                                    <strong class="text-gray-900">{{ vehiculoSeleccionado.proveedor?.telefono || 'N/A' }}</strong>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Pie del Modal -->
                    <div class="bg-gray-100 px-6 py-4 flex justify-between items-center border-t">
                        <span class="text-xs text-gray-500">
                            Presiona <strong>ESC</strong> o haz clic fuera de la ventana para cerrar.
                        </span>
                        <button 
                            @click="cerrarDetalles" 
                            class="px-6 py-2 bg-gray-900 hover:bg-gray-800 text-white text-sm font-bold rounded-md shadow transition"
                        >
                            Cerrar Expediente
                        </button>
                    </div>
                </div>
            </div>
        </Transition>

    </AuthenticatedLayout>
</template>