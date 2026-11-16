const puppeteer = require('puppeteer');
const fs = require('fs');

if (!process.env.PUPPETEER_CACHE_DIR && process.platform === 'win32') {
    process.env.PUPPETEER_CACHE_DIR = 'C:\\Users\\LENOVO\\.cache\\puppeteer';
}

const placa = (process.argv[2] || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
const archivoSalida = process.argv[3];

if (!placa) {
    const errorMsg = JSON.stringify({ status: 'error', message: 'Placa vacía', listado: [] });
    if (archivoSalida) fs.writeFileSync(archivoSalida, errorMsg, 'utf8');
    else console.log(errorMsg);
    process.exit(1);
}

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

        const page = await browser.newPage();
        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36');

        let datosFiscalia = { tiene_denuncias: false, cantidad: 0, listado: [] };

        // Interceptamos cualquier respuesta JSON que devuelva la API de la Fiscalía al consultar
        page.on('response', async (res) => {
            const url = res.url();
            if (url.includes('fiscalia') || url.includes('denuncia') || url.includes('delito') || url.includes('noticia')) {
                try {
                    const ct = res.headers()['content-type'] || '';
                    if (ct.includes('json')) {
                        const json = await res.json();
                        const items = json.data || json.resultado || json.items || (Array.isArray(json) ? json : []);
                        if (items.length > 0) {
                            datosFiscalia = {
                                tiene_denuncias: true,
                                cantidad: items.length,
                                listado: items
                            };
                        }
                    }
                } catch (e) {}
            }
        });

        // Entramos a la página de consulta de la Fiscalía
        await page.goto('https://www.fiscalia.gob.ec/accesibilidad/consulta-de-noticias-del-delito/', {
            waitUntil: 'domcontentloaded',
            timeout: 30000
        });

        await new Promise(r => setTimeout(r, 2000));

        // Esperamos el input y escribimos la placa de forma segura
        const inputSelector = 'input[id*="placa" i], input[name*="placa" i], input[type="text"]';
        await page.waitForSelector(inputSelector, { timeout: 15000 });

        await page.evaluate((p, selector) => {
            const input = document.querySelector(selector);
            if (input) {
                input.focus();
                input.value = p;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }, placa, inputSelector);

        await new Promise(r => setTimeout(r, 500));

        // Disparamos la búsqueda mediante el botón de forma aislada para evitar conflictos de navegación
        await page.evaluate(() => {
            const botones = Array.from(document.querySelectorAll('button, input[type="submit"], a.btn'));
            const btn = botones.find(b => /consultar|buscar|enviar/i.test(b.innerText || b.value || ''));
            if (btn) {
                btn.click();
            } else {
                const form = document.querySelector('form');
                if (form) form.submit();
            }
        });

        // Esperamos a que la red responda la consulta de denuncias
        await new Promise(r => setTimeout(r, 5000));

        const salidaFinal = JSON.stringify({
            status: 'success',
            placa: placa,
            ...datosFiscalia
        }, null, 2);

        if (archivoSalida) {
            fs.writeFileSync(archivoSalida, salidaFinal, 'utf8');
        } else {
            console.log(salidaFinal);
        }

    } catch (error) {
        const errOut = JSON.stringify({ status: 'error', message: error.message, listado: [] });
        if (archivoSalida) {
            fs.writeFileSync(archivoSalida, errOut, 'utf8');
        } else {
            console.log(errOut);
        }
    } finally {
        if (browser) await browser.close();
    }
})();