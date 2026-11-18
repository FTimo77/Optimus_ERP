const crypto = require('crypto');
const fs = require('fs');

const AMT_SECRET_KEY = 'CVozg4dSw&e@OlZylS4&qUQuGu?wSen2';

const placaRaw = (process.argv[2] || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
const archivoSalida = process.argv[3] || '';

if (!placaRaw) {
    console.log(JSON.stringify({ ok: false, error: 'Placa vacía. Uso: node ./scripts/auditoria/amt.cjs PFI0582' }));
    process.exit(1);
}

const placa = /^([A-Z]{3})(\d{3})$/.test(placaRaw)
    ? placaRaw.replace(/^([A-Z]{3})(\d{3})$/, '$10$2')
    : placaRaw;

function desencriptarRetornoAMT(retornoBase64) {
    if (!retornoBase64 || typeof retornoBase64 !== 'string') return null;
    try {
        const raw = Buffer.from(retornoBase64, 'base64');
        if (raw.subarray(0, 8).toString('utf8') !== 'Salted__') {
            return JSON.parse(raw.toString('utf8'));
        }

        const salt = raw.subarray(8, 16);
        const ciphertext = raw.subarray(16);
        const password = Buffer.from(AMT_SECRET_KEY, 'utf8');

        let d = Buffer.alloc(0);
        let dPrev = Buffer.alloc(0);
        while (d.length < 48) {
            dPrev = crypto.createHash('md5').update(Buffer.concat([dPrev, password, salt])).digest();
            d = Buffer.concat([d, dPrev]);
        }

        const key = d.subarray(0, 32);
        const iv = d.subarray(32, 48);

        const decipher = crypto.createDecipheriv('aes-256-cbc', key, iv);
        const decrypted = Buffer.concat([decipher.update(ciphertext), decipher.final()]);
        return JSON.parse(decrypted.toString('utf8'));
    } catch (e) {
        return { error_decrypt: e.message };
    }
}

// Busca el arreglo de multas/rubros dentro del JSON desencriptado de la AMT
function extraerArregloMultas(desencriptado) {
    if (!desencriptado) return [];
    if (Array.isArray(desencriptado)) return desencriptado;
    if (typeof desencriptado === 'object') {
        if (desencriptado.debts && typeof desencriptado.debts === 'object') {
            const payable = Array.isArray(desencriptado.debts.debtsPayable) ? desencriptado.debts.debtsPayable : [];
            const withOrder = Array.isArray(desencriptado.debts.debtsWithOrder) ? desencriptado.debts.debtsWithOrder : [];
            if (payable.length > 0 || withOrder.length > 0) {
                return [...payable, ...withOrder];
            }
        }
        for (const k of Object.keys(desencriptado)) {
            if (Array.isArray(desencriptado[k])) {
                return desencriptado[k];
            }
        }
        // Si es un solo objeto de multa directo
        if (desencriptado.numBoleta || desencriptado.Valor || desencriptado.Total || desencriptado.valorSaldoTotal || desencriptado.placa) {
            return [desencriptado];
        }
    }
    return [];
}

async function consultarMultasAMT(placaBuscada) {
    try {
        const url = `https://apis.amt.gob.ec/api-gateway/public-api/paymentOrders/vehicle-action/${placaBuscada}/0`;
        const res = await fetch(url, {
            method: 'GET',
            headers: {
                'Accept': 'application/json, text/plain, */*',
                'Origin': 'https://servicios.amt.gob.ec',
                'Referer': 'https://servicios.amt.gob.ec/',
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
            },
            signal: AbortSignal.timeout(10000)
        });

        if (!res.ok) {
            return { ok: false, fuente: 'AMT', placa: placaBuscada, total: 0, cantidad: 0, listado: [], error: `HTTP ${res.status}` };
        }

        const json = await res.json();
        const desencriptado = json?.retorno ? desencriptarRetornoAMT(json.retorno) : null;
        const items = extraerArregloMultas(desencriptado);

        let totalCalculado = 0;
        const listado = items.map(m => {
            const val = Number(
                m.valorSaldoTotal
                ?? m.valorSaldo
                ?? m.Total
                ?? m.total
                ?? m.Valor
                ?? m.valor
                ?? m.valorTotal
                ?? 0
            ) || 0;
            totalCalculado += val;
            const primerDetalle = Array.isArray(m.detalle) && m.detalle.length > 0 ? m.detalle[0] : null;
            const fechaRaw = primerDetalle?.fechaCreacionRubro ? String(primerDetalle.fechaCreacionRubro) : '';
            const fechaFormateada = /^\d{8}$/.test(fechaRaw)
                ? `${fechaRaw.slice(6, 8)}/${fechaRaw.slice(4, 6)}/${fechaRaw.slice(0, 4)}`
                : (m.fechaEmision || m.fecha || (m.anioTributario ? String(m.anioTributario) : ''));

            return {
                boleta: m.tipoOrden || m.numBoleta || m.boleta || m.citacion || m.id || 'S/N',
                placa: m.placa || desencriptado?.infoVehicle?.placa || placaBuscada,
                chasis: m.chasis || desencriptado?.infoVehicle?.chasis || '',
                identificacion: m.identificacion || m.cedula || '',
                propietario: m.nombres || m.propietario || desencriptado?.infoVehicle?.razonSocial || '',
                fecha: fechaFormateada,
                concepto: m.descripcionRubro || m.Concepto || m.concepto || m.Rubro || m.rubro || 'Rubro / Infracción AMT',
                valor: Number((m.Valor ?? m.valor ?? val) || 0),
                interes: Number((m.Interes ?? m.interes ?? 0) || 0),
                total: Number(val.toFixed(2))
            };
        });

        const totalFinal = typeof desencriptado?.debts?.totalDebts === 'number'
            ? Number(desencriptado.debts.totalDebts.toFixed(2))
            : Number(totalCalculado.toFixed(2));

        return {
            ok: true,
            fuente: 'AMT',
            placa: placaBuscada,
            tiene_multas: listado.length > 0 || totalFinal > 0,
            cantidad: listado.length,
            total: totalFinal,
            propietario_detectado: (listado[0]?.propietario || desencriptado?.infoVehicle?.razonSocial || desencriptado?.nombres || '').trim() || null,
            cedula_detectada: listado[0]?.identificacion || desencriptado?.identificacion || null,
            listado,
            // Si listado sale vacío pero había datos en retorno, mostramos qué estructura trajo para verla
            debug_retorno: listado.length === 0 ? desencriptado : undefined
        };
    } catch (e) {
        return { ok: false, fuente: 'AMT', placa: placaBuscada, total: 0, cantidad: 0, listado: [], error: e.message };
    }
}

if (require.main === module) {
    (async () => {
        const resultado = await consultarMultasAMT(placa);
        const salida = JSON.stringify(resultado, null, 2);
        if (archivoSalida) {
            fs.writeFileSync(archivoSalida, salida, 'utf8');
        } else {
            process.stdout.write(salida + '\n');
        }
    })();
}

module.exports = { consultarMultasAMT };