const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

process.env.PUPPETEER_CACHE_DIR = process.env.PUPPETEER_CACHE_DIR || 'C:\\Users\\LENOVO\\.cache\\puppeteer';

const arg2 = (process.argv[2] || '').trim();
const arg3 = (process.argv[3] || '').trim();
const arg4 = (process.argv[4] || '').trim();

const placaRaw = arg2.toUpperCase().replace(/[^A-Z0-9]/g, '');

// Si el 3er argumento son 10 números seguidos (y no termina en .json), es la CÉDULA y no un archivo de salida
let archivoSalida = '';
let cedula = '';

if (/^\d{10}$/.test(arg3.replace(/[^0-9]/g, '')) && !arg3.endsWith('.json')) {
    cedula = arg3.replace(/[^0-9]/g, '');
    archivoSalida = '';
} else {
    archivoSalida = arg3;
    cedula = arg4.replace(/[^0-9]/g, '');
}

if (!placaRaw) {
    console.log(JSON.stringify({ status: 'error', message: 'Placa vacía' }));
    process.exit(1);
}

const placa = /^([A-Z]{3})(\d{3})$/.test(placaRaw)
    ? placaRaw.replace(/^([A-Z]{3})(\d{3})$/, '$10$2')
    : placaRaw;

const rutaTokenCache = path.join(__dirname, 'storage', 'app', 'autority_token.txt');
const TOKEN_INICIAL = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpZCI6NjYwNDU4LCJ1c2VyTmFtZSI6IkFub255bW91czIzODUiLCJpYXQiOjE3OTQ2MjA0MTksImV4cCI6MTc5NDYyMjIxOX0.67YrS24vHBSYvodD3nAsuaJoqtHO_-6yshdY3uaWNFI';

// 1. PROPIETARIO (Privynote)
async function consultarPropietario(placaBuscada) {
    try {
        const res = await fetch('https://app3902.privynote.net/api/v1/transit/vehicle-owner', {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/plain, */*',
                'Content-Type': 'application/json',
                'Origin': 'https://consultasecuador.com',
                'Referer': 'https://consultasecuador.com/',
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            },
            body: JSON.stringify({ placa: placaBuscada }),
            signal: AbortSignal.timeout(12000)
        });
        const json = await res.json();
        return { ok: true, nombre: json?.data?.name || json?.name || '' };
    } catch (e) {
        return { ok: false, nombre: '', error: e.message };
    }
}

// 2. MULTAS (Autority)
async function pedirMultasConToken(placaBuscada, token) {
    if (!token) return null;
    try {
        const url = `https://api.autority.io/traffic-ticket/anonymous/PLA/${placaBuscada}`;
        const limpio = token.replace(/^Bearer\s+/i, '').trim();
        for (const authVal of [`Bearer ${limpio}`, limpio]) {
            const res = await fetch(url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json, text/plain, */*',
                    'Authorization': authVal,
                    'Origin': 'https://multas.autority.io',
                    'Referer': `https://multas.autority.io/fines?type=PLA&value=${placaBuscada}&entity=`,
                    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
                },
                signal: AbortSignal.timeout(10000)
            });
            const txt = await res.text();
            if (res.ok && txt.includes('"status":"success"')) {
                return JSON.parse(txt);
            }
        }
    } catch (e) {}
    return null;
}

