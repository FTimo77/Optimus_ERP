// Consulta de propietario actual vía Privynote
async function consultarPropietario(placaBuscada) {
    try {
        const res = await fetch('https://app3902.privynote.net/api/v1/transit/vehicle-owner', {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/plain, */*',
                'Content-Type': 'application/json',
                'Origin': 'https://consultasecuador.com',
                'Referer': 'https://consultasecuador.com/',
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            },
            body: JSON.stringify({ placa: placaBuscada }),
            signal: AbortSignal.timeout(12000)
        });
        const json = await res.json();
        return { ok: true, nombre: json?.data?.name || json?.name || '' };
    } catch (e) {
        return { ok: false, nombre: '', error: e.message };
    }
}

module.exports = {
    consultarPropietario
};
