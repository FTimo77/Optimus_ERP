const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

process.env.PUPPETEER_CACHE_DIR = process.env.PUPPETEER_CACHE_DIR || 'C:\\Users\\LENOVO\\.cache\\puppeteer';

const placaRaw = (process.argv[2] || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
const archivoSalida = process.argv[3];

if (!placaRaw) {
    console.log(JSON.stringify({ status: 'error', message: 'Debes escribir una placa. Ejemplo: node auditoria_completa.cjs PFI0582' }));
    process.exit(1);
}

// Formato de 7 caracteres para SRI/Axis (ej: PBA845 -> PBA0845)
const placa = /^([A-Z]{3})(\d{3})$/.test(placaRaw)
    ? placaRaw.replace(/^([A-Z]{3})(\d{3})$/, '$10$2')
    : placaRaw;

const rutaTokenCache = path.join(__dirname, 'storage', 'app', 'autority_token.txt');
const TOKEN_INICIAL = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpZCI6NjYwNDU4LCJ1c2VyTmFtZSI6IkFub255bW91czIzODUiLCJpYXQiOjE3OTQ2MjA0MTksImV4cCI6MTc5NDYyMjIxOX0.67YrS24vHBSYvodD3nAsuaJoqtHO_-6yshdY3uaWNFI';

// =========================================================================
// 1. CONSULTA DE PROPIETARIO (API Directa - 0.3s)
// =========================================================================
async function consultarPropietario(placaBuscada) {
    try {
        const res = await fetch('https://app3902.privynote.net/api/v1/transit/vehicle-owner', {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/plain, */*',
                'Content-Type': 'application/json',
                'Origin': 'https://consultasecuador.com',
                'Referer': 'https://consultasecuador.com/',
                'X-Requested-With': 'XMLHttpRequest',
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
            },
            body: JSON.stringify({ placa: placaBuscada }),
            signal: AbortSignal.timeout(12000)
        });
        const json = await res.json();
        return {
            ok: true,
            nombre: json?.data?.name || json?.name || ''
        };
    } catch (e) {
        return { ok: false, nombre: '', error: e.message };
    }
}

// =========================================================================
// 2. CONSULTA DE MULTAS AUTORITY (API Directa con Token + Renovación)
// =========================================================================
async function pedirMultasConToken(placaBuscada, token) {
    try {
        const url = `https://api.autority.io/traffic-ticket/anonymous/PLA/${placaBuscada}`;
        const limpio = token.replace(/^Bearer\s+/i, '').trim();
        const headersBase = {
            'Accept': 'application/json, text/plain, */*',
            'Origin': 'https://multas.autority.io',
            'Referer': 'https://multas.autority.io/',
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        };

        for (const authVal of [`Bearer ${limpio}`, limpio]) {
            const res = await fetch(url, {
                method: 'GET',
                headers: { ...headersBase, 'Authorization': authVal },
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
        let token = TOKEN_INICIAL;
        if (fs.existsSync(rutaTokenCache)) {
            const guardado = fs.readFileSync(rutaTokenCache, 'utf8').trim();
            if (guardado) token = guardado;
        }

        // Intento 1: Vía rápida con el token en caché
        let dataMultas = await pedirMultasConToken(placaBuscada, token);
        if (dataMultas) return dataMultas;

        // Intento 2: Si el token caducó, usamos Puppeteer para renovarlo y capturar el JSON
        const page = await browser.newPage();
        let nuevoToken = null;
        let jsonCapturadoEnVivo = null;

        try {
            page.on('request', (req) => {
                const auth = req.headers()['authorization'];
                if (auth && auth.includes('eyJ')) {
                    nuevoToken = auth.replace(/^Bearer\s+/i, '').trim();
                }
            });

            page.on('response', async (res) => {
                const url = res.url();
                if (url.includes('autority.io') && res.request().method() !== 'OPTIONS') {
                    try {
                        const txt = await res.text();
                        const matchJwt = txt.match(/eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/);
                        if (matchJwt && !nuevoToken) {
                            nuevoToken = matchJwt[0];
                        }
                        if (url.includes('traffic-ticket') && txt.includes('"status":"success"')) {
                            jsonCapturadoEnVivo = JSON.parse(txt);
                        }
                    } catch (e) {}
                }
            });

            await page.goto('https://multas.autority.io/', { waitUntil: 'networkidle2', timeout: 25000 });
            await new Promise(r => setTimeout(r, 800));

            // Seleccionar "Placa" en el desplegable
            await page.evaluate(() => {
                const selects = Array.from(document.querySelectorAll('select'));
                for (const s of selects) {
                    const opt = Array.from(s.options).find(o => /placa|pla/i.test(o.text) || /placa|pla/i.test(o.value));
                    if (opt) {
                        s.value = opt.value;
                        s.dispatchEvent(new Event('change', { bubbles: true }));
                        return;
                    }
                }
                const elementos = Array.from(document.querySelectorAll('button, [role="combobox"], .select, div, span'));
                const desplegable = elementos.find(el => {
                    const t = (el.innerText || '').trim().toLowerCase();
                    return (t === 'cédula' || t === 'cedula') && el.children.length <= 2;
                });
                if (desplegable) desplegable.click();
            });

            await new Promise(r => setTimeout(r, 400));

            await page.evaluate(() => {
                const opciones = Array.from(document.querySelectorAll('li, [role="option"], div, span, button, a'));
                const opcionPlaca = opciones.find(el => (el.innerText || '').trim().toLowerCase() === 'placa');
                if (opcionPlaca) opcionPlaca.click();
            });

            await new Promise(r => setTimeout(r, 400));

            const input = await page.$('input[type="text"], input[type="search"], input:not([type="hidden"])');
            if (input) {
                await input.click({ clickCount: 3 });
                await page.keyboard.press('Backspace');
                await input.type(placaBuscada, { delay: 40 });
                await page.keyboard.press('Enter');

                await page.evaluate(() => {
                    const botones = Array.from(document.querySelectorAll('button, input[type="submit"]'));
                    const btn = botones.find(b => /consultar|buscar|continuar/i.test(b.innerText || b.value || ''));
                    if (btn) btn.click();
                });
            }

            for (let i = 0; i < 12; i++) {
                if (jsonCapturadoEnVivo || nuevoToken) break;
                await new Promise(r => setTimeout(r, 500));
            }

            if (!nuevoToken) {
                nuevoToken = await page.evaluate(() => {
                    for (const st of [localStorage, sessionStorage]) {
                        for (let i = 0; i < st.length; i++) {
                            const m = (st.getItem(st.key(i)) || '').match(/eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/);
                            if (m) return m[0];
                        }
                    }
                    return null;
                });
            }

            if (nuevoToken) {
                try { fs.writeFileSync(rutaTokenCache, nuevoToken, 'utf8'); } catch (e) {}
            }

            if (jsonCapturadoEnVivo) {
                return jsonCapturadoEnVivo;
            }

            if (nuevoToken) {
                dataMultas = await pedirMultasConToken(placaBuscada, nuevoToken);
                if (dataMultas) return dataMultas;
            }
        } finally {
            await page.close().catch(() => {});
        }

        return { status: 'error', message: 'No se pudo renovar token de Autority', data: [] };
    } catch (e) {
        return { status: 'error', message: e.message, data: [] };
    }
}

// =========================================================================
// 3. CONSULTA DE VALORES DE MATRÍCULA SRI (Pestaña en Navegador)
// =========================================================================
async function consultarSRI(browser, placaBuscada) {
    const page = await browser.newPage();
    try {
        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36');
        await page.goto('https://srienlinea.sri.gob.ec/sri-en-linea/SriVehiculosWeb/ConsultaValoresPagarVehiculos/Consultas/consultaRubros', {
            waitUntil: 'domcontentloaded',
            timeout: 25000
        });

        const resultado = await page.evaluate(async (p) => {
            const baseRest = 'https://srienlinea.sri.gob.ec/sri-matriculacion-vehicular-recaudacion-servicio-internet/rest';
            const resVeh = await fetch(`${baseRest}/BaseVehiculo/obtenerPorNumeroPlacaOPorNumeroCampvOPorNumeroCpn?numeroPlacaCampvCpn=${p}`, {
                headers: { 'Accept': 'application/json' }
            });
            const vehiculo = await resVeh.json();
            const cod = vehiculo?.codigoVehiculo || vehiculo?.vehiculo?.codigoVehiculo || null;

            let rubros = [];
            if (cod) {
                const resRub = await fetch(`${baseRest}/ConsultaRubros/obtenerPorCodigoVehiculo?codigoVehiculo=${cod}`, {
                    headers: { 'Accept': 'application/json' }
                });
                const txtRub = await resRub.text();
                try { rubros = txtRub ? JSON.parse(txtRub) : []; } catch (e) { rubros = []; }
            }
            return { ok: true, vehiculo, rubros };
        }, placaBuscada);

        return resultado;
    } catch (e) {
        return { ok: false, error: e.message, vehiculo: null, rubros: [] };
    } finally {
        await page.close().catch(() => {});
    }
}

// =========================================================================
// 4. CONSULTA DE FICHA TÉCNICA AXISCLOUD (Pestaña en Navegador)
// =========================================================================
async function consultarAxis(browser, placaBuscada) {
    const page = await browser.newPage();
    try {
        const promesaAxis = new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('Timeout AxisCloud')), 30000);
            page.on('response', async (response) => {
                if (response.url().includes('datosVehiculo.jsp') && response.request().method() === 'POST') {
                    try {
                        const texto = await response.text();
                        if (texto && texto.includes('lsDatosIdentificacion')) {
                            clearTimeout(timer);
                            resolve(JSON.parse(texto.trim()));
                        }
                    } catch (e) {}
                }
            });
        });

        await page.goto('https://servicios.axiscloud.ec/CRV/?ps_empresa=05', {
            waitUntil: 'networkidle2',
            timeout: 25000
        });

        const inputSelector = 'input[type="text"]';
        await page.waitForSelector(inputSelector, { timeout: 12000 });
        await page.click(inputSelector, { clickCount: 3 });
        await page.type(inputSelector, placaBuscada, { delay: 40 });

        await page.evaluate(() => {
            const botones = Array.from(document.querySelectorAll('button, input[type="button"], input[type="submit"], a'));
            const btn = botones.find(b => /buscar/i.test(b.innerText || b.value || ''));
            if (btn) btn.click();
        });

        return await promesaAxis;
    } catch (e) {
        return { error_script: e.message };
    } finally {
        await page.close().catch(() => {});
    }
}

// =========================================================================
// EJECUCIÓN MAESTRA EN PARALELO (ESTE BLOQUE ARRANCA EL PROGRAMA)
// =========================================================================
(async () => {
    let browser;
    try {
        browser = await puppeteer.launch({
            headless: 'new',
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-gpu',
                '--disable-blink-features=AutomationControlled'
            ]
        });

        const [propietario, multas, sri, axis] = await Promise.all([
            consultarPropietario(placa),
            consultarMultas(browser, placa),
            consultarSRI(browser, placa),
            consultarAxis(browser, placa)
        ]);

        const consolidado = JSON.stringify({
            status: 'success',
            placa,
            propietario,
            multas,
            sri,
            axis
        }, null, 2);

        if (archivoSalida) {
            fs.writeFileSync(archivoSalida, consolidado, 'utf8');
        } else {
            console.log(consolidado);
        }

    } catch (error) {
        const errOut = JSON.stringify({ status: 'error', message: error.message });
        if (archivoSalida) fs.writeFileSync(archivoSalida, errOut, 'utf8');
        else console.log(errOut);
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