async function consultarMultas(browser, placaBuscada) {
    try {
        let token = '';
        if (fs.existsSync(rutaTokenCache)) {
            token = fs.readFileSync(rutaTokenCache, 'utf8').trim();
        }

        // 1. Intento ultrarrápido si el token en caché sigue vigente
        let dataMultas = await pedirMultasConToken(placaBuscada, token);
        if (dataMultas) return dataMultas;

        // 2. Si caducó, abrimos pestaña con viewport real e interceptor de fetch/XHR
        const page = await browser.newPage();
        let nuevoToken = null;
        let jsonVivo = null;

        try {
            await page.setViewport({ width: 1366, height: 768 });
            await page.evaluateOnNewDocument(() => {
                Object.defineProperty(navigator, 'webdriver', { get: () => false });
                window.__capturaAutority = { token: null, json: null };

                const origFetch = window.fetch;
                window.fetch = async function (...args) {
                    try {
                        const reqUrl = typeof args[0] === 'string' ? args[0] : (args[0]?.url || '');
                        const headers = (args[1] && args[1].headers) || {};
                        const auth = headers['Authorization'] || headers['authorization'] || (headers.get && headers.get('Authorization'));
                        if (auth && String(auth).includes('eyJ')) {
                            window.__capturaAutority.token = String(auth).replace(/^Bearer\s+/i, '').trim();
                        }
                        const response = await origFetch.apply(this, args);
                        response.clone().text().then(txt => {
                            const m = txt.match(/eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/);
                            if (m && !window.__capturaAutority.token) window.__capturaAutority.token = m[0];
                            if (reqUrl.includes('traffic-ticket') && txt.includes('"status":"success"')) {
                                window.__capturaAutority.json = txt.trim();
                            }
                        }).catch(() => {});
                        return response;
                    } catch (e) {
                        return origFetch.apply(this, args);
                    }
                };
            });

            page.on('request', req => {
                const auth = req.headers()['authorization'];
                if (auth && auth.includes('eyJ')) {
                    nuevoToken = auth.replace(/^Bearer\s+/i, '').trim();
                }
            });

            page.on('response', async res => {
                const url = res.url();
                if (url.includes('autority.io') && res.request().method() !== 'OPTIONS') {
                    try {
                        const txt = await res.text();
                        const m = txt.match(/eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/);
                        if (m && !nuevoToken) nuevoToken = m[0];
                        if (url.includes('traffic-ticket') && txt.includes('"status":"success"')) {
                            jsonVivo = JSON.parse(txt.trim());
                        }
                    } catch (e) {}
                }
            });

            await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36');

            await page.goto(`https://multas.autority.io/fines?type=PLA&value=${placaBuscada}&entity=`, {
                waitUntil: 'networkidle2',
                timeout: 25000
            });

            for (let i = 0; i < 16; i++) {
                const interno = await page.evaluate(() => window.__capturaAutority || {}).catch(() => ({}));
                if (interno.token && !nuevoToken) nuevoToken = interno.token;
                if (interno.json && !jsonVivo) {
                    try { jsonVivo = JSON.parse(interno.json); } catch (e) {}
                }
                if (jsonVivo || nuevoToken) break;
                await new Promise(r => setTimeout(r, 500));
            }

            if (nuevoToken) {
                fs.mkdirSync(path.dirname(rutaTokenCache), { recursive: true });
                fs.writeFileSync(rutaTokenCache, nuevoToken, 'utf8');
            }

            if (jsonVivo) return jsonVivo;
            if (nuevoToken) {
                const retry = await pedirMultasConToken(placaBuscada, nuevoToken);
                if (retry) return retry;
            }
        } finally {
            await page.close().catch(() => {});
        }

        return { status: 'error', message: 'Autority no emitió token o no respondió', data: [] };
    } catch (e) {
        return { status: 'error', message: e.message, data: [] };
    }
}

//MATRICULA Y CANTIDAD DE TRANSFERENCIAS (SRI)
async function consultarSRI(browser, placaBuscada) {
    const page = await browser.newPage();
    try {
        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        await page.goto('https://srienlinea.sri.gob.ec/sri-en-linea/SriVehiculosWeb/ConsultaValoresPagarVehiculos/Consultas/consultaRubros', {
            waitUntil: 'domcontentloaded',
            timeout: 25000
        });
        return await page.evaluate(async (p) => {
            const base = 'https://srienlinea.sri.gob.ec/sri-matriculacion-vehicular-recaudacion-servicio-internet/rest';
            
            const rVeh = await fetch(`${base}/BaseVehiculo/obtenerPorNumeroPlacaOPorNumeroCampvOPorNumeroCpn?numeroPlacaCampvCpn=${p}`, { headers: { 'Accept': 'application/json' } });
            const vehiculo = await rVeh.json();
            const cod = vehiculo?.codigoVehiculo || vehiculo?.vehiculo?.codigoVehiculo || null;
            
            let rubros = [];
            if (cod) {
                const rRub = await fetch(`${base}/ConsultaRubros/obtenerPorCodigoVehiculo?codigoVehiculo=${cod}`, { headers: { 'Accept': 'application/json' } });
                const txt = await rRub.text();
                try { rubros = txt ? JSON.parse(txt) : []; } catch (e) {}
            }

            let pagos = [];
            try {
                const rPagos = await fetch(`${base}/consultaPagos/obtenerPorPlacaCampvCpn?placaCampvCpn=${p}`, { headers: { 'Accept': 'application/json' } });
                const txtPagos = await rPagos.text();
                const jsonPagos = txtPagos ? JSON.parse(txtPagos) : {};
                pagos = jsonPagos.data || [];
            } catch (e) {}

            const transferencias = pagos.filter(item => 
                (item.tipoDeuda || '').toUpperCase().includes('TRANSFERENCIA DE DOMINIO')
            );

            return { 
                ok: true, 
                vehiculo, 
                rubros, 
                total_transferencias: transferencias.length
            };
        }, placaBuscada);
    } catch (e) {
        return { ok: false, vehiculo: null, rubros: [], total_transferencias: 0 };
    } finally {
        await page.close().catch(() => {});
    }
}

