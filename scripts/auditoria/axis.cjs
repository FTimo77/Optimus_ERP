// Consulta de ficha técnica en AxisCloud
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

module.exports = {
    consultarAxis
};
