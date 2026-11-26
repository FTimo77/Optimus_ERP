const fs = require('fs');

const placaRaw = (process.argv[2] || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
const archivoSalida = process.argv[3] || '';

if (!placaRaw) {
    console.log(JSON.stringify({ ok: false, error: 'Placa vacía. Uso: node ./scripts/auditoria/atm.cjs GBN6829' }));
    process.exit(1);
}

const placa = /^([A-Z]{3})(\d{3})$/.test(placaRaw)
    ? placaRaw.replace(/^([A-Z]{3})(\d{3})$/, '$10$2')
    : placaRaw;

function respuestaLimpiaATM(placaBuscada, idPersona, mensaje = 'Sin citaciones pendientes en ATM (Guayaquil)') {
    return {
        ok: true,
        fuente: 'ATM',
        placa: placaBuscada,
        tiene_multas: false,
        cantidad: 0,
        total: 0,
        listado: [],
        mensaje,
        id_persona_atm: idPersona || null
    };
}

function parsearMontoCelda(valor) {
    if (valor === undefined || valor === null) return null;
    const limpio = String(valor).replace(/<[^>]+>/g, '').replace(/[^0-9.,-]/g, '').replace(',', '.').trim();
    if (!/^-?\d+(\.\d+)?$/.test(limpio)) return null;
    const num = parseFloat(limpio);
    return Number.isFinite(num) ? num : null;
}

function extraerIdPersonaDeRespuesta(texto) {
    if (!texto) return '';
    const patrones = [
        /"ps_id_persona"\s*:\s*"?([0-9]{4,})"?/i,
        /"id_persona"\s*:\s*"?([0-9]{4,})"?/i,
        /"idPersona"\s*:\s*"?([0-9]{4,})"?/i,
        /"codigo"\s*:\s*"?([0-9]{4,})"?/i,
        /"id"\s*:\s*"?([0-9]{5,})"?/i,
        /ps_id_persona\s*=\s*["']?([0-9]{4,})["']?/i,
        /value\s*[:=]\s*["']?([0-9]{5,})["']?/i
    ];
    for (const rx of patrones) {
        const m = texto.match(rx);
        if (m && m[1]) return m[1];
    }
    return '';
}

function procesarFilasJqGridATM(jsonAtm, placaBuscada, soloPlacaExacta = true) {
    let filas = null;
    if (Array.isArray(jsonAtm)) {
        filas = jsonAtm;
    } else if (Array.isArray(jsonAtm?.rows)) {
        filas = jsonAtm.rows;
    } else if (Array.isArray(jsonAtm?.data)) {
        filas = jsonAtm.data;
    } else if (Array.isArray(jsonAtm?.citaciones)) {
        filas = jsonAtm.citaciones;
    } else if (
        jsonAtm?.rows === null
        || jsonAtm?.rows === undefined
        || Number(jsonAtm?.records ?? 0) === 0
    ) {
        if (jsonAtm?.records !== undefined || jsonAtm?.total !== undefined || jsonAtm?.page !== undefined) {
            filas = [];
        }
    }

    if (!Array.isArray(filas)) {
        return {
            ok: false,
            fuente: 'ATM',
            placa: placaBuscada,
            tiene_multas: false,
            cantidad: 0,
            total: 0,
            listado: [],
            error: jsonAtm?.lsError || jsonAtm?.error || jsonAtm?.message || 'Formato JSON inválido de ATM'
        };
    }

    let totalDeuda = 0;
    const listado = [];
    const otrasPlacasPropietario = [];

    for (const row of filas) {
        const c = Array.isArray(row.cell) ? row.cell : null;

        if (c) {
            const limpiar = (v) => String(v ?? '').replace(/<[^>]+>/g, '').trim();
            const esFormato21Cols = c.length >= 19;
            const placaFila = esFormato21Cols ? limpiar(c[3]).toUpperCase() : placaBuscada;

            if (soloPlacaExacta && placaFila && placaFila !== placaBuscada) {
                if (!otrasPlacasPropietario.includes(placaFila)) {
                    otrasPlacasPropietario.push(placaFila);
                }
                continue;
            }

            const boleta = limpiar(c[1] || c[0] || row.id || 'S/N');
            const codigoInfraccion = limpiar(c[2]);
            const propietario = esFormato21Cols ? limpiar(c[4]) : '';
            const fecha = esFormato21Cols ? limpiar(c[5] || c[6]) : limpiar(c[4] || c[5]);
            const entidad = esFormato21Cols ? (limpiar(c[7]) || 'ATM') : (limpiar(c[3]) || 'ATM');

            let monto = 0;
            const indicesMonto = esFormato21Cols
                ? [17, 15, 12, 13, 14, 16]
                : [10, 9, 8, 7, 11, 6];

            for (const idx of indicesMonto) {
                const val = parsearMontoCelda(c[idx]);
                if (val !== null && val > 0) {
                    monto = val;
                    break;
                }
            }

            const valorBase = esFormato21Cols ? (parsearMontoCelda(c[12]) ?? monto) : monto;
            let concepto = esFormato21Cols ? limpiar(c[18]) : '';
            if (!concepto) {
                const textos = c
                    .map(limpiar)
                    .filter(t => t.length > 12 && !/^\d{4}-\d{2}-\d{2}/.test(t) && !/AUTORIDAD DE TRANSITO/i.test(t));
                concepto = textos[textos.length - 1] || codigoInfraccion || 'Citación ATM';
            }

            totalDeuda += monto;
            listado.push({
                citacion: boleta,
                codigo_infraccion: codigoInfraccion,
                placa: placaFila || placaBuscada,
                propietario,
                fecha,
                entidad,
                concepto,
                valor_base: Number(valorBase.toFixed(2)),
                total: Number(monto.toFixed(2))
            });
        } else if (row && typeof row === 'object') {
            const monto = parseFloat(String(row.total ?? row.valor ?? row.saldo ?? 0).replace(',', '.')) || 0;
            totalDeuda += monto;
            listado.push({
                citacion: row.citacion || row.boleta || row.id || 'S/N',
                placa: row.placa || placaBuscada,
                fecha: row.fecha_emision || row.fecha || '',
                entidad: 'ATM',
                concepto: row.observacion || row.rubro || row.articulo || 'Citación ATM',
                total: Number(monto.toFixed(2))
            });
        }
    }

    return {
        ok: true,
        fuente: 'ATM',
        placa: placaBuscada,
        tiene_multas: listado.length > 0,
        cantidad: listado.length,
        total: Number(totalDeuda.toFixed(2)),
        listado,
        ...(otrasPlacasPropietario.length > 0 ? { otras_placas_mismo_dueno: otrasPlacasPropietario } : {})
    };
}

async function consultarMultasATM(placaBuscada) {
    const baseUrl = 'https://consultas.atm.gob.ec/PortalWEB/paginas/clientes';
    const ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    const cookiesMap = new Map();
    const guardarCookies = (res) => {
        const arr = res.headers.getSetCookie ? res.headers.getSetCookie() : [res.headers.get('set-cookie') || ''];
        for (const c of arr) {
            const par = c.split(';')[0].trim();
            if (par && par.includes('=')) {
                const [k, ...v] = par.split('=');
                cookiesMap.set(k.trim(), v.join('='));
            }
        }
    };
    const getCookieHeader = () => Array.from(cookiesMap.entries()).map(([k, v]) => `${k}=${v}`).join('; ');

    try {
        // PASO 1: Iniciar sesión en clp_criterio_consulta.jsp
        const urlCriterio = `${baseUrl}/clp_criterio_consulta.jsp`;
        const resCriterio = await fetch(urlCriterio, {
            method: 'GET',
            headers: {
                'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'User-Agent': ua
            },
            signal: AbortSignal.timeout(12000)
        });
        guardarCookies(resCriterio);

        // PASO 2: Llamar al AJAX clp_json_consulta_persona.jsp (el que obtiene ps_id_persona y autoriza la sesión)
        const urlPersona = `${baseUrl}/clp_json_consulta_persona.jsp?ps_tipo_identificacion=PLA&ps_identificacion=${placaBuscada}&ps_persona=`;
        const resPersona = await fetch(urlPersona, {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/javascript, */*; q=0.01',
                'X-Requested-With': 'XMLHttpRequest',
                'Origin': 'https://consultas.atm.gob.ec',
                'Referer': urlCriterio,
                'User-Agent': ua,
                ...(getCookieHeader() ? { 'Cookie': getCookieHeader() } : {})
            },
            signal: AbortSignal.timeout(12000)
        });
        guardarCookies(resPersona);
        const txtPersona = (await resPersona.text()).trim();
        const idPersona = extraerIdPersonaDeRespuesta(txtPersona);

        if (!idPersona) {
            if (/no\s+(?:existen|registra|encontr)/i.test(txtPersona) || txtPersona === '[]' || txtPersona === '{}') {
                return respuestaLimpiaATM(placaBuscada, null);
            }
            return {
                ok: false,
                fuente: 'ATM',
                placa: placaBuscada,
                tiene_multas: false,
                cantidad: 0,
                total: 0,
                listado: [],
                error: 'No se obtuvo ps_id_persona desde clp_json_consulta_persona.jsp',
                debug_persona: txtPersona.slice(0, 350)
            };
        }

        // PASO 3: Enviar el submit POST a clp_grid_citaciones.jsp con ps_id_persona para habilitar el grid en sesión
        const urlGrid = `${baseUrl}/clp_grid_citaciones.jsp`;
        const bodyGrid = new URLSearchParams({
            pstipo_identificacion_: 'PLA',
            psidentificacion_: placaBuscada,
            pspersona_: idPersona,
            ps_tipo_identificacion: 'PLA',
            ps_identificacion: placaBuscada,
            ps_placa: placaBuscada,
            ps_id_persona: idPersona
        });

        const resGrid = await fetch(urlGrid, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Origin': 'https://consultas.atm.gob.ec',
                'Referer': urlCriterio,
                'User-Agent': ua,
                ...(getCookieHeader() ? { 'Cookie': getCookieHeader() } : {})
            },
            body: bodyGrid.toString(),
            signal: AbortSignal.timeout(12000)
        });
        guardarCookies(resGrid);

        // PASO 4: Consultar clp_json_citaciones.jsp con ps_id_persona y la sesión autorizada
        const nd = Date.now();
        const urlJson = `${baseUrl}/clp_json_citaciones.jsp?ps_opcion=P&ps_id_persona=${idPersona}&ps_placa=${placaBuscada}&ps_identificacion=${placaBuscada}&ps_tipo_identificacion=PLA&_search=false&nd=${nd}&rows=50&page=1&sidx=fecha_emision&sord=desc`;

        const resJson = await fetch(urlJson, {
            method: 'GET',
            headers: {
                'Accept': 'application/json, text/javascript, */*; q=0.01',
                'X-Requested-With': 'XMLHttpRequest',
                'Referer': urlGrid,
                'User-Agent': ua,
                ...(getCookieHeader() ? { 'Cookie': getCookieHeader() } : {})
            },
            signal: AbortSignal.timeout(12000)
        });

        const txtJson = (await resJson.text()).trim();
        if (resJson.ok && (txtJson === '' || txtJson === '[]' || txtJson === '{}' || txtJson === 'null')) {
            return respuestaLimpiaATM(placaBuscada, idPersona);
        }

        const inicioObj = txtJson.indexOf('{');
        const finObj = txtJson.lastIndexOf('}');
        if (inicioObj === -1 || finObj <= inicioObj) {
            return {
                ok: false,
                fuente: 'ATM',
                placa: placaBuscada,
                tiene_multas: false,
                cantidad: 0,
                total: 0,
                listado: [],
                id_persona_atm: idPersona,
                error: 'ATM no devolvió JSON en clp_json_citaciones.jsp',
                debug_json: txtJson.slice(0, 250)
            };
        }

        const parsed = JSON.parse(txtJson.slice(inicioObj, finObj + 1));
        const resultado = procesarFilasJqGridATM(parsed, placaBuscada, true);
        resultado.id_persona_atm = idPersona;
        return resultado;

    } catch (e) {
        return {
            ok: false,
            fuente: 'ATM',
            placa: placaBuscada,
            tiene_multas: false,
            cantidad: 0,
            total: 0,
            listado: [],
            error: e.message
        };
    }
}

if (require.main === module) {
    (async () => {
        const resultado = await consultarMultasATM(placa);
        const salida = JSON.stringify(resultado, null, 2);
        if (archivoSalida) {
            fs.writeFileSync(archivoSalida, salida, 'utf8');
        } else {
            process.stdout.write(salida + '\n');
        }
    })();
}

module.exports = { consultarMultasATM };