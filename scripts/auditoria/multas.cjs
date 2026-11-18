const fs = require('fs');
const path = require('path');

const TOKEN_INICIAL = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpZCI6NjYwNDU4LCJ1c2VyTmFtZSI6IkFub255bW91czIzODUiLCJpYXQiOjE3OTQ2MjA0MTksImV4cCI6MTc5NDYyMjIxOX0.67YrS24vHBSYvodD3nAsuaJoqtHO_-6yshdY3uaWNFI';

function guardarTokenCache(rutaTokenCache, token) {
    if (!token) return;
    try {
        fs.mkdirSync(path.dirname(rutaTokenCache), { recursive: true });
        fs.writeFileSync(rutaTokenCache, token, 'utf8');
    } catch (e) {}
}

async function crearSesionAnonimaAutority(placaBuscada, rutaTokenCache) {
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
            guardarTokenCache(rutaTokenCache, token);
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

async function consultarMultas(browser, placaBuscada, rutaTokenCache) {
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
        const tokenFresco = await crearSesionAnonimaAutority(placaBuscada, rutaTokenCache);
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
                guardarTokenCache(rutaTokenCache, nuevoToken);
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

module.exports = {
    consultarMultas
};
