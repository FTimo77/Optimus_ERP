# Guía Técnica y Convenciones del Proyecto — Optimus ERP (`concesionario-erp`)

Este documento resume la arquitectura, el modelo de dominio, los flujos críticos y las convenciones de código actuales del sistema ERP para concesionario vehicular (**Optimus ERP**). Úsalo como referencia obligatoria antes de planificar o implementar cualquier cambio.

---

## 1. Stack Tecnológico Principal

- **Backend**: PHP `^8.3`, **Laravel 13** (`^13.17`), **Laravel Sanctum** (`^4.0`), **Laravel Breeze** (`^2.4`).
- **Frontend (Monolito SPA)**: **Inertia.js v2** (`inertiajs/inertia-laravel ^2.0`, `@inertiajs/vue3 ^2.0`), **Vue 3** (`<script setup>` Composition API), **Ziggy v2** (`route()` en cliente).
- **Estilos y Bundler**: **Tailwind CSS** (`^3.2` / `@tailwindcss/forms`) compilado con **Vite 8** (`laravel-vite-plugin ^3.1`).
- **Roles y Permisos**: `spatie/laravel-permission ^8.5` (configurado en `User` mediante el trait `HasRoles` y poblado en `RolesAndPermissionsSeeder`).
- **Automatización / Scraping e IA**:
  - **Google Gemini API** (`services.gemini.api_key`, modelo por defecto `gemini-3.5-flash-lite`) invocado vía `Http` facade para OCR estructurado de matrículas vehiculares ecuatorianas.
  - **Scripts Node.js / Puppeteer (`^25.17.0`)**: Scripts CommonJS (`.cjs`) en la raíz del proyecto (`auditoria_completa.cjs`, `consultar_axis.cjs`, `consultar_sri.cjs`, `consultar_multas.cjs`, `consultar_fiscalia.cjs`, `consultar_propietario.cjs`) ejecutados desde PHP para consultar fuentes oficiales de Ecuador (AxisCloud/ANT, SRI, Autority/Servipagos, Fiscalía General del Estado, Privynote).
- **Calidad y Testing**: **PHPUnit 12** (`phpunit.xml` con SQLite `:memory:`) y **Laravel Pint** (`^1.27`).

---

## 2. Arquitectura y Organización de Directorios

El proyecto sigue la estructura estándar de Laravel sin capas intermedias innecesarias (no existen directorios `Actions/`, `Services/`, `Repositories/` ni `DTOs/`):

- **`app/Http/Controllers/`**: Controladores multi-acción que concentran la validación, la lógica de negocio, la orquestación de transacciones (`DB::transaction`) y las respuestas Inertia (`Inertia::render`) o JSON (`response()->json()`).
  - `InventarioVehiculoController`: Listado general de inventario (`Vehiculos/Inventario`).
  - `VehiculoIngresoController`: Creación/adquisición de vehículos (`Vehiculos/Ingreso`), consulta rápida a AxisCloud (`consultarAxisCloud`) y escaneo OCR + auditoría integral (`escanearYCompararMatricula`).
  - `ValidacionDocumentosController`: Cola de revisión documental (`ValidacionDocumentos/Index`, `ValidacionDocumentos/Show`), descarga segura de documentos privados (`documento`, `cuv`), aprobación/rechazo con creación atómica del vehículo (`decidir`) y reenvío de trámites rechazados (`reenviar`).
- **`app/Models/`**: Modelos Eloquent directos.
- **`resources/js/`**:
  - `Pages/`: Vistas principales de Inertia (`Vehiculos/Ingreso.vue`, `Vehiculos/Inventario.vue`, `ValidacionDocumentos/Index.vue`, `ValidacionDocumentos/Show.vue`, `Dashboard.vue`, `Auth/*`, `Profile/*`).
  - `Layouts/`: `AuthenticatedLayout.vue` y `GuestLayout.vue`.
  - `Components/`: Componentes reutilizables base de Breeze/UI (`PrimaryButton`, `SecondaryButton`, `DangerButton`, `Modal`, `TextInput`, `Dropdown`, etc.).
- **`routes/web.php`**: Concentra todas las rutas de la aplicación bajo el middleware `auth` (incluidos los endpoints AJAX/JSON consumidos con `axios` desde Vue, como `/vehiculos/consultar-axis` y `/vehiculos/escanear-matricula`).

