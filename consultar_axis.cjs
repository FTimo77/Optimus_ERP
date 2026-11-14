const puppeteer = require('puppeteer');
const fs = require('fs');

process.env.PUPPETEER_CACHE_DIR = 'C:\\Users\\LENOVO\\.cache\\puppeteer';

const placa = process.argv[2];
const archivoSalida = process.argv[3];

if (!placa) {
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

        // Atrapamos la respuesta de datosVehiculo.jsp apenas llegue, sin bloquearla
        const respuestaAxis = new Promise((resolve, reject) => {
            const timeout = setTimeout(() => reject(new Error('Tiempo agotado en AxisCloud')), 30000);

            page.on('response', async (response) => {
                if (response.url().includes('datosVehiculo.jsp') && response.request().method() === 'POST') {
                    try {
                        const texto = await response.text();
                        if (texto && texto.trim().length > 10) {
                            clearTimeout(timeout);
                            resolve(texto.trim());
                        }
                    } catch (err) {
                        reject(err);
                    }
                }
            });
        });

        // 1. Cargamos la página rápido sin esperar recursos externos innecesarios
        await page.goto('https://servicios.axiscloud.ec/CRV/?ps_empresa=05', {
            waitUntil: 'networkidle2',
            timeout: 25000
        });

        // 2. Escribimos la placa tal como en la versión original que sí te funcionó
        const inputSelector = 'input[type="text"]';
        await page.waitForSelector(inputSelector, { timeout: 15000 });
        await page.type(inputSelector, placa);

        // 3. Hacemos clic en el botón Buscar
        await page.evaluate(() => {
            const botones = Array.from(document.querySelectorAll('button, input[type="button"], input[type="submit"], a'));
            const btnBuscar = botones.find(b => 
                b.innerText?.toLowerCase().includes('buscar') || 
                b.value?.toLowerCase().includes('buscar')
            );
            if (btnBuscar) btnBuscar.click();
        });

        const jsonCrudo = await respuestaAxis;

        if (archivoSalida) {
            fs.writeFileSync(archivoSalida, jsonCrudo, 'utf8');
        } else {
            console.log(jsonCrudo);
        }

    } catch (error) {
        const msgError = JSON.stringify({ error_script: error.message });
        if (archivoSalida) {
            fs.writeFileSync(archivoSalida, msgError, 'utf8');
        } else {
            console.log(msgError);
        }
    } finally {
        if (browser) await browser.close();
    }
})();