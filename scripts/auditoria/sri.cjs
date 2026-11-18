// Consulta de matrícula, rubros pendientes y transferencias de dominio en el SRI
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

module.exports = {
    consultarSRI
};