---

## 3. Modelo de Datos y Convenciones de Base de Datos / Eloquent

### 3.1 Claves Primarias y Nomenclatura de Esquema
- **Dominio en español (`snake_case`)**: Las tablas, columnas, modelos, controladores de negocio, rutas y variables de dominio se redactan en español.
- **Claves primarias personalizadas (`id_<entidad>`)**:
  - **`vehiculos`**: La clave primaria es la **placa normalizada** en formato string (`id_vehiculo`, `varchar(20)`, `$incrementing = false`, `$keyType = 'string'`).
  - **Catálogos y tablas operativas**: Usan claves primarias enteras autoincrementales con prefijo `id_`, por ejemplo:
    - `marca_vehiculos` (`id_marca`)
    - `clase_vehiculos` (`id_clase`)
    - `paises` (`id_pais`)
    - `color_vehiculos` (`id_color`)
    - `tipo_combustibles` (`id_tipo_combustible`)
    - `transmisiones` (`id_transmision`)
    - `tipo_vehiculos` (`id_tipo_vehiculo`)
    - `servicios` (`id_servicio`)
    - `patios` (`id_patio`)
    - `estado_vehiculos` (`id_estado`)
    - `proveedores` (`id_proveedor`)
    - `documentos_vehiculos` (`id_documento_vehiculo`)
    - `imagen_vehiculos` (`id_imagen`)
    - `clientes` (`id_cliente`), `ventas` (`id_venta`), `metodo_pagos` (`id_metodo_pago`), `movimientos_vehiculos` (`id_movimiento`), `transacciones_cajas` (`id_transaccion`).
  - **Excepción estándar**: `users` y `validacion_documentos` usan clave primaria estándar `id` (`$table->id()`).

### 3.2 Convenciones en Modelos Eloquent
- **Nombres de archivos y clases**: Coexisten archivos de modelos históricos en minúsculas/snake_case (`vehiculo.php`, `marca_vehiculo.php`, `documentos_vehiculo.php`) con modelos recientes en `StudlyCase` (`ValidacionDocumento.php`, `TipoVehiculo.php`, `Servicio.php`). En Windows el sistema de archivos es *case-insensitive*, pero para **nuevos modelos** o referencias de clase debe usarse **`StudlyCase` (PSR-4)** y definir explícitamente `$table` y `$primaryKey` cuando no sigan la convención en inglés de Laravel.
- **Asignación masiva**: Todos los modelos usan `$fillable` explícito (en `User` mediante el atributo PHP 8 `#[Fillable]`).
- **Casts**: Se definen mediante el método `protected function casts(): array` (estilo Laravel 11+).
- **Datos sensibles cifrados en reposo**: En `ValidacionDocumento`, las estructuras JSON de auditoría y adquisición (`datos_documento`, `datos_oficiales`, `comparacion`, `resumen_auditoria`, `datos_ingreso`) usan el cast `'encrypted:array'`.
- **Creación dinámica de catálogos**: Al recibir datos de fuentes externas (AxisCloud), los catálogos (`marca_vehiculo`, `clase_vehiculo`, `pais`, `color_vehiculo`, `TipoVehiculo`, `Servicio`, `estado_vehiculo`) se resuelven de forma idempotente con `firstOrCreate(['nombre' => ...])`.

---

## 4. Flujos de Negocio Críticos y Almacenamiento de Archivos

### 4.1 Ingreso de Vehículos y Auditoría Documental
Existen tres caminos en `VehiculoIngresoController::store`:
1. **Ingreso con auditoría previa (`validacion_id` presente)**: El usuario escaneó la matrícula (`escanearYCompararMatricula`), lo que generó un registro `ValidacionDocumento` en estado `'borrador'`. Al enviar el formulario de ingreso, el expediente pasa a estado `'pendiente'` (`enviarAuditoriaARevision`) y **el vehículo no se crea aún en `vehiculos`** hasta que sea aprobado en el módulo de Validación de Documentos.
2. **Ingreso con documento sin auditoría automática (`documento_matricula` adjunto sin `validacion_id`)**: Cuando Gemini o AxisCloud fallan (`manual_review_available: true`), el documento y los datos de ingreso se envían directamente a la cola de revisión manual en estado `'pendiente'` (`enviarDocumentoSinAuditoriaARevision`).
3. **Ingreso directo sin expediente bloqueante**: Solo permitido si la placa no tiene una auditoría en estado `'borrador'`, `'pendiente'` o `'rechazada'`. Crea el vehículo en estado `'Creado'` dentro de una transacción.

