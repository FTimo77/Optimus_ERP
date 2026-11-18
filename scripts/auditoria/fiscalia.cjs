const URL_AJX_FISCALIA = 'https://www.gestiondefiscalias.gob.ec/siaf/sitio/consulta_ndd_ext/AJX_consulta_noticiasdelito.php';
const URL_REDIRECT_FISCALIA = 'https://www.gestiondefiscalias.gob.ec/siaf/sitio/consulta_ndd_ext/redirect.php?data=L3Zhci93d3cvaHRtbC9zaWFmL2luZm9ybWFjaW9uL3dlYi9ub3RpY2lhc2RlbGl0by8uLi8uLi8uLi9zaXRpby9jb25zdWx0YV9uZGRfZXh0L2NvbnN1bHRhX2NpdWRhZGFuYS5waHA%3D';

async function llamarAjaxFiscalia(browser, criterio, valor) {
    // 1. Consulta HTTP directa a AJX_consulta_noticiasdelito.php
    try {
        const res = await fetch(URL_AJX_FISCALIA, {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/javascript, */*; q=0.01',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'Origin': 'https://www.gestiondefiscalias.gob.ec',
                'Referer': URL_REDIRECT_FISCALIA,
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
            },
            body: new URLSearchParams({
                tipo: 'buscar_general',
                criterio: String(criterio),
                valor: String(valor)
            }),
            signal: AbortSignal.timeout(15000)
        });

        if (res.ok) {
            const json = await res.json();
            if (json && typeof json === 'object' && !json.error) {
                const cabecera = Array.isArray(json.cabecera)
                    ? json.cabecera
                    : (json.cabecera && typeof json.cabecera === 'object' ? Object.values(json.cabecera) : []);
                return { ok: true, cabecera };
            }
        }
    } catch (e) {}

    // 2. Fallback en el contexto del navegador (iframe SIAF de Fiscalía)
    if (!browser) return { ok: false, cabecera: [] };
    const page = await browser.newPage();
    try {
        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36');
        await page.goto(URL_REDIRECT_FISCALIA, { waitUntil: 'domcontentloaded', timeout: 25000 });
        const respuesta = await page.evaluate(async (crit, val) => {
            const r = await fetch('AJX_consulta_noticiasdelito.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json, text/javascript, */*; q=0.01',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    tipo: 'buscar_general',
                    criterio: String(crit),
                    valor: String(val)
                })
            });
            return await r.json();
        }, criterio, valor);

        if (respuesta && typeof respuesta === 'object' && !respuesta.error) {
            const cabecera = Array.isArray(respuesta.cabecera)
                ? respuesta.cabecera
                : (respuesta.cabecera && typeof respuesta.cabecera === 'object' ? Object.values(respuesta.cabecera) : []);
            return { ok: true, cabecera };
        }
    } catch (e) {
    } finally {
        await page.close().catch(() => {});
    }

    return { ok: false, cabecera: [] };
}

async function consultarFiscalia(browser, placaBuscada) {
    try {
        // Criterio 5 = Placa en AJX_consulta_noticiasdelito.php
        const res = await llamarAjaxFiscalia(browser, 5, placaBuscada);
        if (!res.ok) {
            return { ok: false, tiene_denuncias: false, cantidad: 0, listado: [] };
        }

        const listado = res.cabecera
            .filter(row => row && typeof row === 'object')
            .map(row => ({
                ndd: String(row.ndd || row.ndd1 || 'S/N').trim(),
                noticiaDelito: String(row.ndd || row.ndd1 || 'S/N').trim(),
                fecha: String(row.fecha || '').trim(),
                hora: String(row.hora || '').trim(),
                provincia: String(row.pro_descripcion || '').trim(),
                canton: String(row.ciudad || '').trim(),
                delito: String(row.gen_delito_tipopenal || row.tipoincidente || 'Noticia de delito registrada').trim(),
                descripcion: String(row.gen_delito_tipopenal || row.tipoincidente || 'Noticia de delito registrada').trim(),
                unidad: String(row.unidad || '').trim()
            }));

        return {
            ok: true,
            tiene_denuncias: listado.length > 0,
            cantidad: listado.length,
            listado
        };
    } catch (e) {
        return { ok: false, tiene_denuncias: false, cantidad: 0, listado: [] };
    }
}

