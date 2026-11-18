const fs = require('fs');

const cedulaRaw = (process.argv[2] || '').replace(/[^0-9]/g, '').replace(/^(\d{10})001$/, '$1');
const archivoSalida = process.argv[3] || '';

if (!cedulaRaw) {
    console.log(JSON.stringify({ ok: false, error: 'Cédula vacía. Uso: node ./scripts/auditoria/satje.cjs 1804629366' }));
    process.exit(1);
}

const URL_BUSCAR_CAUSAS = 'https://api.funcionjudicial.gob.ec/EXPEL-CONSULTA-CAUSAS-SERVICE/api/consulta-causas/informacion/buscarCausas';
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

async function consultarCausasRol(cedula, rol, page = 1, size = 20) {
    const payload = {
        numeroCausa: '',
        actor: {
            cedulaActor: rol === 'ACTOR' ? cedula : '',
            nombreActor: ''
        },
        demandado: {
            cedulaDemandado: rol === 'DEMANDADO' ? cedula : '',
            nombreDemandado: ''
        },
        provincia: '',
        canton: '',
        materia: '',
        dependencia: '',
        numeroFiscalia: '',
        recaptcha: 'verdad',
        first: page,
        pageSize: size
    };

    const res = await fetch(`${URL_BUSCAR_CAUSAS}?page=${page}&size=${size}`, {
        method: 'POST',
        headers: {
            'Accept': 'application/json, text/plain, */*',
            'Content-Type': 'application/json',
            'Origin': 'https://procesosjudiciales.funcionjudicial.gob.ec',
            'Referer': 'https://procesosjudiciales.funcionjudicial.gob.ec/',
            'User-Agent': UA
        },
        body: JSON.stringify(payload),
        signal: AbortSignal.timeout(15000)
    });

    if (!res.ok) {
        throw new Error(`HTTP ${res.status} en SATJE (${rol})`);
    }

    const data = await res.json();
    return Array.isArray(data) ? data : [];
}

function mapearCausa(c, rol) {
    return {
        id_juicio: String(c.idJuicio || '').trim(),
        rol,
        delito_accion: String(c.nombreDelito || c.nombreTipoAccion || 'Proceso judicial').trim(),
        estado_actual: String(c.estadoActual || '').trim(),
        id_materia: c.idMateria ?? null,
        fecha_ingreso: String(c.fechaIngreso || '').trim(),
        judicatura: c.nombreJudicatura || null,
        provincia: c.nombreProvincia || null
    };
}

async function consultarCausasJudiciales(cedulaBuscada) {
    const limpia = String(cedulaBuscada || '')
        .replace(/[^0-9]/g, '')
        .replace(/^(\d{10})001$/, '$1');

    if (limpia.length !== 10 && limpia.length !== 13) {
        return {
            ok: false,
            fuente: 'SATJE',
            consultado: false,
            cedula: limpia,
            tiene_causas: false,
            cantidad_demandado: 0,
            cantidad_actor: 0,
            cantidad: 0,
            mensaje: 'Cédula no detectada o inválida para consulta judicial.',
            listado: []
        };
    }

    try {
        const [comoDemandadoRaw, comoActorRaw] = await Promise.all([
            consultarCausasRol(limpia, 'DEMANDADO'),
            consultarCausasRol(limpia, 'ACTOR')
        ]);

        const demandado = comoDemandadoRaw.map(c => mapearCausa(c, 'DEMANDADO'));
        const actor = comoActorRaw.map(c => mapearCausa(c, 'ACTOR'));
        const listado = [...demandado, ...actor];

        return {
            ok: true,
            fuente: 'SATJE',
            consultado: true,
            cedula: limpia,
            tiene_causas: demandado.length > 0,
            cantidad_demandado: demandado.length,
            cantidad_actor: actor.length,
            cantidad: listado.length,
            mensaje: demandado.length > 0
                ? `Se encontraron ${demandado.length} causa(s) como demandado/procesado en Función Judicial.`
                : (actor.length > 0
                    ? `Sin causas como demandado (${actor.length} causa(s) como actor).`
                    : 'Sin causas judiciales registradas en SATJE.'),
            listado
        };
    } catch (e) {
        return {
            ok: false,
            fuente: 'SATJE',
            consultado: true,
            cedula: limpia,
            tiene_causas: false,
            cantidad_demandado: 0,
            cantidad_actor: 0,
            cantidad: 0,
            listado: [],
            error: e.message
        };
    }
}

if (require.main === module) {
    (async () => {
        const resultado = await consultarCausasJudiciales(cedulaRaw);
        const salida = JSON.stringify(resultado, null, 2);
        if (archivoSalida) {
            fs.writeFileSync(archivoSalida, salida, 'utf8');
        } else {
            process.stdout.write(salida + '\n');
        }
    })();
}

module.exports = { consultarCausasJudiciales };
