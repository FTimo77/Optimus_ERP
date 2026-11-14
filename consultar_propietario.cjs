const fs = require('fs');

const placa = (process.argv[2] || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
const archivoSalida = process.argv[3];

if (!placa) {
    const errorMsg = JSON.stringify({ status: 'error', message: 'Placa vacía' });
    if (archivoSalida) fs.writeFileSync(archivoSalida, errorMsg, 'utf8');
    else console.log(errorMsg);
    process.exit(1);
}

(async () => {
    try {
        const url = 'https://app3902.privynote.net/api/v1/transit/vehicle-owner';

        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/plain, */*',
                'Accept-Language': 'es,es-ES;q=0.9,en;q=0.8',
                'Content-Type': 'application/json',
                'Origin': 'https://consultasecuador.com',
                'Referer': 'https://consultasecuador.com/',
                'X-Requested-With': 'XMLHttpRequest',
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
            },
            body: JSON.stringify({ placa: placa }),
            signal: AbortSignal.timeout(15000)
        });

        const texto = await response.text();

        if (archivoSalida) {
            fs.writeFileSync(archivoSalida, texto.trim(), 'utf8');
        } else {
            console.log(texto.trim());
        }

    } catch (error) {
        const errOut = JSON.stringify({
            status: 'error',
            message: error.message
        });

        if (archivoSalida) {
            fs.writeFileSync(archivoSalida, errOut, 'utf8');
        } else {
            console.log(errOut);
        }
    }
})();