// 4. FICHA TÉCNICA (AxisCloud)
async function consultarAxis(browser, placaBuscada) {
    const page = await browser.newPage();
    try {
        const promesa = new Promise((resolve) => {
            const timer = setTimeout(() => resolve({ error_script: 'Timeout AxisCloud' }), 25000);
            page.on('response', async (res) => {
                if (res.url().includes('datosVehiculo.jsp') && res.request().method() === 'POST') {
                    try {
                        const txt = await res.text();
                        if (txt && txt.includes('lsDatosIdentificacion')) {
                            clearTimeout(timer);
                            resolve(JSON.parse(txt.trim()));
                        }
                    } catch (e) {}
                }
            });
        });
        await page.goto('https://servicios.axiscloud.ec/CRV/?ps_empresa=05', { waitUntil: 'networkidle2', timeout: 25000 });
        const input = await page.$('input[type="text"]');
        if (input) {
            await input.click({ clickCount: 3 });
            await input.type(placaBuscada, { delay: 30 });
            await page.evaluate(() => {
                const btn = Array.from(document.querySelectorAll('button, input[type="submit"], a')).find(b => /buscar/i.test(b.innerText || b.value));
                if (btn) btn.click();
            });
        }
        return await promesa;
    } catch (e) {
        return { error_script: e.message };
    } finally {
        await page.close().catch(() => {});
    }
}

// =========================================================================
// 5. DENUNCIAS (Fiscalía FGE - SIAF)
// =========================================================================
async function consultarFgeBase(browser, valorBuscado) {
    if (!valorBuscado) return { ok: false, items: [] };
    const page = await browser.newPage();
    try {
        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36');
        let itemsCapturados = [];
        let respondioSiaf = false;

        const extraerCabeceraDeTexto = (txt) => {
            if (!txt) return null;
            const inicio = txt.indexOf('{');
            const fin = txt.lastIndexOf('}');
            if (inicio !== -1 && fin !== -1) {
                try {
                    const json = JSON.parse(txt.slice(inicio, fin + 1));
                    if (Array.isArray(json.cabecera)) {
                        return json.cabecera;
                    }
                } catch (e) {}
            }
            return null;
        };

        page.on('response', async (res) => {
            const url = res.url();
            if (
                url.includes('AJX_consulta_noticiasdelito.php') ||
                url.includes('consulta_ndd') ||
                url.includes('noticiasdelito')
            ) {
                try {
                    const txt = await res.text();
                    const cab = extraerCabeceraDeTexto(txt);
                    if (cab) {
                        itemsCapturados = cab;
                        respondioSiaf = true;
                    }
                } catch (e) {}
            }
        });

        await page.goto('https://www.gestiondefiscalias.gob.ec/siaf/sitio/consulta_ndd_ext/', {
            waitUntil: 'networkidle2',
            timeout: 25000
        });

        // 1. Escribimos con teclado real de Puppeteer en el input visible y damos Enter / Clic real
        const inputHandle = await page.$('input[type="text"], input#pwd, input:not([type="hidden"])');
        if (inputHandle) {
            await inputHandle.click({ clickCount: 3 });
            await page.keyboard.press('Backspace');
            await inputHandle.type(String(valorBuscado), { delay: 40 });
            await page.keyboard.press('Enter');
        }

        // Hacemos clic en el botón del formulario o llamamos a la función JS nativa del SIAF
        await page.evaluate((val) => {
            const inp = document.querySelector('input[type="text"], input#pwd, input:not([type="hidden"])');
            if (inp) inp.value = val;

            // Si la página tiene funciones globales declaradas para consultar, las invocamos
            for (const fnName of ['consultar', 'buscar', 'consultar_ndd', 'buscar_ndd', 'enviar']) {
                if (typeof window[fnName] === 'function') {
                    try { window[fnName](); } catch (e) {}
                }
            }

            const btn = Array.from(document.querySelectorAll('button, input[type="button"], input[type="submit"], a, span.btn'))
                .find(b => /consultar|buscar|enviar/i.test(b.innerText || b.value || b.id || ''));
            if (btn) btn.click();
        }, valorBuscado);

        // Esperamos hasta 5 segundos la respuesta por el flujo normal
        for (let i = 0; i < 10; i++) {
            if (respondioSiaf) break;
            await new Promise(r => setTimeout(r, 500));
        }

        // 2. Plan B directo: Si el clic del DOM no disparó el AJAX, llamamos directo a AJX_consulta_noticiasdelito.php desde adentro de la página
        if (!respondioSiaf) {
            const textoDirecto = await page.evaluate(async (val) => {
                const posiblesRutas = [
                    `AJX_consulta_noticiasdelito.php?pwd=${encodeURIComponent(val)}`,
                    `AJX_consulta_noticiasdelito.php?dato=${encodeURIComponent(val)}`,
                    `AJX_consulta_noticiasdelito.php`
                ];
                for (const r of posiblesRutas) {
                    try {
                        const fd = new URLSearchParams();
                        fd.append('pwd', val);
                        fd.append('dato', val);
                        fd.append('cedula', val);
                        fd.append('placa', val);

                        const res = r.includes('?')
                            ? await fetch(r, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                            : await fetch(r, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                                    'X-Requested-With': 'XMLHttpRequest'
                                },
                                body: fd.toString()
                            });
                        const t = await res.text();
                        if (t && t.includes('"cabecera"')) return t;
                    } catch (e) {}
                }
                return null;
            }, valorBuscado);

            const cab = extraerCabeceraDeTexto(textoDirecto);
            if (cab) {
                itemsCapturados = cab;
            }
        }

        return { ok: true, items: itemsCapturados };
    } catch (e) {
        return { ok: false, items: [] };
    } finally {
        await page.close().catch(() => {});
    }
}

