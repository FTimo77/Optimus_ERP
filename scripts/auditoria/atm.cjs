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

function procesarFilasJqGridATM(jsonAtm, placaBuscada) {
    if (!jsonAtm) {
        return {
            ok: false,
            fuente: 'ATM',
            placa: placaBuscada,
            tiene_multas: false,
            cantidad: 0,
            total: 0,
            listado: [],
            error: 'Formato JSON inválido de ATM'
        };
    }

    // Soportamos array directo o diferentes claves que devuelve Axis PortalWEB ({ rows }, { data }, { citaciones })
    let filas = null;
    if (Array.isArray(jsonAtm)) {
        filas = jsonAtm;
    } else if (Array.isArray(jsonAtm.rows)) {
        filas = jsonAtm.rows;
    } else if (Array.isArray(jsonAtm.data)) {
        filas = jsonAtm.data;
    } else if (Array.isArray(jsonAtm.citaciones)) {
        filas = jsonAtm.citaciones;
    } else if (
        jsonAtm.rows === null
        || jsonAtm.rows === undefined
        || Number(jsonAtm.records ?? 0) === 0
    ) {
        // Cuando no hay citaciones, algunos endpoints jqGrid omiten "rows" o lo envían null con records: 0
        if (jsonAtm.records !== undefined || jsonAtm.total !== undefined || jsonAtm.page !== undefined) {
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
            error: jsonAtm.lsError || jsonAtm.error || jsonAtm.message || 'Formato JSON inválido de ATM'
        };
    }

    let totalDeuda = 0;
    const listado = [];

    for (const row of filas) {
        // En jqGrid de Axis, cada elemento de rows puede venir como { id: "...", cell: [...] } o como objeto directo
        const c = Array.isArray(row.cell) ? row.cell : null;

        if (c) {
            // Estructura estándar de columnas en clp_json_citaciones.jsp de PortalWEB Axis:
            // Buscamos los valores numéricos con decimales o columnas típicas (boleta, fecha, valor, total)
            const boleta = String(c[1] || c[0] || row.id || 'S/N').replace(/<[^>]+>/g, '').trim();
            const infraccion = String(c[2] || '').replace(/<[^>]+>/g, '').trim();
            const entidad = String(c[3] || 'ATM').replace(/<[^>]+>/g, '').trim();
            const fecha = String(c[4] || c[5] || '').replace(/<[^>]+>/g, '').trim();

            // En jqGrid de citaciones, las últimas columnas numéricas suelen ser: Valor Multa, Remisión/Interés, Total a Pagar
            // Buscamos el monto a pagar en las columnas numéricas (usualmente índice 8, 9, 10 o 11)
            let monto = 0;
            const posiblesIndicesMonto = [10, 9, 8, 7, 11, 6];
            for (const idx of posiblesIndicesMonto) {
                if (c[idx] !== undefined && c[idx] !== null && String(c[idx]).trim() !== '') {
                    const limpio = String(c[idx]).replace(/[^0-9.,-]/g, '').replace(',', '.');
                    if (/^\d+(\.\d+)?$/.test(limpio)) {
                        const val = parseFloat(limpio);
                        if (val > 0) {
                            monto = val;
                            break;
                        }
                    }
                }
            }

            const RubroDescripcion = String(c[c.length - 1] || c[12] || infraccion || 'Citación ATM').replace(/<[^>]+>/g, '').trim();

            totalDeuda += monto;
            listado.push({
                citacion: boleta,
                fecha,
                entidad,
                concepto: RubroDescripcion,
                total: Number(monto.toFixed(2)),
                raw_cell: c
            });
        } else if (row && typeof row === 'object') {
            const monto = parseFloat(String(row.total ?? row.valor ?? row.saldo ?? 0).replace(',', '.')) || 0;
            totalDeuda += monto;
            listado.push({
                citacion: row.citacion || row.boleta || row.id || 'S/N',
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
        cantidad: Number(jsonAtm.records ?? listado.length),
        total: Number(totalDeuda.toFixed(2)),
        listado
    };
}

async function consultarMultasATM(placaBuscada) {
    const baseUrl = 'https://consultas.atm.gob.ec/PortalWEB/paginas/clientes';
    const ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    try {
        // PASO 1: Entramos a clp_grid_citaciones.jsp para obtener la cookie JSESSIONID y el ps_id_persona de la placa
        const urlGrid = `${baseUrl}/clp_grid_citaciones.jsp?ps_tipo_identificacion=PLA&ps_identificacion=${placaBuscada}&ps_placa=${placaBuscada}`;

        const resGrid = await fetch(urlGrid, {
            method: 'GET',
            headers: {
                'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'User-Agent': ua
            },
            signal: AbortSignal.timeout(12000)
        });

        if (!resGrid.ok) {
            return {
                ok: false,
                fuente: 'ATM',
                placa: placaBuscada,
                tiene_multas: false,
                cantidad: 0,
                total: 0,
                listado: [],
                error: `ATM respondió HTTP ${resGrid.status} al cargar la consulta.`
            };
        }

        // Capturamos la cookie JSESSIONID
        const rawCookies = resGrid.headers.getSetCookie ? resGrid.headers.getSetCookie() : [resGrid.headers.get('set-cookie') || ''];
        const cookieHeader = rawCookies
            .map(c => c.split(';')[0])
            .filter(Boolean)
            .join('; ');

        const htmlGrid = await resGrid.text();

        // Extraemos ps_id_persona generado por el JSP para esta placa (ej. ps_id_persona=5456649)
        const matchIdPersona = htmlGrid.match(/ps_id_persona=([0-9]+)/i)
            || htmlGrid.match(/name=["']ps_id_persona["'][^>]*value=["']([0-9]+)["']/i);
        const idPersona = matchIdPersona ? matchIdPersona[1] : '';

        const patronSinRegistros = /no\s+(?:existen|registra|tiene|se\s+encontraron)\s+(?:citaciones|infracciones|registros|datos|multas)|persona\s+no\s+encontrada|veh[ií]culo\s+no\s+registrado/i;

        // Si el portal ATM no generó ps_id_persona y reporta que no existen registros, el vehículo está limpio en ATM
        if (!idPersona && patronSinRegistros.test(htmlGrid)) {
            return respuestaLimpiaATM(placaBuscada, null);
        }

        // PASO 2: Consultamos clp_json_citaciones.jsp con ps_opcion=P (Pendientes)
        const nd = Date.now();
        const urlJson = `${baseUrl}/clp_json_citaciones.jsp?ps_opcion=P&ps_id_persona=${idPersona}&ps_placa=${placaBuscada}&ps_identificacion=${placaBuscada}&ps_tipo_identificacion=PLA&_search=false&nd=${nd}&rows=50&page=1&sidx=fecha_emision&sord=desc`;

        const resJson = await fetch(urlJson, {
            method: 'GET',
            headers: {
                'Accept': 'application/json, text/javascript, */*; q=0.01',
                'X-Requested-With': 'XMLHttpRequest',
                'Referer': urlGrid,
                'User-Agent': ua,
                ...(cookieHeader ? { 'Cookie': cookieHeader } : {})
            },
            signal: AbortSignal.timeout(12000)
        });

        const txtJson = await resJson.text();
        const trimmed = (txtJson || '').trim();

        // Caso 1: Respuesta vacía o arreglo vacío cuando la placa no tiene citaciones en ATM (muy común si ps_id_persona está vacío)
        if (resJson.ok && (trimmed === '' || trimmed === '[]' || trimmed === '{}' || trimmed === 'null')) {
            return respuestaLimpiaATM(placaBuscada, idPersona);
        }

        // Caso 2: Mensaje HTML/texto indicando explícitamente ausencia de citaciones
        if (resJson.ok && patronSinRegistros.test(trimmed)) {
            return respuestaLimpiaATM(placaBuscada, idPersona);
        }

        // Caso 3: Respuesta XML de jqGrid (<rows><records>0</records></rows>)
        if (trimmed.startsWith('<') && /<rows\b/i.test(trimmed)) {
            const matchRecords = trimmed.match(/<records>\s*(\d+)\s*<\/records>/i);
            const totalRecords = matchRecords ? parseInt(matchRecords[1], 10) : 0;
            if (totalRecords === 0) {
                return respuestaLimpiaATM(placaBuscada, idPersona);
            }
        }

        // Caso 4: Bloque JSON (objeto {...} o arreglo [...])
        const inicioObj = trimmed.indexOf('{');
        const finObj = trimmed.lastIndexOf('}');
        const inicioArr = trimmed.indexOf('[');
        const finArr = trimmed.lastIndexOf(']');

        let bloqueJson = null;
        if (inicioObj !== -1 && finObj > inicioObj && (inicioArr === -1 || inicioObj < inicioArr)) {
            bloqueJson = trimmed.slice(inicioObj, finObj + 1);
        } else if (inicioArr !== -1 && finArr > inicioArr) {
            bloqueJson = trimmed.slice(inicioArr, finArr + 1);
        }

        if (!bloqueJson) {
            // Si no hubo idPersona en ATM y devolvió una página/respuesta sin JSON ni error HTTP, la placa no tiene historial en ATM
            if (resJson.ok && !idPersona && !/exception|error\s+500|tomcat/i.test(trimmed)) {
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
                error: 'ATM no devolvió un bloque JSON válido',
                debug_http_status: resJson.status,
                debug_id_persona: idPersona || null,
                debug_muestra: trimmed.slice(0, 200)
            };
        }

        const parsed = JSON.parse(bloqueJson);
        const resultado = procesarFilasJqGridATM(parsed, placaBuscada);
        resultado.id_persona_atm = idPersona || null;
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