#!/usr/bin/env node
/*
 * Busca secretos en el código (y opcionalmente en todo el historial de git) sin imprimir su valor.
 *
 *   node scripts/check-secrets.mjs            → archivos versionados actuales
 *   node scripts/check-secrets.mjs --history  → todo el historial (git log -p --all)
 *
 * Sale con código 1 si encuentra algo (sirve como verificación antes de publicar y en CI).
 * Complementa, no reemplaza, a Gitleaks/TruffleHog: en CI se usa Gitleaks.
 */
import { execSync } from 'node:child_process';

const RULES = [
    ['Clave de aplicación Laravel', /APP_KEY=base64:[A-Za-z0-9+/]{20,}={0,2}/],
    ['Clave privada (PEM)', /-----BEGIN (RSA |EC |OPENSSH |DSA |)PRIVATE KEY-----/],
    ['Clave privada VAPID con valor', /VAPID_PRIVATE_KEY=["']?[A-Za-z0-9_-]{30,}/],
    ['Clave de Evolution API con valor', /(EVOLUTION_APIKEY|AUTHENTICATION_API_KEY)=["']?[A-Za-z0-9_-]{16,}/],
    ['Contraseña de base de datos con valor', /DB_PASSWORD=["']?[^\s"'$]{6,}/],
    ['URL de base de datos con contraseña', /(mysql|postgres(ql)?):\/\/[^:\s"']+:[^@\s"']{4,}@/],
    ['Token de WhatsApp Cloud', /WHATSAPP_CLOUD_TOKEN=["']?EA[A-Za-z0-9]{20,}/],
    ['Clave AWS', /AKIA[0-9A-Z]{16}/],
    ['Token de GitHub', /gh[pousr]_[A-Za-z0-9]{30,}/],
    ['Webhook de Slack', /hooks\.slack\.com\/services\/[A-Za-z0-9/]{20,}/],
    ['Clave de Google', /AIza[0-9A-Za-z_-]{35}/],
];
// Valores de ejemplo que no son secretos.
const ALLOW = /(example|ejemplo|changeme|cambia|=pon|usuario:cla|=la-|tu_|xxxx|\$\(|\$\{|\{\$|openssl|=\.\*|secret_here|password_here)/i;

const history = process.argv.includes('--history');
const text = history
    ? execSync('git log -p --all --no-color', { maxBuffer: 1024 * 1024 * 512 }).toString()
    : execSync('git grep -I -n -e . -- . ":!vendor" ":!node_modules" ":!public/build" ":!scripts/check-secrets.mjs"', { maxBuffer: 1024 * 1024 * 512 }).toString();

let commit = '';
let file = '';
const findings = new Map();
for (const line of text.split('\n')) {
    if (history) {
        if (line.startsWith('commit ')) { commit = line.slice(7, 15); continue; }
        if (line.startsWith('+++ b/')) { file = line.slice(6); continue; }
        if (!line.startsWith('+') || line.startsWith('+++')) continue;
    } else {
        file = line.split(':')[0];
    }
    if (file === 'scripts/check-secrets.mjs') continue;
    for (const [name, rule] of RULES) {
        const match = line.match(rule);
        if (match && !ALLOW.test(match[0])) {
            const key = `${name} · ${file}`;
            if (!findings.has(key)) findings.set(key, history ? commit : '');
        }
    }
}

if (findings.size === 0) {
    console.log(`Sin secretos detectados (${history ? 'historial completo' : 'archivos actuales'}).`);
    process.exit(0);
}
console.log('POSIBLES SECRETOS (no se muestra el valor):');
for (const [key, where] of findings) console.log(`  - ${key}${where ? ' (commit ' + where + ')' : ''}`);
console.log('\nSi alguno es real: rótalo en el proveedor. Borrarlo de git no basta.');
process.exit(1);
