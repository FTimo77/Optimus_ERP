const fs = require('fs');

const apiKey = process.argv[2];
const archivoEntrada = process.argv[3];
const archivoSalida = process.argv[4];

if (!apiKey || !archivoEntrada || !archivoSalida) {
    process.exit(1);
}

const esperar = (ms) => new Promise(resolve => setTimeout(resolve, ms));

(async () => {
    try {
        const payload = fs.readFileSync(archivoEntrada, 'utf8');

        // 1. Consultamos dinámicamente qué modelos Flash/Pro tiene activos tu cuenta de Google AI Studio
        let modelosCandidatos = ['gemini-3.8-flash'];
        try {
            const resModelos = await fetch(`https://generativelanguage.googleapis.com/v1beta/models?key=${apiKey}`, {
                signal: AbortSignal.timeout(5000)
            });
            if (resModelos.ok) {
                const dataModelos = await resModelos.json();
                const disponibles = (dataModelos.models || [])
                    .filter(m => m.supportedGenerationMethods && m.supportedGenerationMethods.includes('generateContent'))
                    .map(m => m.name.replace('models/', ''))
                    .filter(name => name.includes('gemini') && !name.includes('embedding') && !name.includes('imagen') && !name.includes('tts'));

                // Priorizamos gemini-3.8-flash primero, y dejamos cualquier otro modelo activo de tu cuenta como respaldo
                const otros = disponibles.filter(m => m !== 'gemini-3.8-flash');
                modelosCandidatos = ['gemini-3.8-flash', ...otros];
            }
        } catch (e) {
            // Si falla la lista, usamos la lista por defecto
        }

        let ultimaRespuesta = '';

        // 2. Intentamos hasta 4 rondas si Google responde "high demand" (503 / 429)
        for (let intento = 1; intento <= 4; intento++) {
            for (const modelo of modelosCandidatos) {
                try {
                    const url = `https://generativelanguage.googleapis.com/v1beta/models/${modelo}:generateContent?key=${apiKey}`;
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: payload,
                        signal: AbortSignal.timeout(25000)
                    });

                    const texto = await response.text();
                    ultimaRespuesta = texto;

                    // Si respondió 200 OK y tiene candidates, guardamos y terminamos de inmediato
                    if (response.ok && texto.includes('"candidates"')) {
                        fs.writeFileSync(archivoSalida, texto, 'utf8');
                        process.exit(0);
                    }

                    // Si el modelo no está disponible para usuarios nuevos (404 / no longer available), lo sacamos de la lista
                    if (response.status === 404 || texto.includes('no longer available')) {
                        modelosCandidatos = modelosCandidatos.filter(m => m !== modelo);
                        continue;
                    }

                    // Si es "high demand" (503) o límite de velocidad (429), probamos el siguiente o esperamos un par de segundos
                    if (response.status === 503 || response.status === 429 || texto.includes('high demand')) {
                        continue;
                    }
                } catch (err) {
                    ultimaRespuesta = JSON.stringify({ error: { message: err.message } });
                }
            }

            // Pausa de 2.5 segundos antes del siguiente reintento cuando hay "High Demand"
            await esperar(2500);
        }

        // Si después de todos los reintentos sigue saturado, devolvemos el último mensaje
        fs.writeFileSync(archivoSalida, ultimaRespuesta, 'utf8');

    } catch (error) {
        fs.writeFileSync(archivoSalida, JSON.stringify({
            error: { message: 'Error Node Fetch: ' + error.message }
        }), 'utf8');
    }
})();