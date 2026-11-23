// Prueba de carga de la página principal (k6: https://grafana.com/docs/k6/latest/set-up/install-k6/).
//
// Igual a la prueba original (10 y luego 50 usuarios, solo GET a la portada):
//   k6 run deploy/carga/k6-inicio.js
// Contra otra dirección:
//   k6 run -e BASE_URL=https://unabarberiamuychimbita.tech deploy/carga/k6-inicio.js
// Meta de 2000 usuarios concurrentes (sube de a poco; cada usuario espera 1–3 s entre páginas,
// como una persona real). Desde un PC con internet de casa no se alcanzan 2000 usuarios: corre esta
// parte desde otro servidor (un VPS de prueba por horas) o con Grafana Cloud k6.
//   k6 run -e MODO=meta deploy/carga/k6-inicio.js
//
// La cabecera X-Page-Cache dice de dónde salió la página: STATIC (Caddy, sin PHP), HIT (caché de
// Laravel) o MISS (se armó en ese momento). Al final se ve el porcentaje en "desde la caché".
import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE = __ENV.BASE_URL || 'https://unabarberiamuychimbita.tech';
const META = __ENV.MODO === 'meta';

export const options = {
    scenarios: META
        ? {
            meta: {
                executor: 'ramping-vus',
                startVUs: 0,
                stages: [
                    { duration: '1m', target: 200 },
                    { duration: '2m', target: 1000 },
                    { duration: '2m', target: 2000 },
                    { duration: '3m', target: 2000 },
                    { duration: '1m', target: 0 },
                ],
                gracefulRampDown: '30s',
            },
        }
        : {
            diez: { executor: 'constant-vus', vus: 10, duration: '1m' },
            cincuenta: { executor: 'constant-vus', vus: 50, duration: '1m', startTime: '1m10s' },
        },
    // Meta: p(95) < 3 s y menos de 2 % de errores.
    thresholds: {
        http_req_failed: ['rate<0.02'],
        http_req_duration: ['p(95)<3000'],
    },
};

export default function () {
    // Como un navegador: pide la página comprimida (k6 la descomprime sola).
    const response = http.get(`${BASE}/`, { headers: { 'Accept-Encoding': 'zstd, br, gzip' } });

    check(response, {
        'estado 200': (r) => r.status === 200,
        'desde la caché': (r) => ['STATIC', 'HIT'].includes(r.headers['X-Page-Cache']),
    });

    if (META) {
        sleep(1 + Math.random() * 2);
    }
}
