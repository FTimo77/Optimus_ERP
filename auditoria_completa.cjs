const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

process.env.PUPPETEER_CACHE_DIR = process.env.PUPPETEER_CACHE_DIR || 'C:\\Users\\LENOVO\\.cache\\puppeteer';

const placaRaw = (process.argv[2] || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
const archivoSalida = process.argv[3];

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
                    'Referer': 'https://multas.autority.io/',
                    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
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
        let token = TOKEN_INICIAL;
        if (fs.existsSync(rutaTokenCache)) {
            const guardado = fs.readFileSync(rutaTokenCache, 'utf8').trim();
            if (guardado) token = guardado;
        }
        let dataMultas = await pedirMultasConToken(placaBuscada, token);
        if (dataMultas) return dataMultas;

        const page = await browser.newPage();
        let nuevoToken = null;
        let jsonVivo = null;
        try {
            page.on('request', req => {
                const auth = req.headers()['authorization'];
                if (auth && auth.includes('eyJ')) nuevoToken = auth.replace(/^Bearer\s+/i, '').trim();
            });
            page.on('response', async res => {
                const url = res.url();
                if (url.includes('autority.io') && res.request().method() !== 'OPTIONS') {
                    try {
                        const txt = await res.text();
                        const m = txt.match(/eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/);
                        if (m && !nuevoToken) nuevoToken = m[0];
                        if (url.includes('traffic-ticket') && txt.includes('"status":"success"')) jsonVivo = JSON.parse(txt);
                    } catch (e) {}
                }
            });
            await page.goto('https://multas.autority.io/', { waitUntil: 'networkidle2', timeout: 20000 });
            await new Promise(r => setTimeout(r, 800));
            
            const input = await page.$('input[type="text"], input[type="search"], input:not([type="hidden"])');
            if (input) {
                await input.click({ clickCount: 3 });
                await page.keyboard.press('Backspace');
                await input.type(placaBuscada, { delay: 40 });
                await page.keyboard.press('Enter');
            }
            for (let i = 0; i < 10; i++) {
                if (jsonVivo || nuevoToken) break;
                await new Promise(r => setTimeout(r, 500));
            }
            if (nuevoToken) fs.writeFileSync(rutaTokenCache, nuevoToken, 'utf8');
            if (jsonVivo) return jsonVivo;
            if (nuevoToken) return await pedirMultasConToken(placaBuscada, nuevoToken);
        } finally {
            await page.close().catch(() => {});
        }
        return { status: 'error', data: [] };
    } catch (e) {
        return { status: 'error', data: [] };
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

// 5. DENUNCIAS (Fiscalía FGE)
async function consultarFiscalia(browser, placaBuscada) {
    const page = await browser.newPage();
    try {
        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        let resultado = { tiene_denuncias: false, cantidad: 0, listado: [] };

        page.on('response', async (res) => {
            const url = res.url();
            if (url.includes('fiscalia') || url.includes('denuncia') || url.includes('delito') || url.includes('noticia')) {
                try {
                    const ct = res.headers()['content-type'] || '';
                    if (ct.includes('json')) {
                        const json = await res.json();
                        const items = json.data || json.resultado || json.items || (Array.isArray(json) ? json : []);
                        if (items.length > 0) {
                            resultado = { tiene_denuncias: true, cantidad: items.length, listado: items };
                        }
                    }
                } catch (e) {}
            }
        });

        await page.goto('https://www.fiscalia.gob.ec/accesibilidad/consulta-de-noticias-del-delito/', { waitUntil: 'domcontentloaded', timeout: 25000 });
        await new Promise(r => setTimeout(r, 1000));

        const selector = 'input[id*="placa" i], input[name*="placa" i], input[type="text"]';
        await page.waitForSelector(selector, { timeout: 10000 });
        await page.evaluate((p, sel) => {
            const el = document.querySelector(sel);
            if (el) {
                el.focus();
                el.value = p;
                el.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }, placaBuscada, selector);

        await page.evaluate(() => {
            const btn = Array.from(document.querySelectorAll('button, input[type="submit"], a')).find(b => /consultar|buscar|enviar/i.test(b.innerText || b.value));
            if (btn) btn.click();
        });

        await new Promise(r => setTimeout(r, 4000));
        return resultado;
    } catch (e) {
        return { tiene_denuncias: false, cantidad: 0, listado: [] };
    } finally {
        await page.close().catch(() => {});
    }
}

// EJECUCIÓN PARALELA DE LAS 5 FUENTES
(async () => {
    let browser;
    try {
        browser = await puppeteer.launch({
            headless: 'new',
            args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu']
        });

        const [propietario, multas, sri, axis, fiscalia] = await Promise.all([
            consultarPropietario(placa),
            consultarMultas(browser, placa),
            consultarSRI(browser, placa),
            consultarAxis(browser, placa),
            consultarFiscalia(browser, placa)
        ]);

        const paquete = JSON.stringify({ status: 'success', placa, propietario, multas, sri, axis, fiscalia }, null, 2);
        if (archivoSalida) fs.writeFileSync(archivoSalida, paquete, 'utf8');
        else console.log(paquete);

    } catch (error) {
        const err = JSON.stringify({ status: 'error', message: error.message });
        if (archivoSalida) fs.writeFileSync(archivoSalida, err, 'utf8');
        else console.log(err);
    } finally {
        if (browser) await browser.close();
    }
})();