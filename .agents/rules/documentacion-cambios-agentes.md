---
trigger: always_on
description: Regla obligatoria para documentar cambios técnicos realizados por agentes en Optimus ERP.
---

# Regla del Proyecto — Documentación de Cambios Realizados por Agentes

Todo agente que implemente cambios técnicos relevantes en este repositorio **DEBE** mantener actualizada la documentación del proyecto en `documentacion.md` (en la raíz del repositorio), siguiendo estas directrices:

## 1. Cuándo documentar (Obligatorio)
Se debe agregar o actualizar una entrada en `documentacion.md` al finalizar cualquiera de los siguientes cambios:
- Nuevas migraciones, tablas, columnas, llaves foráneas o seeders.
- Nuevos módulos, controladores, servicios, modelos Eloquent o rutas en `routes/web.php`.
- Cambios en flujos críticos de negocio (validación documental, inventario, ventas/reservas, créditos, cobranzas, caja, roles/permisos).
- Cambios en integraciones externas (Gemini, Puppeteer/Node `.cjs`, generación de documentos Word, proxy/despliegue).

> **Excepción:** No es necesario modificar `documentacion.md` para consultas informativas sin cambios de código ni para ajustes menores de texto o estilos CSS, salvo que el usuario lo solicite explícitamente.

## 2. Formato estándar de cada entrada
Cada cambio documentado debe incluir de forma concisa y estructurada:
1. **Fecha y título del cambio** (`YYYY-MM-DD — Nombre del módulo o funcionalidad`).
2. **Resumen funcional**: qué problema resuelve y qué reglas de negocio aplica.
3. **Base de datos** (si aplica): migraciones, tablas/columnas agregadas o modificadas, llaves primarias/foráneas y seeders.
4. **Archivos creados o modificados**: rutas relativas exactas (backend y frontend).
5. **Rutas, permisos y seguridad**: endpoints nuevos, roles/permisos Spatie exigidos y confirmación de almacenamiento privado en disco `local` para documentos confidenciales.
6. **Comandos requeridos en local** (si aplica): por ejemplo `php artisan migrate`, `php artisan db:seed`, `npm run build`.

## 3. Restricciones de seguridad
- **Nunca** escribir claves API (`GEMINI_API_KEY`), contraseñas, tokens ni valores reales del archivo `.env` en la documentación.
- Mantener todo el contenido redactado en **español** y coherente con `AGENTS.md`.
