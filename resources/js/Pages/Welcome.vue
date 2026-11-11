<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
    laravelVersion: {
        type: String,
        required: true,
    },
    phpVersion: {
        type: String,
        required: true,
    },
});

function handleImageError() {
    document.getElementById('screenshot-container')?.classList.add('!hidden');
    document.getElementById('background')?.classList.add('!hidden');
}
</script>

<template>
    <Head title="Bienvenido" />
    <div class="bg-gray-50 text-black/50 dark:bg-black dark:text-white/50">
        <img
            id="background"
            class="absolute -left-20 top-0 max-w-[877px]"
            src="/assets/img/welcome/background.jpg"
            alt="Fondo"
        />
        <div
            class="relative flex min-h-screen flex-col items-center justify-center selection:bg-[#FF2D20] selection:text-white"
        >
            <div class="relative w-full max-w-2xl px-3 lg:max-w-7xl">
                <header
                    class="grid grid-cols-2 items-center gap-2 py-2 lg:grid-cols-3"
                >
                    <div class="flex lg:col-start-2 lg:justify-center">
                        <svg
                            class="h-24 w-auto text-black-400 lg:h-36"
                            viewBox="100 150 340 220"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <g transform="translate(0 572) scale(0.1 -0.1)" fill="currentColor" stroke="none">
                                <path d="M2675 4070 c-129 -18 -268 -82 -368 -170 -45 -40 -137 -158 -137 -176 0 -5 46 -21 103 -35 l102 -27 67 64 c104 97 194 134 328 134 134 0 250 -49 342 -145 l51 -52 78 20 78 19 -31 47 c-79 114 -211 220 -319 256 -28 9 -48 19 -46 22 8 7 121 -41 184 -78 67 -40 179 -149 208 -202 19 -36 55 -51 55 -23 0 24 -103 149 -165 199 -143 115 -348 172 -530 147z"/>
                                <path d="M1987 3700 c3 -11 13 -25 22 -29 9 -5 169 -57 356 -116 286 -90 350 -107 405 -107 55 0 119 17 404 107 187 58 347 110 357 115 18 10 33 50 18 50 -5 0 -146 -35 -312 -77 l-302 -77 -165 0 -165 0 -302 77 c-166 42 -307 77 -312 77 -5 0 -7 -9 -4 -20z"/>
                                <path d="M2015 3619 c4 -19 16 -41 28 -49 11 -8 108 -60 216 -116 331 -169 356 -183 369 -205 8 -12 30 -149 51 -305 33 -257 39 -287 60 -307 l23 -22 -7 340 c-8 374 -12 401 -71 442 -16 11 -168 70 -339 133 -170 62 -316 115 -324 118 -10 4 -12 -3 -6 -29z m248 -86 c15 -5 26 -53 12 -53 -15 0 -225 112 -225 120 0 5 44 -7 98 -27 53 -19 105 -38 115 -40z"/>
                                <path d="M3199 3532 c-173 -65 -328 -125 -343 -135 -60 -40 -64 -69 -72 -442 l-6 -340 22 22 c20 19 27 51 61 306 20 157 43 294 51 306 7 12 119 76 258 148 319 165 334 173 341 181 11 12 21 72 12 71 -4 0 -150 -53 -324 -117z m291 69 c0 -8 -209 -121 -224 -121 -3 0 -6 10 -6 23 0 20 14 28 113 64 61 23 113 42 115 42 1 1 2 -3 2 -8z"/>
                                <path d="M2084 3463 c-21 -110 23 -314 91 -427 94 -157 263 -282 434 -320 l63 -15 -7 62 c-12 112 -13 114 -54 121 -20 3 -61 17 -91 32 -177 82 -280 242 -280 433 l0 77 -75 37 -74 36 -7 -36z"/>
                                <path d="M3434 3492 c-7 -4 -13 -54 -16 -127 -6 -138 -32 -224 -100 -331 -39 -62 -157 -182 -200 -205 -27 -14 -21 -7 50 50 90 74 182 222 210 341 16 69 25 176 18 215 l-7 35 -68 -34 -67 -34 -12 -78 c-26 -165 -127 -301 -275 -369 l-74 -34 -11 -95 c-17 -138 -22 -127 51 -110 81 19 208 82 276 137 159 132 252 333 250 539 0 81 -8 111 -25 100z"/>
                                <path d="M2262 3329 c9 -127 57 -228 154 -322 75 -74 234 -144 234 -104 0 11 -28 29 -76 51 -149 69 -248 202 -276 372 -8 48 -19 79 -27 82 -11 4 -13 -11 -9 -79z"/>
                                <path d="M1400 2429 c-113 -14 -154 -65 -154 -193 0 -103 20 -148 78 -179 35 -18 58 -21 151 -22 127 0 176 17 210 76 27 46 28 197 1 247 -32 61 -145 89 -286 71z m161 -105 c44 -40 32 -176 -17 -194 -45 -18 -119 -12 -148 11 -24 19 -26 27 -26 93 0 77 10 97 55 109 35 10 117 -2 136 -19z"/>
                                <path d="M4045 2430 c-97 -11 -135 -43 -135 -112 0 -83 37 -111 160 -123 83 -8 110 -18 110 -41 0 -26 -50 -36 -145 -31 -49 3 -98 8 -107 12 -16 6 -18 1 -18 -39 0 -41 3 -46 28 -53 15 -4 79 -8 142 -8 98 0 121 3 155 21 46 24 65 54 65 105 0 71 -44 100 -175 114 -33 4 -68 9 -77 11 -28 8 -22 43 10 55 26 10 120 7 190 -7 l32 -6 0 45 0 46 -47 6 c-92 11 -128 12 -188 5z"/>
                                <path d="M1770 2235 l0 -195 60 0 60 0 0 44 0 43 89 6 c156 10 197 45 189 163 -7 112 -52 134 -273 134 l-125 0 0 -195z m261 86 c24 -29 24 -53 2 -80 -14 -16 -33 -22 -80 -25 l-63 -5 0 71 0 70 61 -4 c49 -4 66 -9 80 -27z"/>
                                <path d="M2180 2390 l0 -39 68 -3 67 -3 3 -152 3 -153 59 0 60 0 0 156 0 156 65 -4 65 -3 0 43 0 42 -195 0 -195 0 0 -40z"/>
                                <path d="M2610 2235 l0 -195 60 0 60 0 0 195 0 195 -60 0 -60 0 0 -195z"/>
                                <path d="M2810 2235 l0 -195 55 0 55 0 1 143 1 142 47 -140 48 -140 68 0 68 0 48 140 47 140 1 -142 1 -143 55 0 55 0 0 195 0 195 -99 0 -98 0 -33 -117 c-18 -65 -36 -124 -40 -131 -5 -11 -14 15 -65 191 l-16 57 -100 0 -99 0 0 -195z"/>
                                <path d="M3440 2284 c0 -169 10 -202 68 -231 52 -25 223 -25 269 0 61 32 68 54 72 224 l3 153 -61 0 -61 0 -1 -117 c-1 -140 -4 -164 -26 -180 -23 -18 -94 -16 -121 3 -21 14 -22 22 -22 155 l0 139 -60 0 -60 0 0 -146z"/>
                            </g>
                        </svg>
                    </div>
                    <nav v-if="canLogin" class="-mx-3 flex flex-1 justify-end">
                        <Link
                            v-if="$page.props.auth.user"
                            :href="route('dashboard')"
                            class="rounded-md px-3 py-2 text-black ring-1 ring-transparent transition hover:text-black/70 focus:outline-none focus-visible:ring-[#FF2D20] dark:text-white dark:hover:text-white/80 dark:focus-visible:ring-white"
                        >
                            Dashboard
                        </Link>

                        <template v-else>
                            <Link
                                :href="route('login')"
                                class="rounded-md px-3 py-2 text-black ring-1 ring-transparent transition hover:text-black/70 focus:outline-none focus-visible:ring-[#FF2D20] dark:text-white dark:hover:text-white/80 dark:focus-visible:ring-white"
                            >
                                Iniciar Sesión
                            </Link>

                            <Link
                                v-if="canRegister"
                                :href="route('register')"
                                class="rounded-md px-3 py-2 text-black ring-1 ring-transparent transition hover:text-black/70 focus:outline-none focus-visible:ring-[#FF2D20] dark:text-white dark:hover:text-white/80 dark:focus-visible:ring-white"
                            >
                                Registrarse
                            </Link>
                        </template>
                    </nav>
                </header>

                <main class="mt-6">
                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div
                            id="screenshot-container"
                            class="relative w-full"
                        >
                            <img
                                src="/assets/img/welcome/banner1.jpg"
                                alt="Promoción del concesionario"
                                class="block h-auto w-full rounded-[10px] object-contain drop-shadow-[0px_4px_34px_rgba(0,0,0,0.06)] dark:hidden"
                                @error="handleImageError"
                            />
                            <img
                                src="/assets/img/welcome/banner1.jpg"
                                alt="Promoción del concesionario"
                                class="hidden h-auto w-full rounded-[10px] object-contain drop-shadow-[0px_4px_34px_rgba(0,0,0,0.25)] dark:block"
                            />
                        </div>
                        <div
                            id="screenshot-container"
                            class="relative w-full"
                        >
                            <img
                                src="/assets/img/welcome/banner2.jpg"
                                alt="Promoción del concesionario"
                                class="block h-auto w-full rounded-[10px] object-contain drop-shadow-[0px_4px_34px_rgba(0,0,0,0.06)] dark:hidden"
                                @error="handleImageError"
                            />
                            <img
                                src="/assets/img/welcome/banner2.jpg"
                                alt="Promoción del concesionario"
                                class="hidden h-auto w-full rounded-[10px] object-contain drop-shadow-[0px_4px_34px_rgba(0,0,0,0.25)] dark:block"
                            />
                        </div>
                    </div>

                    <div class="grid gap-6">
                        
                    </div>
                </main>

                <footer
                    class="py-16 text-center text-sm text-black dark:text-white/70"
                >
                    Optimus ERP &copy; 2024. Todos los derechos reservados.
                </footer>
            </div>
        </div>
    </div>
</template>
