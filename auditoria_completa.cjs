const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

process.env.PUPPETEER_CACHE_DIR = process.env.PUPPETEER_CACHE_DIR || 'C:\\Users\\LENOVO\\.cache\\puppeteer';

const placaRaw = (process.argv[2] || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
const arg3Raw = (process.argv[3] || '').trim().replace(/^["']+|["']+$/g, '');
const arg4Raw = (process.argv[4] || '').trim().replace(/^["']+|["']+$/g, '');

// En PowerShell, pasar "" puede eliminarse como argumento y desplazar la cédula a argv[3]
const arg3EsCedula = arg4Raw === '' && /^\d{10}(\d{3})?$/.test(arg3Raw);
const archivoSalida = arg3EsCedula ? '' : arg3Raw;
const cedula = (arg3EsCedula ? arg3Raw : arg4Raw).replace(/[^0-9]/g, '');

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

// 2. MULTAS (Autority: api.autority.io)
async function crearSesionAnonimaAutority(placaBuscada) {
    try {
        const res = await fetch('https://api.autority.io/public/anonymous-session', {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/plain, */*',
                'Content-Type': 'application/json',
                'Origin': 'https://multas.autority.io',
                'Referer': `https://multas.autority.io/fines?type=PLA&value=${placaBuscada}&entity=`,
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
            },
            body: JSON.stringify({ identifierType: 'PLA', value: placaBuscada }),
            signal: AbortSignal.timeout(10000)
        });
        const json = await res.json();
        const token = json?.data?.token ? String(json.data.token).trim() : '';
        if (token) {
            try {
                fs.mkdirSync(path.dirname(rutaTokenCache), { recursive: true });
                fs.writeFileSync(rutaTokenCache, token, 'utf8');
            } catch (e) {}
            return token;
        }
    } catch (e) {}
    return null;
}

async function pedirMultasConToken(placaBuscada, token) {
    if (!token) return null;
    try {
        const url = `https://api.autority.io/traffic-ticket/anonymous/PLA/${placaBuscada}`;
        const limpio = token.replace(/^Bearer\s+/i, '').trim();
        for (const authVal of [limpio, `Bearer ${limpio}`]) {
            const res = await fetch(url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json, text/plain, */*',
                    'Authorization': authVal,
                    'Origin': 'https://multas.autority.io',
                    'Referer': `https://multas.autority.io/fines?type=PLA&value=${placaBuscada}&entity=`,
                    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
                },
                signal: AbortSignal.timeout(25000)
            });
            const txt = await res.text();
            if (res.ok && txt.includes('"status":"success"')) {
                return JSON.parse(txt);
            }
            if (res.status === 401 || res.status === 403) {
                break;
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

        // 1. Intentar con token en caché
        let dataMultas = await pedirMultasConToken(placaBuscada, token);
        if (dataMultas) return dataMultas;

        // 2. Generar nueva sesión anónima directo contra api.autority.io/public/anonymous-session
        const tokenFresco = await crearSesionAnonimaAutority(placaBuscada);
        if (tokenFresco) {
            dataMultas = await pedirMultasConToken(placaBuscada, tokenFresco);
            if (dataMultas) return dataMultas;
        }

        // 3. Fallback con Puppeteer abriendo directamente /fines?type=PLA&value=...
        const page = await browser.newPage();
        let nuevoToken = null;
        let jsonVivo = null;
        try {
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
                            jsonVivo = JSON.parse(txt);
                        }
                    } catch (e) {}
                }
            });

            await page.goto(`https://multas.autority.io/fines?type=PLA&value=${placaBuscada}&entity=`, {
                waitUntil: 'domcontentloaded',
                timeout: 25000
            });

            for (let i = 0; i < 30; i++) {
                if (jsonVivo) break;
                await new Promise(r => setTimeout(r, 500));
            }

            if (nuevoToken) {
                try {
                    fs.mkdirSync(path.dirname(rutaTokenCache), { recursive: true });
                    fs.writeFileSync(rutaTokenCache, nuevoToken, 'utf8');
                } catch (e) {}
            }
            if (jsonVivo) return jsonVivo;
            if (nuevoToken) {
                const retry = await pedirMultasConToken(placaBuscada, nuevoToken);
                if (retry) return retry;
            }
        } finally {
            await page.close().catch(() => {});
        }
        return { status: 'error', data: [] };
    } catch (e) {
        return { status: 'error', data: [] };
    }
}

