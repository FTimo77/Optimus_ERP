const fs = require('fs');

const cedulaRaw = (process.argv[2] || '').replace(/[^0-9]/g, '').replace(/^(\d{10})001$/, '$1');
const archivoSalida = process.argv[3] || '';

if (!cedulaRaw) {
    console.log(JSON.stringify({ ok: false, error: 'Cédula vacía. Uso: node ./scripts/auditoria/supa.cjs 1804629366' }));
    process.exit(1);
}

const URL_SUPA = 'https://supa.funcionjudicial.gob.ec/pensiones/publico/consulta.jsf';
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

function limpiarTextoHtml(html) {
    return String(html || '')
        .replace(/<[^>]+>/g, ' ')
        .replace(/&nbsp;/gi, ' ')
        .replace(/&amp;/gi, '&')
        .replace(/&lt;/gi, '<')
        .replace(/&gt;/gi, '>')
        .replace(/&quot;/gi, '"')
        .replace(/&#39;/gi, "'")
        .replace(/\s+/g, ' ')
        .trim();
}

function parsearMonto(texto) {
    const limpio = String(texto || '').replace(/[^0-9.,-]/g, '').replace(',', '.');
    return parseFloat(limpio) || 0;
}

function extraerCdataUpdate(xml, idUpdate) {
    const escapedId = idUpdate.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const regex = new RegExp(`<update\\s+id=["']${escapedId}["']><!\\[CDATA\\[([\\s\\S]*?)\\]\\]><\\/update>`, 'i');
    const match = xml.match(regex);
    return match ? match[1] : '';
}

function extraerCeldasPrimerNivel(filaHtml) {
    const celdas = [];
    let profundidadTabla = 0;
    let inicioTd = -1;
    const tagRegex = /<\/?(?:table|td)\b[^>]*>/gi;
    let mTag;

    while ((mTag = tagRegex.exec(filaHtml)) !== null) {
        const tag = mTag[0].toLowerCase();
        if (tag.startsWith('<table')) {
            profundidadTabla++;
        } else if (tag.startsWith('</table')) {
            profundidadTabla = Math.max(0, profundidadTabla - 1);
        } else if (profundidadTabla === 0) {
            if (tag.startsWith('<td')) {
                inicioTd = mTag.index + mTag[0].length;
            } else if (tag.startsWith('</td') && inicioTd !== -1) {
                celdas.push(filaHtml.slice(inicioTd, mTag.index));
                inicioTd = -1;
            }
        }
    }

    return celdas;
}

function parsearTarjetasSUPA(htmlResultado) {
    const inicioTbody = htmlResultado.search(/<tbody[^>]*id=["']form:j_idt60_data["'][^>]*>/i);
    if (inicioTbody === -1) return [];

    const desdeTbody = htmlResultado.slice(inicioTbody);
    const bloquesFila = desdeTbody.split(/<tr[^>]*data-ri=["'](\d+)["'][^>]*>/i);
    const filas = [];

    for (let i = 1; i + 1 < bloquesFila.length; i += 2) {
        const indice = Number(bloquesFila[i]);
        const filaHtml = bloquesFila[i + 1];
        const celdas = extraerCeldasPrimerNivel(filaHtml);

        if (celdas.length < 5) continue;

        const intervinientes = {};
        const intRegex = /<tr[^>]*>\s*<td[^>]*>([\s\S]*?)<\/td>\s*<td[^>]*>([\s\S]*?)<\/td>\s*<\/tr>/gi;
        let mInt;
        while ((mInt = intRegex.exec(celdas[4])) !== null) {
            const etiqueta = limpiarTextoHtml(mInt[1]).replace(/:$/, '');
            const nombre = limpiarTextoHtml(mInt[2]);
            if (etiqueta && nombre) {
                intervinientes[etiqueta] = nombre;
            }
        }

        filas.push({
            indice,
            codigo_tarjeta: limpiarTextoHtml(celdas[0]),
            proceso_judicial: limpiarTextoHtml(celdas[1]),
            dependencia: limpiarTextoHtml(celdas[2]),
            tipo_pension: limpiarTextoHtml(celdas[3]),
            representante_legal: intervinientes['Representante Legal'] || '',
            obligado_principal: intervinientes['Obligado principal'] || ''
        });
    }

    return filas;
}

function parsearHistorialPagosSUPA(htmlDetalle) {
    const pensionMatch = htmlDetalle.match(/Pensión actual:<\/label><\/td>\s*<td[^>]*>([^<]+)<\/td>/i);
    const pensionActual = pensionMatch ? limpiarTextoHtml(pensionMatch[1]) : null;

    const tbodyMatch = htmlDetalle.match(/<tbody[^>]*id=["']form:j_idt113_data["'][^>]*>([\s\S]*?)<\/tbody>/i);
    if (!tbodyMatch) {
        return {
            pension_actual: pensionActual,
            total_movimientos: 0,
            pagos_acreditados: 0,
            pagos_pendientes: 0,
            saldo_pendiente: 0,
            al_dia: true,
            tiene_alerta: false,
            pendientes_detalle: []
        };
    }

    const bloques = tbodyMatch[1].split(/<tr[^>]*data-ri=["']\d+["'][^>]*>/i);
    let totalMovimientos = 0;
    let pagosAcreditados = 0;
    let pagosPendientes = 0;
    let saldoPendiente = 0;
    const pendientesDetalle = [];

    for (let i = 1; i < bloques.length; i++) {
        const celdas = extraerCeldasPrimerNivel(bloques[i]);
        if (celdas.length < 9) continue;

        totalMovimientos++;
        const fechaDeuda = limpiarTextoHtml(celdas[1]);
        const anio = limpiarTextoHtml(celdas[2]);
        const mes = limpiarTextoHtml(celdas[3]);
        const concepto = limpiarTextoHtml(celdas[4]);
        const estado = limpiarTextoHtml(celdas[5]);
        const valorDeuda = parsearMonto(limpiarTextoHtml(celdas[6]));
        const valorPagado = parsearMonto(limpiarTextoHtml(celdas[7]));
        const transaccion = limpiarTextoHtml(celdas[8]);

        const esAcreditado = /acreditado/i.test(transaccion)
            || (/pagado/i.test(estado) && valorPagado >= valorDeuda && valorDeuda > 0);

        if (esAcreditado) {
            pagosAcreditados++;
        } else {
            pagosPendientes++;
            const diferencia = Math.max(0, valorDeuda - valorPagado);
            saldoPendiente += diferencia;
            pendientesDetalle.push({
                fecha_deuda: fechaDeuda,
                anio,
                mes,
                concepto,
                estado: estado || transaccion || 'Pendiente',
                valor_deuda: Number(valorDeuda.toFixed(2)),
                valor_pagado: Number(valorPagado.toFixed(2)),
                saldo: Number(diferencia.toFixed(2))
            });
        }
    }

    return {
        pension_actual: pensionActual,
        total_movimientos: totalMovimientos,
        pagos_acreditados: pagosAcreditados,
        pagos_pendientes: pagosPendientes,
        saldo_pendiente: Number(saldoPendiente.toFixed(2)),
        al_dia: pagosPendientes === 0,
        tiene_alerta: pagosPendientes >= 3,
        pendientes_detalle: pendientesDetalle
    };
}

async function consultarPensionesSUPA(cedulaBuscada) {
    const limpia = String(cedulaBuscada || '')
        .replace(/[^0-9]/g, '')
        .replace(/^(\d{10})001$/, '$1');

    if (limpia.length !== 10 && limpia.length !== 13) {
        return {
            ok: false,
            fuente: 'SUPA',
            consultado: false,
            cedula: limpia,
            tiene_pensiones: false,
            tiene_alerta: false,
            pagos_pendientes: 0,
            saldo_pendiente: 0,
            cantidad: 0,
            mensaje: 'Cédula no detectada o inválida para consulta en SUPA.',
            listado: []
        };
    }

    try {
        // 1. GET inicial para obtener JSESSIONID, action exacto y javax.faces.ViewState
        const resGet = await fetch(URL_SUPA, {
            method: 'GET',
            headers: {
                'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'User-Agent': UA
            },
            signal: AbortSignal.timeout(15000)
        });

        if (!resGet.ok) {
            return {
                ok: false,
                fuente: 'SUPA',
                consultado: true,
                cedula: limpia,
                tiene_pensiones: false,
                tiene_alerta: false,
                pagos_pendientes: 0,
                saldo_pendiente: 0,
                cantidad: 0,
                listado: [],
                error: `HTTP ${resGet.status}`
            };
        }

        const htmlInicial = await resGet.text();
        const rawCookies = resGet.headers.getSetCookie ? resGet.headers.getSetCookie() : [resGet.headers.get('set-cookie') || ''];
        const cookieHeader = rawCookies
            .map(c => c.split(';')[0].trim())
            .filter(Boolean)
            .join('; ');

        const viewStateMatch = htmlInicial.match(/name=["']javax\.faces\.ViewState["'][^>]*value=["']([^"']+)["']/i)
            || htmlInicial.match(/value=["']([^"']+)["'][^>]*name=["']javax\.faces\.ViewState["']/i);
        const actionMatch = htmlInicial.match(/<form[^>]*id=["']form["'][^>]*action=["']([^"']+)["']/i);
        const urlPost = actionMatch
            ? (actionMatch[1].startsWith('http') ? actionMatch[1] : `https://supa.funcionjudicial.gob.ec${actionMatch[1]}`)
            : URL_SUPA;

        if (!viewStateMatch) {
            return {
                ok: false,
                fuente: 'SUPA',
                consultado: true,
                cedula: limpia,
                tiene_pensiones: false,
                tiene_alerta: false,
                pagos_pendientes: 0,
                saldo_pendiente: 0,
                cantidad: 0,
                listado: [],
                error: 'No se encontró javax.faces.ViewState en SUPA.'
            };
        }

        let viewState = viewStateMatch[1];

        const postPrime = async (params) => {
            const res = await fetch(urlPost, {
                method: 'POST',
                headers: {
                    'Accept': 'application/xml, text/xml, */*; q=0.01',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Faces-Request': 'partial/ajax',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Origin': 'https://supa.funcionjudicial.gob.ec',
                    'Referer': URL_SUPA,
                    'User-Agent': UA,
                    ...(cookieHeader ? { 'Cookie': cookieHeader } : {})
                },
                body: new URLSearchParams({
                    'javax.faces.partial.ajax': 'true',
                    'form': 'form',
                    'form:t_texto_cedula': limpia,
                    'form:s_criterio_busqueda': 'Seleccione...',
                    'form:t_texto': '',
                    'javax.faces.ViewState': viewState,
                    ...params
                }).toString(),
                signal: AbortSignal.timeout(15000)
            });

            if (!res.ok) {
                throw new Error(`HTTP POST ${res.status}`);
            }

            const xml = await res.text();
            const nuevoVs = extraerCdataUpdate(xml, 'javax.faces.ViewState');
            if (nuevoVs) {
                viewState = nuevoVs;
            }
            return xml;
        };

        // 2. Búsqueda por cédula
        const xmlBusqueda = await postPrime({
            'javax.faces.source': 'form:b_buscar_cedula',
            'javax.faces.partial.execute': '@all',
            'javax.faces.partial.render': 'form:pResultado panelMensajes form:pFiltro',
            'form:b_buscar_cedula': 'form:b_buscar_cedula'
        });

        const htmlResultado = extraerCdataUpdate(xmlBusqueda, 'form:pResultado');
        const htmlMensajes = extraerCdataUpdate(xmlBusqueda, 'panelMensajes');
        const tarjetas = parsearTarjetasSUPA(htmlResultado);

        // 3. Para cada tarjeta, cargamos el detalle dinámico (form:dDetalle) e inspeccionamos el historial de pagos
        let totalPagosPendientes = 0;
        let maxPagosPendientesTarjeta = 0;
        let saldoPendienteTotal = 0;
        const listado = [];

        for (const tarjeta of tarjetas) {
            const btnId = `form:j_idt60:${tarjeta.indice}:j_idt80`;
            await postPrime({
                'javax.faces.source': btnId,
                'javax.faces.partial.execute': '@all',
                'javax.faces.partial.render': 'form:dDetalle',
                [btnId]: btnId
            });

            const xmlDetalle = await postPrime({
                'javax.faces.source': 'form:dDetalle',
                'javax.faces.partial.execute': 'form:dDetalle',
                'javax.faces.partial.render': 'form:dDetalle',
                'form:dDetalle_contentLoad': 'true'
            });

            const htmlDetalle = extraerCdataUpdate(xmlDetalle, 'form:dDetalle');
            const historial = parsearHistorialPagosSUPA(htmlDetalle);

            totalPagosPendientes += historial.pagos_pendientes;
            maxPagosPendientesTarjeta = Math.max(maxPagosPendientesTarjeta, historial.pagos_pendientes);
            saldoPendienteTotal += historial.saldo_pendiente;

            listado.push({
                ...tarjeta,
                ...historial
            });
        }

        const tieneAlerta = listado.some(t => t.tiene_alerta) || totalPagosPendientes >= 3;
        const mensajeBase = limpiarTextoHtml(htmlMensajes);
        let mensaje = mensajeBase;

        if (!mensaje) {
            if (listado.length === 0) {
                mensaje = 'Sin tarjetas registradas en SUPA.';
            } else if (tieneAlerta) {
                mensaje = `¡Alerta! Se registran ${totalPagosPendientes} pago(s) pendiente(s) en SUPA (3 o más sin acreditar).`;
            } else if (totalPagosPendientes === 0) {
                mensaje = `Pagos al día (${listado.length} tarjeta(s) verificada(s), todo acreditado).`;
            } else {
                mensaje = `${totalPagosPendientes} pago(s) pendiente(s) en SUPA (por debajo del umbral de alerta de 3).`;
            }
        }

        return {
            ok: true,
            fuente: 'SUPA',
            consultado: true,
            cedula: limpia,
            tiene_pensiones: listado.length > 0,
            tiene_alerta: tieneAlerta,
            pagos_pendientes: totalPagosPendientes,
            max_pagos_pendientes_tarjeta: maxPagosPendientesTarjeta,
            saldo_pendiente: Number(saldoPendienteTotal.toFixed(2)),
            cantidad: listado.length,
            mensaje,
            listado
        };
    } catch (e) {
        return {
            ok: false,
            fuente: 'SUPA',
            consultado: true,
            cedula: limpia,
            tiene_pensiones: false,
            tiene_alerta: false,
            pagos_pendientes: 0,
            saldo_pendiente: 0,
            cantidad: 0,
            listado: [],
            error: e.message
        };
    }
}

if (require.main === module) {
    (async () => {
        const resultado = await consultarPensionesSUPA(cedulaRaw);
        const salida = JSON.stringify(resultado, null, 2);
        if (archivoSalida) {
            fs.writeFileSync(archivoSalida, salida, 'utf8');
        } else {
            process.stdout.write(salida + '\n');
        }
    })();
}

module.exports = { consultarPensionesSUPA };