// 5A. Denuncias por PLACA
async function consultarFiscalia(browser, placaBuscada) {
    const res = await consultarFgeBase(browser, placaBuscada);
    if (!res.ok) {
        return { ok: false, tiene_denuncias: false, cantidad: 0, listado: [] };
    }

    const listado = [];
    for (const item of res.items) {
        if (!item || typeof item !== 'object') continue;
        const vehs = Array.isArray(item.vehiculos) ? item.vehiculos : [];
        const coincidePlaca = vehs.length === 0 || vehs.some(v => String(v.placa ?? v['2'] ?? '').toUpperCase().includes(placaBuscada));

        if (coincidePlaca) {
            listado.push({
                ndd: String(item.ndd ?? item.ndd1 ?? item['0'] ?? 'S/N').trim(),
                fecha: String(item.fecha ?? item['2'] ?? '').trim(),
                hora: String(item.hora ?? item['3'] ?? '').trim(),
                provincia: String(item.pro_descripcion ?? item['8'] ?? '').trim(),
                canton: String(item.ciudad ?? item['9'] ?? '').trim(),
                delito: String(item.gen_delito_tipopenal ?? item['11'] ?? 'DELITO EN INVESTIGACIÓN').trim(),
                tipo_incidente: String(item.tipoincidente ?? item['7'] ?? '').trim(),
                unidad: String(item.unidad ?? item['12'] ?? '').trim()
            });
        }
    }

    return {
        ok: true,
        tiene_denuncias: listado.length > 0,
        cantidad: listado.length,
        listado
    };
}

