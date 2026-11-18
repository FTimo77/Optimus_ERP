const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

if (!process.env.PUPPETEER_CACHE_DIR && process.platform === 'win32') {
    process.env.PUPPETEER_CACHE_DIR = 'C:\\Users\\LENOVO\\.cache\\puppeteer';
}

const placa = (process.argv[2] || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
const archivoSalida = process.argv[3];

if (!placa) {
    console.log(JSON.stringify({ status: 'error', message: 'Placa vacía', data: [] }));
    process.exit(1);
}

const rutaTokenCache = path.join(__dirname, 'storage', 'app', 'autority_token.txt');

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

async function pedirMultasConToken(placaBuscada, token) {
    if (!token) return null;
    const limpio = token.replace(/^Bearer\s+/i, '').trim();
    const url = `https://api.autority.io/traffic-ticket/anonymous/PLA/${placaBuscada}`;

    for (const authHeader of [`Bearer ${limpio}`, limpio]) {
        try {
            const res = await fetch(url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json, text/plain, */*',
                    'Authorization': authHeader,
                    'Origin': 'https://multas.autority.io',
                    'Referer': `https://multas.autority.io/fines?type=PLA&value=${placaBuscada}&entity=`,
                    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
                },
                signal: AbortSignal.timeout(25000)
            });

            const texto = await res.text();
            if (res.ok && texto.includes('"status":"success"')) {
                return texto.trim();
            }
            if (res.status === 401 || res.status === 403) {
                break;
            }
        } catch (e) {}
    }
    return null;
}

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
            fs.mkdirSync(path.dirname(rutaTokenCache), { recursive: true });
            fs.writeFileSync(rutaTokenCache, token, 'utf8');
            return token;
        }
    } catch (e) {}
    return null;
}

async function renovarTokenYConsultarConPuppeteer(placaBuscada) {
    let browser;
    const urlsVistas = [];
    try {
        const opcionesLaunch = {
            headless: 'new', // Lo abrimos visible 1 vez para ver si Autority carga o pide algo
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-gpu',
                '--disable-blink-features=AutomationControlled'
            ]
        };

        const exeWin = obtenerRutaNavegadorWindows();
        if (exeWin) opcionesLaunch.executablePath = exeWin;

        browser = await puppeteer.launch(opcionesLaunch);
        const page = await browser.newPage();

        // Inyectamos un interceptor directo en el window.fetch y XMLHttpRequest de la página
        await page.evaluateOnNewDocument(() => {
            Object.defineProperty(navigator, 'webdriver', { get: () => false });
            window.__capturaAutority = { token: null, json: null };

            const origFetch = window.fetch;
            window.fetch = async function (...args) {
                try {
                    const reqUrl = typeof args[0] === 'string' ? args[0] : (args[0]?.url || '');
                    const opts = args[1] || {};
                    const headers = opts.headers || {};
                    const auth = headers['Authorization'] || headers['authorization'] || (headers.get && headers.get('Authorization'));
                    if (auth && String(auth).includes('eyJ')) {
                        window.__capturaAutority.token = String(auth).replace(/^Bearer\s+/i, '').trim();
                    }
                    const response = await origFetch.apply(this, args);
                    const clone = response.clone();
                    clone.text().then(txt => {
                        const m = txt.match(/eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/);
                        if (m && !window.__capturaAutority.token) {
                            window.__capturaAutority.token = m[0];
                        }
                        if (reqUrl.includes('traffic-ticket') && txt.includes('"status":"success"')) {
                            window.__capturaAutority.json = txt.trim();
                        }
                    }).catch(() => {});
                    return response;
                } catch (e) {
                    return origFetch.apply(this, args);
                }
            };

            const origOpen = XMLHttpRequest.prototype.open;
            const origSetHeader = XMLHttpRequest.prototype.setRequestHeader;
            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                this.__url = url;
                this.addEventListener('load', function () {
                    try {
                        const txt = this.responseText || '';
                        const m = txt.match(/eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/);
                        if (m && !window.__capturaAutority.token) {
                            window.__capturaAutority.token = m[0];
                        }
                        if ((this.__url || '').includes('traffic-ticket') && txt.includes('"status":"success"')) {
                            window.__capturaAutority.json = txt.trim();
                        }
                    } catch (e) {}
                });
                return origOpen.call(this, method, url, ...rest);
            };
            XMLHttpRequest.prototype.setRequestHeader = function (header, value) {
                if (/authorization/i.test(header) && String(value).includes('eyJ')) {
                    window.__capturaAutority.token = String(value).replace(/^Bearer\s+/i, '').trim();
                }
                return origSetHeader.call(this, header, value);
            };
        });

        let nuevoToken = null;
        let jsonDirecto = null;

        page.on('request', (req) => {
            const url = req.url();
            if (url.includes('autority.io')) {
                const auth = req.headers()['authorization'];
                if (auth && auth.includes('eyJ')) {
                    nuevoToken = auth.replace(/^Bearer\s+/i, '').trim();
                }
            }
        });

        page.on('response', async (res) => {
            const url = res.url();
            if (url.includes('autority.io')) {
                urlsVistas.push(`${res.status()}:${url.split('?')[0]}`);
                if (res.request().method() !== 'OPTIONS') {
                    try {
                        const txt = await res.text();
                        const match = txt.match(/eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/);
                        if (match && !nuevoToken) nuevoToken = match[0];
                        if (url.includes('traffic-ticket') && txt.includes('"status":"success"')) {
                            jsonDirecto = txt.trim();
                        }
                    } catch (e) {}
                }
            }
        });

        const urlFines = `https://multas.autority.io/fines?type=PLA&value=${placaBuscada}&entity=`;
        await page.goto(urlFines, {
            waitUntil: 'domcontentloaded',
            timeout: 25000
        });

        // Esperamos hasta 10 segundos revisando tanto los eventos de red como el interceptor interno
        for (let i = 0; i < 20; i++) {
            const interno = await page.evaluate(() => window.__capturaAutority || {}).catch(() => ({}));
            if (interno.token && !nuevoToken) nuevoToken = interno.token;
            if (interno.json && !jsonDirecto) jsonDirecto = interno.json;

            if (jsonDirecto) break;
            await new Promise(r => setTimeout(r, 500));
        }

        if (nuevoToken) {
            fs.mkdirSync(path.dirname(rutaTokenCache), { recursive: true });
            fs.writeFileSync(rutaTokenCache, nuevoToken, 'utf8');
        }

        if (jsonDirecto) {
            return { json: jsonDirecto, debug: 'OK directo' };
        }

        if (nuevoToken) {
            const resApi = await pedirMultasConToken(placaBuscada, nuevoToken);
            if (resApi) return { json: resApi, debug: 'OK con token renovado' };
        }

        return { json: null, debug: `Token:${nuevoToken ? 'SI' : 'NO'} | URLs:${urlsVistas.slice(0, 6).join(', ')}` };
    } catch (err) {
        return { json: null, debug: `Excepcion: ${err.message}` };
    } finally {
        if (browser) {
            const pages = await browser.pages().catch(() => []);
            await Promise.all(pages.map(p => p.close().catch(() => {})));
            await browser.close().catch(() => {});
        }
    }
}

(async () => {
    try {
        let tokenActual = '';
        if (fs.existsSync(rutaTokenCache)) {
            tokenActual = fs.readFileSync(rutaTokenCache, 'utf8').trim();
        }

        let jsonMultas = await pedirMultasConToken(placa, tokenActual);

        if (!jsonMultas) {
            const nuevoToken = await crearSesionAnonimaAutority(placa);
            if (nuevoToken) {
                jsonMultas = await pedirMultasConToken(placa, nuevoToken);
            }
        }

        if (!jsonMultas) {
            const res = await renovarTokenYConsultarConPuppeteer(placa);
            if (res.json) {
                jsonMultas = res.json;
            } else {
                throw new Error(`No se pudo obtener respuesta de api.autority.io (${res.debug})`);
            }
        }

        if (archivoSalida) {
            fs.writeFileSync(archivoSalida, jsonMultas, 'utf8');
        } else {
            console.log(jsonMultas);
        }

    } catch (error) {
        const errOut = JSON.stringify({ status: 'error', message: error.message, data: [] });
        if (archivoSalida) {
            fs.writeFileSync(archivoSalida, errOut, 'utf8');
        } else {
            console.log(errOut);
        }
    }
})();