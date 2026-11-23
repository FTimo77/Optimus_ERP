<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    usuarios: {
        type: Array,
        default: () => [],
    },
    rolesDisponibles: {
        type: Array,
        default: () => [],
    },
    success: {
        type: String,
        default: null,
    },
});

const page = usePage();
const usuarioActualId = computed(() => page.props.auth?.user?.id ?? null);

const busqueda = ref('');
const filtroRol = ref('');
const guardandoPorUsuario = reactive({});
const errorPorUsuario = reactive({});
const seleccionPorUsuario = reactive({});

const sincronizarSelecciones = (lista) => {
    for (const usuario of lista || []) {
        seleccionPorUsuario[usuario.id] = usuario.rol_actual || '';
    }
};

watch(() => props.usuarios, sincronizarSelecciones, { immediate: true });

const usuariosFiltrados = computed(() => {
    const termino = busqueda.value.trim().toLowerCase();

    return (props.usuarios || []).filter((usuario) => {
        if (termino) {
            const nombre = String(usuario.name || '').toLowerCase();
            const correo = String(usuario.email || '').toLowerCase();
            if (!nombre.includes(termino) && !correo.includes(termino)) {
                return false;
            }
        }

        if (filtroRol.value === '__sin_rol__') {
            return !usuario.rol_actual;
        }

        if (filtroRol.value && usuario.rol_actual !== filtroRol.value) {
            return false;
        }

        return true;
    });
});

const formatearFecha = (fecha) => {
    if (!fecha) return 'No registrada';
    return new Date(fecha).toLocaleDateString('es-EC', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};

const clasesBadgeRol = (rol) => {
    if (!rol) return 'border-amber-200 bg-amber-50 text-amber-800';
    if (rol === 'Administrador') return 'border-purple-200 bg-purple-100 text-purple-800';
    if (rol === 'Administracion') return 'border-indigo-200 bg-indigo-100 text-indigo-800';
    return 'border-emerald-200 bg-emerald-50 text-emerald-800';
};

const tieneCambio = (usuario) =>
    (seleccionPorUsuario[usuario.id] ?? '') !== (usuario.rol_actual || '');

const guardarRol = (usuario) => {
    errorPorUsuario[usuario.id] = '';
    guardandoPorUsuario[usuario.id] = true;

    router.patch(
        route('usuarios.rol.update', usuario.id),
        { role: seleccionPorUsuario[usuario.id] || null },
        {
            preserveScroll: true,
            onError: (errors) => {
                errorPorUsuario[usuario.id] = errors.role || 'No se pudo actualizar el rol.';
            },
            onFinish: () => {
                guardandoPorUsuario[usuario.id] = false;
            },
        },
    );
};
</script>

<template>
    <Head title="Gestión de Usuarios" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-center">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">
                        Gestión de Usuarios y Roles
                    </h2>
                    <p class="mt-1 text-xs text-gray-500">
                        Administra los usuarios registrados en el sistema y asigna sus roles operativos.
                    </p>
                </div>
                <span class="w-fit rounded-full bg-gray-900 px-3 py-1 text-xs font-bold text-yellow-400">
                    Solo Administrador
                </span>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                <div
                    v-if="success"
                    role="status"
                    class="rounded-md border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800"
                >
                    {{ success }}
                </div>

                <!-- BARRA DE FILTROS -->
                <div class="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:flex-row sm:items-end sm:justify-between">
                    <div class="grid flex-1 grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label for="busqueda-usuarios" class="block text-xs font-semibold text-gray-700">
                                Buscar usuario
                            </label>
                            <input
                                id="busqueda-usuarios"
                                v-model="busqueda"
                                type="search"
                                placeholder="Nombre o correo electrónico..."
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-yellow-500 focus:ring-yellow-500"
                            />
                        </div>
                        <div>
                            <label for="filtro-rol" class="block text-xs font-semibold text-gray-700">
                                Filtrar por rol
                            </label>
                            <select
                                id="filtro-rol"
                                v-model="filtroRol"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-yellow-500 focus:ring-yellow-500"
                            >
                                <option value="">Todos los roles</option>
                                <option value="__sin_rol__">Sin rol asignado</option>
                                <option v-for="rol in rolesDisponibles" :key="rol" :value="rol">
                                    {{ rol }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <p class="text-xs text-gray-600">
                        Mostrando <strong class="text-gray-900">{{ usuariosFiltrados.length }}</strong> de
                        <strong class="text-gray-900">{{ usuarios.length }}</strong> usuario(s)
                    </p>
                </div>

                <!-- TABLA DE USUARIOS -->
                <div class="overflow-hidden border border-gray-200 bg-white shadow-sm sm:rounded-lg">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-900 text-white">
                                <tr>
                                    <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Usuario</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Correo</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Registro</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Rol actual</th>
                                    <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-wider">Asignar rol</th>
                                    <th class="px-4 py-3.5 text-right text-xs font-bold uppercase tracking-wider">Acción</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr v-if="usuariosFiltrados.length === 0">
                                    <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">
                                        No se encontraron usuarios con los criterios seleccionados.
                                    </td>
                                </tr>
                                <tr
                                    v-for="usuario in usuariosFiltrados"
                                    :key="usuario.id"
                                    class="hover:bg-gray-50"
                                >
                                    <td class="whitespace-nowrap px-4 py-3 font-semibold text-gray-900">
                                        <div class="flex items-center gap-2">
                                            <span>{{ usuario.name }}</span>
                                            <span
                                                v-if="usuario.id === usuarioActualId"
                                                class="rounded bg-yellow-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-yellow-800"
                                            >
                                                Tú
                                            </span>
                                        </div>
                                        <p v-if="usuario.patio_nombre" class="text-xs font-normal text-gray-500">
                                            Patio: {{ usuario.patio_nombre }}
                                        </p>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">
                                        {{ usuario.email }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-500">
                                        {{ formatearFecha(usuario.created_at) }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <span
                                            :class="clasesBadgeRol(usuario.rol_actual)"
                                            class="inline-flex rounded-full border px-2.5 py-0.5 text-xs font-bold"
                                        >
                                            {{ usuario.rol_actual || 'Sin rol' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <select
                                            v-model="seleccionPorUsuario[usuario.id]"
                                            :aria-label="`Rol para ${usuario.name}`"
                                            :disabled="guardandoPorUsuario[usuario.id]"
                                            class="block w-full min-w-[190px] rounded-md border-gray-300 text-xs shadow-sm focus:border-yellow-500 focus:ring-yellow-500"
                                        >
                                            <option value="">Sin rol</option>
                                            <option
                                                v-for="rol in rolesDisponibles"
                                                :key="rol"
                                                :value="rol"
                                            >
                                                {{ rol }}
                                            </option>
                                        </select>
                                        <p
                                            v-if="errorPorUsuario[usuario.id]"
                                            class="mt-1 text-xs font-medium text-red-600"
                                            role="alert"
                                        >
                                            {{ errorPorUsuario[usuario.id] }}
                                        </p>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            :disabled="!tieneCambio(usuario) || guardandoPorUsuario[usuario.id]"
                                            class="rounded-md bg-yellow-500 px-3 py-1.5 text-xs font-bold text-black shadow-sm transition hover:bg-yellow-600 disabled:cursor-not-allowed disabled:opacity-40"
                                            @click="guardarRol(usuario)"
                                        >
                                            {{ guardandoPorUsuario[usuario.id] ? 'Guardando...' : 'Guardar' }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
