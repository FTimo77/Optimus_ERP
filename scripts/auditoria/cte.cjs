const puppeteer = require('puppeteer');
const fs = require('fs');

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

async function ejecutarConsultaCTEUnIntento(browser, placaBuscada) {
    const context = typeof browser.createBrowserContext === 'function'
        ? await browser.createBrowserContext()
        : null;
    const page = context ? await context.newPage() : await browser.newPage();
    const urlsPostVistas = [];

    try {
        await page.evaluateOnNewDocument(() => {
            Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
        });
        await page.setExtraHTTPHeaders({
            'Accept-Language': 'es-EC,es;q=0.9,en;q=0.8'
        });
        const uaNativo = (await browser.userAgent()).replace(/HeadlessChrome/g, 'Chrome');
        await page.setUserAgent(uaNativo);
        await page.setViewport({ width: 1366, height: 768 });

        let resuelto = false;
        let resolvePromesa;
        const promesaJson = new Promise((resolve) => {
            resolvePromesa = resolve;
            setTimeout(() => {
                if (!resuelto) {
                    resuelto = true;
                    resolve({ error_script: 'Timeout CTE AxisCloud' });
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
                        resolvePromesa(JSON.parse(trimmed));
                    }
                } catch (e) {}
            }
        });

        // 1. Entramos por inicio.jsp (que sí renderiza el formulario y autoriza el iframe) con sesión 100% limpia
        await page.goto('https://servicios.axiscloud.ec/AutoServicio/inicio.jsp?ps_empresa=16&ps_accion=P55', {
            waitUntil: 'networkidle2',
            timeout: 30000
        });

        // 2. Localizamos el iframe ConsultaInfracciones.jsp
        let frameConsulta = page.frames().find(f => f.url().includes('ConsultaInfracciones.jsp'));
        if (!frameConsulta) {
            for (let i = 0; i < 15; i++) {
                await new Promise(r => setTimeout(r, 300));
                frameConsulta = page.frames().find(f => f.url().includes('ConsultaInfracciones.jsp'));
                if (frameConsulta) break;
            }
        }

        if (!frameConsulta) {
            return procesarRespuestaCTE({ error_script: 'No se encontró el iframe ConsultaInfracciones.jsp' }, placaBuscada, {
                frames: page.frames().map(f => f.url())
            });
        }

        await frameConsulta.waitForSelector('#filtro_busqueda', { timeout: 10000 });
        await frameConsulta.waitForSelector('#valor_busqueda', { timeout: 10000 });
        await frameConsulta.waitForSelector('#boton_buscar', { timeout: 10000 });

        await frameConsulta.select('#filtro_busqueda', 'PLA');
        await new Promise(r => setTimeout(r, 400));
        await frameConsulta.click('#valor_busqueda', { clickCount: 3 });
        await frameConsulta.type('#valor_busqueda', placaBuscada, { delay: 35 });

        const valorActual = await frameConsulta.$eval('#valor_busqueda', el => el.value).catch(() => '');
        if (valorActual.trim().toUpperCase() !== placaBuscada) {
            await frameConsulta.$eval('#valor_busqueda', (el, val) => {
                el.value = val;
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }, placaBuscada);
        }

        await new Promise(r => setTimeout(r, 200));
        await frameConsulta.click('#boton_buscar');

        const rawJson = await promesaJson;
        return procesarRespuestaCTE(rawJson, placaBuscada, { posts: urlsPostVistas });
    } catch (e) {
        return {
            ok: false,
            fuente: 'CTE',
            placa: placaBuscada,
            tiene_multas: false,
            cantidad: 0,
            total: 0,
            listado: [],
            error: e.message
        };
    } finally {
        await page.close().catch(() => {});
        if (context) await context.close().catch(() => {});
    }
}

async function consultarMultasCTE(browser, placaBuscada) {
    let resultado = await ejecutarConsultaCTEUnIntento(browser, placaBuscada);

    if (!resultado.ok && /ERR_NAME_NOT_RESOLVED|ERR_NETWORK_CHANGED|ERR_CONNECTION_RESET/i.test(String(resultado.error || ''))) {
        await new Promise(r => setTimeout(r, 2000));
        resultado = await ejecutarConsultaCTEUnIntento(browser, placaBuscada);
    }

    return resultado;
}

if (require.main === module) {
    (async () => {
        let browser;
        try {
            const opciones = {
                headless: 'new',
                args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--disable-blink-features=AutomationControlled']
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