// 3. MATRICULA Y CANTIDAD DE TRANSFERENCIAS (SRI)
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

// 5. DENUNCIAS (Fiscalía General del Estado - AJX_consulta_noticiasdelito.php)
const URL_AJX_FISCALIA = 'https://www.gestiondefiscalias.gob.ec/siaf/sitio/consulta_ndd_ext/AJX_consulta_noticiasdelito.php';
const URL_REDIRECT_FISCALIA = 'https://www.gestiondefiscalias.gob.ec/siaf/sitio/consulta_ndd_ext/redirect.php?data=L3Zhci93d3cvaHRtbC9zaWFmL2luZm9ybWFjaW9uL3dlYi9ub3RpY2lhc2RlbGl0by8uLi8uLi8uLi9zaXRpby9jb25zdWx0YV9uZGRfZXh0L2NvbnN1bHRhX2NpdWRhZGFuYS5waHA%3D';

async function llamarAjaxFiscalia(browser, criterio, valor) {
    // 1. Consulta HTTP directa a AJX_consulta_noticiasdelito.php
    try {
        const res = await fetch(URL_AJX_FISCALIA, {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/javascript, */*; q=0.01',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'Origin': 'https://www.gestiondefiscalias.gob.ec',
                'Referer': URL_REDIRECT_FISCALIA,
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
            },
            body: new URLSearchParams({
                tipo: 'buscar_general',
                criterio: String(criterio),
                valor: String(valor)
            }),
            signal: AbortSignal.timeout(15000)
        });

        if (res.ok) {
            const json = await res.json();
            if (json && typeof json === 'object' && !json.error) {
                const cabecera = Array.isArray(json.cabecera)
                    ? json.cabecera
                    : (json.cabecera && typeof json.cabecera === 'object' ? Object.values(json.cabecera) : []);
                return { ok: true, cabecera };
            }
        }
    } catch (e) {}

    // 2. Fallback en el contexto del navegador (iframe SIAF de Fiscalía)
    if (!browser) return { ok: false, cabecera: [] };
    const page = await browser.newPage();
    try {
        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36');
        await page.goto(URL_REDIRECT_FISCALIA, { waitUntil: 'domcontentloaded', timeout: 25000 });
        const respuesta = await page.evaluate(async (crit, val) => {
            const r = await fetch('AJX_consulta_noticiasdelito.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json, text/javascript, */*; q=0.01',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    tipo: 'buscar_general',
                    criterio: String(crit),
                    valor: String(val)
                })
            });
            return await r.json();
        }, criterio, valor);

        if (respuesta && typeof respuesta === 'object' && !respuesta.error) {
            const cabecera = Array.isArray(respuesta.cabecera)
                ? respuesta.cabecera
                : (respuesta.cabecera && typeof respuesta.cabecera === 'object' ? Object.values(respuesta.cabecera) : []);
            return { ok: true, cabecera };
        }
    } catch (e) {
    } finally {
        await page.close().catch(() => {});
    }

    return { ok: false, cabecera: [] };
}

async function consultarFiscalia(browser, placaBuscada) {
    try {
        // Criterio 5 = Placa en AJX_consulta_noticiasdelito.php
        const res = await llamarAjaxFiscalia(browser, 5, placaBuscada);
        if (!res.ok) {
            return { ok: false, tiene_denuncias: false, cantidad: 0, listado: [] };
        }

        const listado = res.cabecera
            .filter(row => row && typeof row === 'object')
            .map(row => ({
                ndd: String(row.ndd || row.ndd1 || 'S/N').trim(),
                noticiaDelito: String(row.ndd || row.ndd1 || 'S/N').trim(),
                fecha: String(row.fecha || '').trim(),
                hora: String(row.hora || '').trim(),
                provincia: String(row.pro_descripcion || '').trim(),
                canton: String(row.ciudad || '').trim(),
                delito: String(row.gen_delito_tipopenal || row.tipoincidente || 'Noticia de delito registrada').trim(),
                descripcion: String(row.gen_delito_tipopenal || row.tipoincidente || 'Noticia de delito registrada').trim(),
                unidad: String(row.unidad || '').trim()
            }));

        return {
            ok: true,
            tiene_denuncias: listado.length > 0,
            cantidad: listado.length,
            listado
        };
    } catch (e) {
        return { ok: false, tiene_denuncias: false, cantidad: 0, listado: [] };
    }
}

