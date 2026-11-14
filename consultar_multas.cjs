const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

process.env.PUPPETEER_CACHE_DIR = process.env.PUPPETEER_CACHE_DIR || 'C:\\Users\\LENOVO\\.cache\\puppeteer';

const placa = (process.argv[2] || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
const archivoSalida = process.argv[3];

if (!placa) {
    process.exit(1);
}

// Guardamos el token en caché para que las consultas tarden solo 0.4 segundos durante 30 minutos
const rutaTokenCache = path.join(__dirname, 'storage', 'app', 'autority_token.txt');

// Token inicial que capturaste
const TOKEN_INICIAL = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpZCI6NjYwNDU4LCJ1c2VyTmFtZSI6IkFub255bW91czIzODUiLCJpYXQiOjE3OTQ2MjA0MTksImV4cCI6MTc5NDYyMjIxOX0.67YrS24vHBSYvodD3nAsuaJoqtHO_-6yshdY3uaWNFI';

// Función que consulta la API directa de Autority con el token
async function pedirMultasConToken(placaBuscada, token) {
    const authHeader = token.startsWith('Bearer ') ? token : `Bearer ${token}`;
    const url = `https://api.autority.io/traffic-ticket/anonymous/PLA/${placaBuscada}`;

    const res = await fetch(url, {
        method: 'GET',
        headers: {
            'Accept': 'application/json, text/plain, */*',
            'Authorization': authHeader,
            'Origin': 'https://multas.autority.io',
            'Referer': 'https://multas.autority.io/',
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
        },
        signal: AbortSignal.timeout(12000)
    });

    const texto = await res.text();
    if (res.ok && texto.includes('"status":"success"')) {
        return texto.trim();
    }

    // También probamos enviando el token sin la palabra "Bearer " por si el backend lo recibe crudo
    const resCrudo = await fetch(url, {
        method: 'GET',
        headers: {
            'Accept': 'application/json, text/plain, */*',
            'Authorization': token.replace(/^Bearer\s+/i, ''),
            'Origin': 'https://multas.autority.io',
            'Referer': 'https://multas.autority.io/',
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
        },
        signal: AbortSignal.timeout(12000)
    });

    const textoCrudo = await resCrudo.text();
    if (resCrudo.ok && textoCrudo.includes('"status":"success"')) {
        return textoCrudo.trim();
    }

    return null;
}

// Si el token de 30 minutos caducó, abrimos Puppeteer 1 sola vez para capturar un token Anonymous nuevo
async function renovarTokenConPuppeteer(placaBuscada) {
    let browser;
    try {
        browser = await puppeteer.launch({
            headless: 'new',
            args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu']
        });

        const page = await browser.newPage();
        let nuevoToken = null;

        // Escuchamos todas las peticiones y respuestas de api.autority.io para atrapar el JWT "eyJ..."
        page.on('request', (req) => {
            const auth = req.headers()['authorization'];
            if (auth && auth.includes('eyJ')) {
                nuevoToken = auth;
            }
        });

        page.on('response', async (res) => {
            if (res.url().includes('autority.io') && res.request().method() !== 'OPTIONS') {
                try {
                    const txt = await res.text();
                    const match = txt.match(/eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/);
                    if (match) {
                        nuevoToken = match[0];
                    }
                } catch (e) {}
            }
        });

        await page.goto('https://multas.autority.io/', {
            waitUntil: 'networkidle2',
            timeout: 25000
        });

        // Si el token no salió en las cabeceras de red iniciales, buscamos el JWT dentro de localStorage/sessionStorage/cookies
        if (!nuevoToken) {
            nuevoToken = await page.evaluate(() => {
                const buscarJWT = (storage) => {
                    for (let i = 0; i < storage.length; i++) {
                        const val = storage.getItem(storage.key(i)) || '';
                        const m = val.match(/eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/);
                        if (m) return m[0];
                    }
                    return null;
                };
                return buscarJWT(localStorage) || buscarJWT(sessionStorage) || (document.cookie.match(/eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/) || [])[0] || null;
            });
        }

        // Si aún no genera el token hasta que se hace una consulta en la web, disparamos una consulta rápida
        if (!nuevoToken) {
            const input = await page.$('input');
            if (input) {
                await input.type(placaBuscada);
                await page.keyboard.press('Enter');
                await new Promise(r => setTimeout(r, 2500));
            }
        }

        if (nuevoToken) {
            try {
                fs.writeFileSync(rutaTokenCache, nuevoToken.trim(), 'utf8');
            } catch (e) {}
        }

        return nuevoToken;
    } finally {
        if (browser) await browser.close();
    }
}

(async () => {
    try {
        // 1. Leemos el token en caché (o usamos el token inicial que capturaste)
        let tokenActual = TOKEN_INICIAL;
        if (fs.existsSync(rutaTokenCache)) {
            const guardado = fs.readFileSync(rutaTokenCache, 'utf8').trim();
            if (guardado) tokenActual = guardado;
        }

        // 2. Intentamos la consulta ultrarrápida (0.4s) con el token actual
        let jsonMultas = await pedirMultasConToken(placa, tokenActual);

        // 3. Si el token ya expiró (pasaron los 30 min), lo renovamos automáticamente con Puppeteer y reintentamos
        if (!jsonMultas) {
            const tokenFresco = await renovarTokenConPuppeteer(placa);
            if (tokenFresco) {
                jsonMultas = await pedirMultasConToken(placa, tokenFresco);
            }
        }

        if (!jsonMultas) {
            throw new Error('No se pudo obtener respuesta de api.autority.io');
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