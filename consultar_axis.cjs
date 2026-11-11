const puppeteer = require('puppeteer');
const fs = require('fs');

// Le indicamos a Puppeteer dónde está el navegador instalado dentro del propio script
process.env.PUPPETEER_CACHE_DIR = 'C:\\Users\\LENOVO\\.cache\\puppeteer';

const placa = process.argv[2];
const archivoSalida = process.argv[3];

if (!placa || !archivoSalida) {
    process.exit(1);
}

(async () => {
    let browser;
    try {
        browser = await puppeteer.launch({
            headless: 'new',
            args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu']
        });

        const page = await browser.newPage();

        const respuestaAxis = new Promise((resolve, reject) => {
            const timeout = setTimeout(() => reject(new Error('Tiempo agotado en AxisCloud')), 35000);

            page.on('response', async (response) => {
                if (response.url().includes('datosVehiculo.jsp') && response.request().method() === 'POST') {
                    try {
                        const texto = await response.text();
                        if (texto && texto.includes('lsDatosIdentificacion')) {
                            clearTimeout(timeout);
                            resolve(texto.trim());
                        }
                    } catch (err) {
                        // Continuar esperando
                    }
                }
            });
        });

        await page.goto('https://servicios.axiscloud.ec/CRV/?ps_empresa=05', {
            waitUntil: 'networkidle2',
            timeout: 30000
        });

        const inputSelector = 'input[type="text"]';
        await page.waitForSelector(inputSelector, { timeout: 15000 });
        await page.click(inputSelector, { clickCount: 3 });
        await page.type(inputSelector, placa, { delay: 50 });

        await page.evaluate(() => {
            const botones = Array.from(document.querySelectorAll('button, input[type="button"], input[type="submit"], a'));
            const btnBuscar = botones.find(b => 
                (b.innerText && b.innerText.toLowerCase().includes('buscar')) || 
                (b.value && b.value.toLowerCase().includes('buscar'))
            );
            if (btnBuscar) btnBuscar.click();
        });

        const jsonCrudo = await respuestaAxis;
        // Guardamos el JSON directamente en disco en UTF-8 puro
        fs.writeFileSync(archivoSalida, jsonCrudo, 'utf8');

    } catch (error) {
        fs.writeFileSync(archivoSalida, JSON.stringify({ error_script: error.message }), 'utf8');
    } finally {
        if (browser) await browser.close();
    }
})();