### 4.2 Ciclo de Vida de `ValidacionDocumento` (`estado_revision`)
- `borrador`: Creado tras escanear la matrícula con IA + robot de auditoría; visible únicamente para el usuario que lo subió (`subido_por`) durante el llenado del formulario.
- `pendiente`: Enviado a la cola de revisión (`ValidacionDocumentosController`).
- `aprobada`: El revisor aprueba el expediente en `ValidacionDocumentosController::decidir`. Dentro de `DB::transaction()` con `lockForUpdate()`, se crea el registro en `vehiculos`, se registran `Documentos_vehiculo` (`ruta_matricula`, `ruta_cuv`) y se mueven las imágenes del disco privado al público.
- `rechazada`: El revisor rechaza el expediente. El autor original (`subido_por`) puede subsanar y reenviar a `'pendiente'` mediante `reenviar` agregando `comentario_reenvio`.

### 4.3 Regla Global de Arquitectura y Seguridad — Almacenamiento Privado de Documentos (`Storage`)
- **Regla obligatoria para todo el proyecto (módulos actuales y futuros)**: **Ningún documento confidencial, legal, financiero o de respaldo** (matrículas, CUV, contratos, poderes, mandatos, cédulas, comprobantes de pago/abonos, comprobantes de multas, constancias, actas, etc.) debe guardarse jamás en el disco `'public'` (`storage/app/public`).
- **Disco `local` (Privado -> `storage/app/private/`)**:
  - Todo `->store(...)` o escritura de documentos confidenciales/legales/financieros en controladores y servicios debe apuntar **exclusivamente** al disco `'local'` (nunca `'public'`).
  - Incluye: matrículas (`validacion-documentos/...`), CUV (`validacion-documentos/{id}/cuv/...`), cédulas (`documentos/cedulas/...`), contratos generados o legalizados (`documentos/contratos/...`), comprobantes de pago/abonos (`validacion-documentos/{id}/pagos/...`, `vehiculos/{placa}/pagos/...`), comprobantes de multas (`vehiculos/{placa}/multas/...`) e imágenes temporales de vehículos en revisión (`validacion-documentos/{id}/imagenes/...`).
  - **Zero exposición por URL directa**: En el frontend (Vue) está prohibido usar enlaces directos `/storage/...` para documentos. Toda visualización o descarga debe pasar por rutas protegidas en `routes/web.php` bajo `middleware(['auth'])` (y roles/permisos de Spatie cuando corresponda), entregando el archivo desde el controlador mediante `Storage::disk('local')->response($ruta)` o `Storage::disk('local')->download($ruta)` tras validar su existencia (`abort_unless(Storage::disk('local')->exists(...), 404)`) con cabecera `'X-Content-Type-Options' => 'nosniff'`.
- **Disco `public` (Público -> `storage/app/public/`)**:
  - Reservado **únicamente** para las fotografías comerciales exteriores/interiores de los vehículos aprobados que se muestran en el catálogo/inventario (`vehiculos/...`), referenciadas en `imagen_vehiculos` (la primera imagen lleva `es_principal = true`).
- **Limpieza ante fallos**: Toda operación que escriba archivos en `Storage` antes o durante una transacción `DB::transaction` debe capturar `Throwable` y eliminar los archivos creados si la transacción falla.

---

## 5. Convenciones de Código y Desarrollo

### 5.1 PHP y Controladores
- **Tipado estricto y moderno**: Declarar siempre tipos de parámetros y tipos de retorno explícitos (`Response`, `RedirectResponse`, `JsonResponse`, `StreamedResponse`, `void`), además de bloques PHPDoc con *array shapes* (`@return array<string, mixed>`, `@param list<string>`) cuando aplique.
- **Validación**:
  - Se utiliza validación inline con `$request->validate([...])` o `Validator::make(...)` usando sintaxis de arreglos (`['required', 'string', ...]`) en los controladores de negocio.
  - Los errores de reglas de negocio puntuales se disparan mediante `throw ValidationException::withMessages(['campo' => 'Mensaje en español'])`.
