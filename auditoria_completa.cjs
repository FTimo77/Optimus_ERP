const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

const { consultarPropietario } = require('./scripts/auditoria/propietario.cjs');
const { consultarMultas } = require('./scripts/auditoria/multas.cjs');
const { consultarSRI } = require('./scripts/auditoria/sri.cjs');
const { consultarAxis } = require('./scripts/auditoria/axis.cjs');
const { consultarFiscalia, consultarFiscaliaCedula } = require('./scripts/auditoria/fiscalia.cjs');
const { consultarMultasAMT} = require('./scripts/auditoria/amt.cjs');
const {consultarMultasCTE} = require('./scripts/auditoria/cte.cjs');
const { consultarMultasATM } = require('./scripts/auditoria/atm.cjs');
const { consultarPensionesSUPA } = require('./scripts/auditoria/supa.cjs');
const { consultarCausasJudiciales } = require('./scripts/auditoria/satje.cjs');

process.env.PUPPETEER_CACHE_DIR = process.env.PUPPETEER_CACHE_DIR || 'C:\\Users\\LENOVO\\.cache\\puppeteer';

const placaRaw = (process.argv[2] || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
const arg3Raw = (process.argv[3] || '').trim().replace(/^["']+|["']+$/g, '');
const arg4Raw = (process.argv[4] || '').trim().replace(/^["']+|["']+$/g, '');

// En PowerShell, pasar "" puede eliminarse como argumento y desplazar la cédula a argv[3]
const arg3EsCedula = arg4Raw === '' && /^\d{10}(\d{3})?$/.test(arg3Raw);
const archivoSalida = arg3EsCedula ? '' : arg3Raw;
const cedula = (arg3EsCedula ? arg3Raw : arg4Raw)
    .replace(/[^0-9]/g, '')
    .replace(/^(\d{10})001$/, '$1');

if (!placaRaw) {
    console.log(JSON.stringify({ status: 'error', message: 'Placa vacía' }));
    process.exit(1);
}

const placa = /^([A-Z]{3})(\d{3})$/.test(placaRaw)
    ? placaRaw.replace(/^([A-Z]{3})(\d{3})$/, '$10$2')
    : placaRaw;

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

(async () => {
    let browser;
    try {
        const opcionesBrowser = {
            headless: 'new',
            args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--disable-blink-features=AutomationControlled']
        };
        const exe = obtenerRutaNavegadorWindows();
        if (exe) opcionesBrowser.executablePath = exe;

        browser = await puppeteer.launch(opcionesBrowser);

        const capturarFallo = (fuente, defecto = {}) => (err) => ({
            ok: false,
            fuente,
            ...defecto,
            error: err?.message || `Error inesperado al consultar ${fuente}`
        });

        // CTE (/AutoServicio) es estricto con el límite de sesiones en servicios.axiscloud.ec,
        // mientras que AxisCloud (/CRV) no. Ejecutar CTE PRIMERO y luego Axis evita el error
        // "Ha excedido la cantidad de sesiones permitidas".
        const consultarCteYAxisEnSerie = async () => {
            const resCte = await consultarMultasCTE(browser, placa)
                .catch(capturarFallo('CTE', { placa, tiene_multas: false, cantidad: 0, total: 0, listado: [] }));
            const resAxis = await consultarAxis(browser, placa)
                .catch(err => ({ error_script: err?.message || 'Error en AxisCloud' }));
            return [resAxis, resCte];
        };

        const [propietario, multas, sri, [axis, cte], fiscalia, fiscalia_cedula, amt, atm, supa, satje] = await Promise.all([
            consultarPropietario(placa).catch(capturarFallo('Propietario', { nombre: '' })),
            consultarMultas(browser, placa, rutaTokenCache).catch(err => ({ status: 'error', data: [], error: err?.message || 'Error en Autority' })),
            consultarSRI(browser, placa).catch(capturarFallo('SRI', { vehiculo: null, rubros: [], total_transferencias: 0 })),
            consultarCteYAxisEnSerie(),
            consultarFiscalia(browser, placa).catch(capturarFallo('Fiscalía', { tiene_denuncias: false, cantidad: 0, listado: [] })),
            consultarFiscaliaCedula(browser, cedula).catch(capturarFallo('Fiscalía Cédula', { consultado: false, cedula, tiene_denuncias: false, cantidad: 0, total_procesos_vista: 0, procesos_otros_roles: 0, listado: [] })),
            consultarMultasAMT(placa).catch(capturarFallo('AMT', { placa, tiene_multas: false, cantidad: 0, total: 0, listado: [] })),
            consultarMultasATM(placa).catch(capturarFallo('ATM', { placa, tiene_multas: false, cantidad: 0, total: 0, listado: [] })),
            consultarPensionesSUPA(cedula).catch(capturarFallo('SUPA', { consultado: false, cedula, tiene_pensiones: false, tiene_alerta: false, pagos_pendientes: 0, saldo_pendiente: 0, cantidad: 0, listado: [] })),
            consultarCausasJudiciales(cedula).catch(capturarFallo('SATJE', { consultado: false, cedula, tiene_causas: false, cantidad_demandado: 0, cantidad_actor: 0, cantidad: 0, listado: [] }))
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
            fiscalia_cedula,
            amt,
            cte, 
            atm,
            supa,
            satje
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
