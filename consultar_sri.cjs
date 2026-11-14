const puppeteer = require('puppeteer');
const fs = require('fs');

process.env.PUPPETEER_CACHE_DIR = process.env.PUPPETEER_CACHE_DIR || 'C:\\Users\\LENOVO\\.cache\\puppeteer';

const placaRaw = (process.argv[2] || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
const archivoSalida = process.argv[3];

if (!placaRaw) {
    process.exit(1);
}

const placa = /^([A-Z]{3})(\d{3})$/.test(placaRaw)
    ? placaRaw.replace(/^([A-Z]{3})(\d{3})$/, '$10$2')
    : placaRaw;

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

        // 1. Entramos al dominio del SRI para activar las cookies del Firewall F5
        await page.goto('https://srienlinea.sri.gob.ec/sri-en-linea/SriVehiculosWeb/ConsultaValoresPagarVehiculos/Consultas/consultaRubros', {
            waitUntil: 'domcontentloaded',
            timeout: 25000
        });

        // 2. Ejecutamos las 2 llamadas encadenadas:
        //    Paso A -> Obtener codigoVehiculo con la placa
        //    Paso B -> Obtener los rubros a pagar con codigoVehiculo
        const resultadoSRI = await page.evaluate(async (placaBuscada) => {
            const baseRest = 'https://srienlinea.sri.gob.ec/sri-matriculacion-vehicular-recaudacion-servicio-internet/rest';

            // PASO A: Primera URL
            const urlVehiculo = `${baseRest}/BaseVehiculo/obtenerPorNumeroPlacaOPorNumeroCampvOPorNumeroCpn?numeroPlacaCampvCpn=${placaBuscada}`;
            const resVehiculo = await fetch(urlVehiculo, {
                method: 'GET',
                headers: { 'Accept': 'application/json, text/plain, */*' }
            });

            const textoVehiculo = await resVehiculo.text();
            let datosVehiculo = null;
            try {
                datosVehiculo = JSON.parse(textoVehiculo);
            } catch (e) {
                return { status: 'error', message: 'Fallo al leer BaseVehiculo', crudo: textoVehiculo.substring(0, 300) };
            }

            // Buscamos el codigoVehiculo sin importar si viene en la raíz o anidado
            const codigoVehiculo = datosVehiculo?.codigoVehiculo 
                                || datosVehiculo?.vehiculo?.codigoVehiculo 
                                || datosVehiculo?.id 
                                || null;

            if (!codigoVehiculo) {
                return {
                    status: 'error',
                    message: 'No se encontró codigoVehiculo en la primera respuesta',
                    vehiculo: datosVehiculo
                };
            }

            // PASO B: Segunda URL (La que trae los valores de matrícula)
            const urlRubros = `${baseRest}/ConsultaRubros/obtenerPorCodigoVehiculo?codigoVehiculo=${codigoVehiculo}`;
            const resRubros = await fetch(urlRubros, {
                method: 'GET',
                headers: { 'Accept': 'application/json, text/plain, */*' }
            });

            const statusRubros = resRubros.status;
            const textoRubros = await resRubros.text();
            let datosRubros = null;

            try {
                datosRubros = textoRubros ? JSON.parse(textoRubros) : [];
            } catch (e) {
                datosRubros = textoRubros;
            }

            // Calculamos la suma total si viene un arreglo de rubros
            let totalPagar = 0;
            if (Array.isArray(datosRubros)) {
                for (const r of datosRubros) {
                    const val = parseFloat(r.valorRubro ?? r.valor ?? r.total ?? r.valorTotal ?? 0);
                    if (!isNaN(val)) totalPagar += val;
                }
            }

            return {
                status: 'success',
                codigoVehiculo: codigoVehiculo,
                httpStatusRubros: statusRubros,
                total_calculado: Math.round(totalPagar * 100) / 100,
                rubros: datosRubros,
                vehiculo: datosVehiculo
            };
        }, placa);

        const jsonFinal = JSON.stringify(resultadoSRI, null, 2);

        if (archivoSalida) {
            fs.writeFileSync(archivoSalida, jsonFinal, 'utf8');
        } else {
            console.log(jsonFinal);
        }

    } catch (error) {
        const errOut = JSON.stringify({ status: 'error', message: error.message });
        if (archivoSalida) {
            fs.writeFileSync(archivoSalida, errOut, 'utf8');
        } else {
            console.log(errOut);
        }
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