const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

if (!process.env.PUPPETEER_CACHE_DIR && process.platform === 'win32') {
    process.env.PUPPETEER_CACHE_DIR = 'C:\\Users\\LENOVO\\.cache\\puppeteer';
}

const placaRaw = (process.argv[2] || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
const archivoSalida = process.argv[3] || '';

if (!placaRaw) {
    console.log(JSON.stringify({ ok: false, error: 'Placa vacía. Uso: node ./scripts/auditoria/cte.cjs PFI0582' }));
    process.exit(1);
}

const placa = /^([A-Z]{3})(\d{3})$/.test(placaRaw)
    ? placaRaw.replace(/^([A-Z]{3})(\d{3})$/, '$10$2')
    : placaRaw;

const dirStorageApp = path.resolve(__dirname, '../../storage/app');
const rutaSesionLegacy = path.join(dirStorageApp, 'cte_session.json');
const rutaLockCTE = path.join(dirStorageApp, 'cte_consulta.lock');
const rutaCooldownCTE = path.join(dirStorageApp, 'cte_cooldown.json');

const LOCK_STALE_MS = 45000;
const LOCK_WAIT_MAX_MS = 35000;
const COOLDOWN_LIMITE_SESIONES_MS = 90000;

const esperar = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function obtenerRutaNavegadorWindows() {
    if (process.platform !== 'win32') return undefined;
    const rutas = [
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'
    ];
    for (const r of rutas) {
        if (fs.existsSync(r)) return r;
    }
    return undefined;
}

async function adquirirLockLocal() {
    fs.mkdirSync(dirStorageApp, { recursive: true });
    const inicio = Date.now();

    while (Date.now() - inicio < LOCK_WAIT_MAX_MS) {
        try {
            if (fs.existsSync(rutaLockCTE)) {
                const stat = fs.statSync(rutaLockCTE);
                if (Date.now() - stat.mtimeMs > LOCK_STALE_MS) {
                    fs.unlinkSync(rutaLockCTE);
                }
            }
            const fd = fs.openSync(rutaLockCTE, 'wx');
            fs.writeFileSync(fd, JSON.stringify({ pid: process.pid, startedAt: Date.now() }), 'utf8');
            fs.closeSync(fd);
            return true;
        } catch (err) {
            if (err && err.code !== 'EEXIST') {
                return false;
            }
            await esperar(400);
        }
    }

    return false;
}

function liberarLockLocal() {
    try {
        if (fs.existsSync(rutaLockCTE)) {
            fs.unlinkSync(rutaLockCTE);
        }
    } catch (e) {}
}

function verificarCooldownActivo() {
    try {
        if (!fs.existsSync(rutaCooldownCTE)) return null;
        const data = JSON.parse(fs.readFileSync(rutaCooldownCTE, 'utf8'));
        const restanteMs = (data?.hasta || 0) - Date.now();
        if (restanteMs > 0) {
            return Math.ceil(restanteMs / 1000);
        }
        fs.unlinkSync(rutaCooldownCTE);
    } catch (e) {}
    return null;
}

function registrarCooldownLimiteSesiones() {
    try {
        fs.mkdirSync(dirStorageApp, { recursive: true });
        fs.writeFileSync(
            rutaCooldownCTE,
            JSON.stringify({ hasta: Date.now() + COOLDOWN_LIMITE_SESIONES_MS }),
            'utf8'
        );
    } catch (e) {}
}

function limpiarCookiesLegacy() {
    try {
        if (fs.existsSync(rutaSesionLegacy)) {
            fs.unlinkSync(rutaSesionLegacy);
        }
    } catch (e) {}
}

function procesarRespuestaCTE(jsonCTE, placaBuscada, debugExtra) {
    if (!jsonCTE || !jsonCTE.lsTotales) {
        return {
            ok: false,
            fuente: 'CTE',
            placa: placaBuscada,
            tiene_multas: false,
            cantidad: 0,
            total: 0,
            listado: [],
            requiere_revision_manual: true,
            error: jsonCTE?.lsError || jsonCTE?.error_script || 'Sin respuesta de CTE AxisCloud',
            debug: debugExtra || undefined
        };
    }

    const t = jsonCTE.lsTotales;
    const parseMonto = (val) => parseFloat(String(val ?? '0').replace(',', '.')) || 0;
    const parseCant = (val) => parseInt(String(val ?? '0'), 10) || 0;

    const categoriasDeuda = [
        { clave: 'PENDIENTES', nombre: 'Pendientes' },
        { clave: 'COACTIVAS',  nombre: 'En Coactiva' },
        { clave: 'CONVENIOS',  nombre: 'En Convenio' },
        { clave: 'IMPUGNADAS', nombre: 'En Impugnación' }
    ];

    let cantidadActiva = 0;
    let totalDeuda = 0;
    const resumenCategorias = [];

    for (const cat of categoriasDeuda) {
        const nodo = t[cat.clave];
        if (!nodo) continue;

        const cant = parseCant(nodo.cantidad);
        const monto = parseMonto(nodo.saldo ?? nodo.total ?? nodo.valor);

        if (cant > 0 || monto > 0) {
            cantidadActiva += cant;
            totalDeuda += monto;
            resumenCategorias.push({
                categoria: cat.clave,
                etiqueta: nodo.etiqueta || cat.nombre,
                cantidad: cant,
                valor_base: parseMonto(nodo.valor),
                interes: parseMonto(nodo.interes),
                total: Number(monto.toFixed(2))
            });
        }
    }

    const detalleCitaciones = [];
    for (const key of Object.keys(jsonCTE)) {
        if (key !== 'lsTotales' && key !== 'lsInfoPlaca' && jsonCTE[key] && typeof jsonCTE[key] === 'object') {
            for (const subKey of Object.keys(jsonCTE[key])) {
                if (Array.isArray(jsonCTE[key][subKey]) && jsonCTE[key][subKey].length > 0) {
                    detalleCitaciones.push(...jsonCTE[key][subKey]);
                }
            }
        }
    }

    return {
        ok: true,
        fuente: 'CTE',
        placa: placaBuscada,
        servicio: jsonCTE.lsInfoPlaca?.SERVICIO_COD?.valor || null,
        tiene_multas: cantidadActiva > 0 || totalDeuda > 0,
        cantidad: cantidadActiva,
        total: Number(totalDeuda.toFixed(2)),
        historico_pagadas: parseCant(t.PAGADAS?.cantidad),
        historico_anuladas: parseCant(t.ANULADAS?.cantidad),
        resumen_categorias: resumenCategorias,
        listado: detalleCitaciones.length > 0 ? detalleCitaciones : resumenCategorias
    };
}

async function cerrarSesionTomcatLimpia(page, sesionIniciada) {
    if (!sesionIniciada || page.isClosed()) return;

    try {
        // Cierra explícitamente tanto el contexto del iframe como el portal contenedor para no dejar sesiones huérfanas
        await page.goto('https://servicios.axiscloud.ec/AutoServicioConsultaInfracciones/salir.jsp', {
            waitUntil: 'domcontentloaded',
            timeout: 4000
        }).catch(() => {});

        await page.goto('https://servicios.axiscloud.ec/AutoServicio/salir.jsp', {
            waitUntil: 'domcontentloaded',
            timeout: 5000
        }).catch(() => {});

        const client = await page.target().createCDPSession().catch(() => null);
        if (client) {
            await client.send('Network.clearBrowserCookies').catch(() => {});
            await client.detach().catch(() => {});
        }
    } catch (e) {}
}

async function consultarMultasCTE(browser, placaBuscada) {
    // Elimina el antiguo archivo de cookies incompletas que provocaba fugas de sesión entre /AutoServicio y /AutoServicioConsultaInfracciones
    limpiarCookiesLegacy();

    const segundosRestantes = verificarCooldownActivo();
    if (segundosRestantes !== null) {
        return {
            ok: false,
            fuente: 'CTE',
            placa: placaBuscada,
            tiene_multas: false,
            cantidad: 0,
            total: 0,
            listado: [],
            requiere_revision_manual: true,
            error: `El portal CTE alcanzó temporalmente su límite de sesiones activas. Espere ~${segundosRestantes}s para que expiren las sesiones previas o verifique manualmente.`
        };
    }

    const lockObtenido = await adquirirLockLocal();
    const page = await browser.newPage();
    const urlsPostVistas = [];
    let sesionIniciada = false;

    try {
        await page.setExtraHTTPHeaders({
            'Accept-Language': 'es-EC,es;q=0.9,en;q=0.8'
        });
        await page.setViewport({ width: 1366, height: 768 });

        let resuelto = false;
        let resolvePromesa;
        let timerTimeout;
        const promesaJson = new Promise((resolve) => {
            resolvePromesa = resolve;
            timerTimeout = setTimeout(() => {
                if (!resuelto) {
                    resuelto = true;
                    resolve({ error_script: 'Tiempo de espera agotado en CTE AxisCloud (verificación manual requerida)' });
                }
            }, 25000);
        });

        page.on('response', async (res) => {
            if (res.request().method() === 'POST') {
                urlsPostVistas.push(`${res.status()}:${res.url()}`);
            }
            if (res.url().includes('download_construction.jsp') && res.request().method() === 'POST') {
                try {
                    const txt = await res.text();
                    const trimmed = txt ? txt.trim() : '';
                    if (trimmed && !resuelto && (trimmed.includes('lsTotales') || trimmed.includes('lsError'))) {
                        resuelto = true;
                        clearTimeout(timerTimeout);
                        resolvePromesa(JSON.parse(trimmed));
                    }
                } catch (e) {}
            }
        });

        await page.goto('https://servicios.axiscloud.ec/AutoServicio/inicio.jsp?ps_empresa=16&ps_accion=P55', {
            waitUntil: 'networkidle2',
            timeout: 30000
        });
        sesionIniciada = true;

        let frameConsulta = page.frames().find(f => f.url().includes('ConsultaInfracciones.jsp'));
        if (!frameConsulta) {
            for (let i = 0; i < 15; i++) {
                await esperar(300);
                frameConsulta = page.frames().find(f => f.url().includes('ConsultaInfracciones.jsp'));
                if (frameConsulta) break;
            }
        }

        if (!frameConsulta) {
            return procesarRespuestaCTE({ error_script: 'No se encontró el formulario ConsultaInfracciones.jsp' }, placaBuscada, {
                frames: page.frames().map(f => f.url())
            });
        }

        await frameConsulta.waitForSelector('#filtro_busqueda', { timeout: 10000 });
        await frameConsulta.waitForSelector('#valor_busqueda', { timeout: 10000 });
        await frameConsulta.waitForSelector('#boton_buscar', { timeout: 10000 });

        await frameConsulta.select('#filtro_busqueda', 'PLA');
        await esperar(250);
        await frameConsulta.click('#valor_busqueda', { clickCount: 3 });
        await frameConsulta.type('#valor_busqueda', placaBuscada, { delay: 30 });

        const valorActual = await frameConsulta.$eval('#valor_busqueda', el => el.value).catch(() => '');
        if (valorActual.trim().toUpperCase() !== placaBuscada) {
            await frameConsulta.$eval('#valor_busqueda', (el, val) => {
                el.value = val;
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }, placaBuscada);
        }

        await esperar(300);
        await frameConsulta.click('#boton_buscar');

        const rawJson = await promesaJson;
        const resultado = procesarRespuestaCTE(rawJson, placaBuscada, { posts: urlsPostVistas });

        if (!resultado.ok && /sesiones permitidas/i.test(String(resultado.error || ''))) {
            registrarCooldownLimiteSesiones();
        }

        return resultado;
    } catch (e) {
        return {
            ok: false,
            fuente: 'CTE',
            placa: placaBuscada,
            tiene_multas: false,
            cantidad: 0,
            total: 0,
            listado: [],
            requiere_revision_manual: true,
            error: e.message
        };
    } finally {
        // Cierra siempre la sesión activa (tanto si la consulta tuvo éxito como si falló) antes de cerrar la pestaña
        await cerrarSesionTomcatLimpia(page, sesionIniciada);
        await page.close().catch(() => {});
        if (lockObtenido) {
            liberarLockLocal();
        }
    }
}

if (require.main === module) {
    (async () => {
        let browser;
        try {
            const opciones = {
                headless: 'new',
                args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu']
            };
            const exe = obtenerRutaNavegadorWindows();
            if (exe) opciones.executablePath = exe;

            browser = await puppeteer.launch(opciones);
            const resultado = await consultarMultasCTE(browser, placa);
            const salida = JSON.stringify(resultado, null, 2);

            if (archivoSalida) {
                fs.writeFileSync(archivoSalida, salida, 'utf8');
            } else {
                process.stdout.write(salida + '\n');
            }
        } finally {
            if (browser) await browser.close().catch(() => {});
        }
    })();
}

module.exports = { consultarMultasCTE };