// 5B. Denuncias por CÉDULA (Solo alerta si es SOSPECHOSO o DENUNCIADO)
async function consultarFiscaliaCedula(browser, cedulaBuscada) {
    const cedula10 = String(cedulaBuscada || '').replace(/\D/g, '');
    if (cedula10.length !== 10) {
        return {
            ok: false,
            consultado: false,
            cedula: cedulaBuscada || '',
            motivo: 'cedula_invalida',
            tiene_denuncias: false,
            cantidad: 0,
            total_procesos_vista: 0,
            procesos_otros_roles: 0,
            listado: []
        };
    }

    const res = await consultarFgeBase(browser, cedula10);
    if (!res.ok) {
        return {
            ok: false,
            consultado: true,
            cedula: cedula10,
            motivo: 'fiscalia_no_disponible',
            tiene_denuncias: false,
            cantidad: 0,
            total_procesos_vista: 0,
            procesos_otros_roles: 0,
            listado: []
        };
    }

    const denunciasComoSospechoso = [];
    let procesosOtrosRoles = 0;

    for (const item of res.items) {
        if (!item || typeof item !== 'object') continue;

        const sujetos = Array.isArray(item.sujetos) ? item.sujetos : [];
        const sujetosCoincidentes = sujetos.filter(s => {
            if (!s || typeof s !== 'object') return false;
            const cedSujeto = String(s.cedula ?? s['0'] ?? '').replace(/\D/g, '');
            return cedSujeto === cedula10;
        });

        if (sujetosCoincidentes.length > 0) {
            const sujetoSospechoso = sujetosCoincidentes.find(s => {
                const rol = String(s.tipo ?? s['2'] ?? '').toUpperCase().trim();
                return rol.includes('SOSPECHOSO') || rol.includes('DENUNCIADO') || rol.includes('PROCESADO') || rol.includes('IMPUTADO');
            });

            if (sujetoSospechoso) {
                const rolDetectado = String(sujetoSospechoso.tipo ?? sujetoSospechoso['2'] ?? 'SOSPECHOSO').toUpperCase().trim();
                const nombreDetectado = String(sujetoSospechoso.persona ?? sujetoSospechoso['1'] ?? '').trim();

                denunciasComoSospechoso.push({
                    ndd: String(item.ndd ?? item.ndd1 ?? item['0'] ?? 'S/N').trim(),
                    fecha: String(item.fecha ?? item['2'] ?? '').trim(),
                    hora: String(item.hora ?? item['3'] ?? '').trim(),
                    provincia: String(item.pro_descripcion ?? item['8'] ?? '').trim(),
                    canton: String(item.ciudad ?? item['9'] ?? '').trim(),
                    delito: String(item.gen_delito_tipopenal ?? item['11'] ?? 'DELITO EN INVESTIGACIÓN').trim(),
                    rol: rolDetectado,
                    nombre_sujeto: nombreDetectado,
                    unidad: String(item.unidad ?? item['12'] ?? '').trim()
                });
            } else {
                procesosOtrosRoles++;
            }
        }
    }

    return {
        ok: true,
        consultado: true,
        cedula: cedula10,
        tiene_denuncias: denunciasComoSospechoso.length > 0,
        cantidad: denunciasComoSospechoso.length,
        total_procesos_vista: denunciasComoSospechoso.length + procesosOtrosRoles,
        procesos_otros_roles: procesosOtrosRoles,
        listado: denunciasComoSospechoso
    };
}

// Función auxiliar para detectar el navegador instalado en Windows (y no afectar en Linux/deploy)
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

// EJECUCIÓN PARALELA DE LAS 5 FUENTES
(async () => {
    let browser;
    try {
        const opcionesLaunch = {
            headless: 'new',
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-gpu',
                '--disable-blink-features=AutomationControlled'
            ]
        };

        // Si estamos en Windows y existe Chrome/Edge, lo usamos directamente
        const exeWin = obtenerRutaNavegadorWindows();
        if (exeWin) {
            opcionesLaunch.executablePath = exeWin;
        }

        browser = await puppeteer.launch(opcionesLaunch);

        const [propietario, multas, sri, axis, fiscalia, fiscalia_cedula] = await Promise.all([
            consultarPropietario(placa),
            consultarMultas(browser, placa),
            consultarSRI(browser, placa),
            consultarAxis(browser, placa),
            consultarFiscalia(browser, placa),
            consultarFiscaliaCedula(browser, cedula)
        ]);

        const paquete = JSON.stringify({
            status: 'success',
            placa,
            cedula,
            propietario,
            multas,
            sri,
            axis,
            fiscalia,
            fiscalia_cedula
        }, null, 2);

        if (archivoSalida) fs.writeFileSync(archivoSalida, paquete, 'utf8');
        else console.log(paquete);

    } catch (error) {
        const err = JSON.stringify({ status: 'error', message: error.message });
        if (archivoSalida) fs.writeFileSync(archivoSalida, err, 'utf8');
        else console.log(err);
    } finally {
        if (browser) {
            try {
                const pages = await browser.pages();
                await Promise.all(pages.map(p => p.close().catch(() => {})));
                await browser.close().catch(() => {});
            } catch (e) {}
        }
        process.exit(0);
    }
})();