- **Concurrencia y Transacciones**:
  - Usar `DB::transaction(function () { ... })` junto con `->lockForUpdate()` al mutar estados críticos (como aprobar, rechazar o reenviar expedientes en `ValidacionDocumento`).
- **Normalización de Placas Ecuatorianas**:
  - Limpiar siempre con `strtoupper(preg_replace('/[^A-Z0-9]/', '', $placa))`.
  - Si una placa tiene formato de 3 letras y 3 dígitos (`^[A-Z]{3}\d{3}$`), normalizar a 4 dígitos insertando un `0` después de las letras (`ABC123` -> `ABC0123`).
- **Rutas y Redirecciones**:
  - Usar siempre rutas con nombre (`route('...')`, `to_route('...')`) y mensajes flash en sesión (`->with('success', '...')`).
- **Formateo**:
  - Ejecutar `vendor/bin/pint --dirty` después de modificar archivos PHP.

### 5.2 Frontend (Vue 3 + Inertia v2 + Tailwind)
- **Estructura de componentes**: Usar `<script setup>` en todos los componentes Vue, con un único elemento raíz en `<template>` cuando el componente se renderiza como página de Inertia.
- **Formularios y Peticiones**:
  - Usar `useForm` de `@inertiajs/vue3` para envíos de formularios Inertia (`form.post`, `form.patch`, `form.delete`).
  - Usar `axios` + `route('nombre.ruta')` (Ziggy) para acciones asíncronas en segundo plano que devuelven JSON sin recargar la vista (como consultar AxisCloud o escanear matrícula).
- **Idioma de la interfaz**: Todo texto visible al usuario, etiquetas, mensajes de error y comentarios explicativos de negocio se escriben en **español** (configuración regional `es-EC`).
- **Estilos**: Utilizar utilidades de Tailwind CSS directamente en las plantillas (paleta predominante en grises oscuros `bg-gray-900`, acentos en amarillo `bg-yellow-500` / `text-yellow-600`, alertas semánticas en verde/rojo/ámbar).

### 5.3 Integraciones Externas (Gemini y Scripts `.cjs`)
- **Gemini API**:
  - Leer credenciales únicamente desde `config('services.gemini.api_key')` y `config('services.gemini.model')` (nunca `env()` directo fuera de archivos `config/`).
  - Enviar la clave en el header `x-goog-api-key` con `Http::acceptJson()->connectTimeout(10)->timeout(60)`.
- **Scripts Node.js (`.cjs`)**:
  - Para facilitar las pruebas automatizadas, la invocación a `exec(...)` en controladores debe encapsularse en métodos `protected` (como `ejecutarAuditoriaCompleta` o `ejecutarConsultaAxis`), permitiendo su sustitución mediante subclases de prueba sin lanzar procesos reales de Puppeteer.
  - Los archivos temporales de intercambio JSON en `storage/app/` deben usar nombres aleatorios seguros (`bin2hex(random_bytes(12))`) y eliminarse con `@unlink` inmediatamente después de su lectura.

### 5.4 Pruebas Automatizadas (PHPUnit)
- **Ubicación y Estilo**: Pruebas de integración/feature en `tests/Feature/` extendiendo `Tests\TestCase` y usando el trait `RefreshDatabase`.
- **Nomenclatura**: Métodos en `snake_case` con prefijo `test_` y nombres descriptivos del comportamiento (ej. `test_reviewer_can_approve_a_pending_document_and_create_the_vehicle`).
- **Aislamiento**:
  - Usar `Storage::fake('local')` y `Storage::fake('public')` al probar subida, descarga o limpieza de archivos.
  - Usar `Http::preventStrayRequests()` y `Http::fake([...])` al probar integraciones con Gemini.
  - Verificar respuestas de Inertia mediante `assertInertia(fn (Assert $page) => $page->component(...)->where(...))`.
- **Ejecución**: Ejecutar pruebas específicas con `php artisan test --filter=NombreDelTest`.