async function consultarFiscaliaCedula(browser, cedulaBuscada) {
    const limpia = String(cedulaBuscada || '').replace(/[^0-9]/g, '');
    if (limpia.length !== 10 && limpia.length !== 13) {
        return {
            ok: false,
            consultado: false,
            cedula: limpia,
            motivo: 'cedula_invalida',
            tiene_denuncias: false,
            cantidad: 0,
            total_procesos_vista: 0,
            procesos_otros_roles: 0,
            listado: []
        };
    }

    try {
        // Criterio 2 = Cédula (10 dígitos), Criterio 3 = RUC (13 dígitos)
        const criterio = limpia.length === 13 ? 3 : 2;
        const res = await llamarAjaxFiscalia(browser, criterio, limpia);

        if (!res.ok) {
            return {
                ok: false,
                consultado: true,
                cedula: limpia,
                tiene_denuncias: false,
                cantidad: 0,
                total_procesos_vista: 0,
                procesos_otros_roles: 0,
                listado: []
            };
        }

        const listadoAlertas = [];
        let totalProcesosVista = 0;

        for (const row of res.cabecera) {
            if (!row || typeof row !== 'object') continue;
            totalProcesosVista++;

            const sujetos = Array.isArray(row.sujetos) ? row.sujetos : [];
            const coincidenciasSujeto = sujetos.filter(
                s => s && typeof s === 'object' && String(s.cedula || '').trim() === limpia
            );

            // Verificar si en este proceso figura como SOSPECHOSO o DENUNCIADO
            let rolDetectado = null;
            let nombreDetectado = '';

            for (const s of coincidenciasSujeto) {
                const tipoUpper = String(s.tipo || '').trim().toUpperCase();
                if (tipoUpper.includes('SOSPECHOSO')) {
                    rolDetectado = 'SOSPECHOSO';
                    nombreDetectado = String(s.persona || '').trim();
                    break;
                }
                if (tipoUpper.includes('DENUNCIADO')) {
                    rolDetectado = 'DENUNCIADO';
                    nombreDetectado = String(s.persona || '').trim();
                    break;
                }
            }

            if (rolDetectado) {
                listadoAlertas.push({
                    ndd: String(row.ndd || row.ndd1 || 'S/N').trim(),
                    noticiaDelito: String(row.ndd || row.ndd1 || 'S/N').trim(),
                    fecha: String(row.fecha || '').trim(),
                    provincia: String(row.pro_descripcion || '').trim(),
                    canton: String(row.ciudad || '').trim(),
                    delito: String(row.gen_delito_tipopenal || row.tipoincidente || 'Investigación fiscal registrada').trim(),
                    descripcion: String(row.gen_delito_tipopenal || row.tipoincidente || 'Investigación fiscal registrada').trim(),
                    rol: rolDetectado,
                    nombre_sujeto: nombreDetectado
                });
            }
        }

        return {
            ok: true,
            consultado: true,
            cedula: limpia,
            tiene_denuncias: listadoAlertas.length > 0,
            cantidad: listadoAlertas.length,
            total_procesos_vista: totalProcesosVista,
            procesos_otros_roles: Math.max(0, totalProcesosVista - listadoAlertas.length),
            listado: listadoAlertas
        };
    } catch (e) {
        return {
            ok: false,
            consultado: true,
            cedula: limpia,
            tiene_denuncias: false,
            cantidad: 0,
            total_procesos_vista: 0,
            procesos_otros_roles: 0,
            listado: []
        };
    }
}

// EJECUCIÓN PARALELA DE LAS FUENTES
(async () => {
    let browser;
    try {
        browser = await puppeteer.launch({
            headless: 'new',
            args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu']
        });

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
        if (browser) await browser.close();
    }
})();