async function consultarFiscaliaCedula(browser, cedulaBuscada) {
    const limpia = String(cedulaBuscada || '')
        .replace(/[^0-9]/g, '')
        .replace(/^(\d{10})001$/, '$1');
    if (limpia.length !== 10 && limpia.length !== 13) {
        return {
            ok: false,
            consultado: false,
            cedula: limpia,
            motivo: 'cedula_invalida',
            tiene_denuncias: false,
            cantidad: 0,
            total_procesos_vista: 0,
            procesos_otros_roles: 0,
            listado: []
        };
    }

    try {
        // Criterio 2 = Cédula (10 dígitos), Criterio 3 = RUC (13 dígitos)
        const criterio = limpia.length === 13 ? 3 : 2;
        const res = await llamarAjaxFiscalia(browser, criterio, limpia);

        if (!res.ok) {
            return {
                ok: false,
                consultado: true,
                cedula: limpia,
                tiene_denuncias: false,
                cantidad: 0,
                total_procesos_vista: 0,
                procesos_otros_roles: 0,
                listado: []
            };
        }

        const listadoAlertas = [];
        let totalProcesosVista = 0;

        for (const row of res.cabecera) {
            if (!row || typeof row !== 'object') continue;
            totalProcesosVista++;

            const sujetos = Array.isArray(row.sujetos) ? row.sujetos : [];
            const coincidenciasSujeto = sujetos.filter(
                s => s && typeof s === 'object' && String(s.cedula || '').trim() === limpia
            );

            // Verificar si en este proceso figura como SOSPECHOSO o DENUNCIADO
            let rolDetectado = null;
            let nombreDetectado = '';

            for (const s of coincidenciasSujeto) {
                const tipoUpper = String(s.tipo || '').trim().toUpperCase();
                if (tipoUpper.includes('SOSPECHOSO')) {
                    rolDetectado = 'SOSPECHOSO';
                    nombreDetectado = String(s.persona || '').trim();
                    break;
                }
                if (tipoUpper.includes('DENUNCIADO')) {
                    rolDetectado = 'DENUNCIADO';
                    nombreDetectado = String(s.persona || '').trim();
                    break;
                }
            }

            if (rolDetectado) {
                listadoAlertas.push({
                    ndd: String(row.ndd || row.ndd1 || 'S/N').trim(),
                    noticiaDelito: String(row.ndd || row.ndd1 || 'S/N').trim(),
                    fecha: String(row.fecha || '').trim(),
                    provincia: String(row.pro_descripcion || '').trim(),
                    canton: String(row.ciudad || '').trim(),
                    delito: String(row.gen_delito_tipopenal || row.tipoincidente || 'Investigación fiscal registrada').trim(),
                    descripcion: String(row.gen_delito_tipopenal || row.tipoincidente || 'Investigación fiscal registrada').trim(),
                    rol: rolDetectado,
                    nombre_sujeto: nombreDetectado
                });
            }
        }

        return {
            ok: true,
            consultado: true,
            cedula: limpia,
            tiene_denuncias: listadoAlertas.length > 0,
            cantidad: listadoAlertas.length,
            total_procesos_vista: totalProcesosVista,
            procesos_otros_roles: Math.max(0, totalProcesosVista - listadoAlertas.length),
            listado: listadoAlertas
        };
    } catch (e) {
        return {
            ok: false,
            consultado: true,
            cedula: limpia,
            tiene_denuncias: false,
            cantidad: 0,
            total_procesos_vista: 0,
            procesos_otros_roles: 0,
            listado: []
        };
    }
}

module.exports = {
    consultarFiscalia,
    consultarFiscaliaCedula
};
