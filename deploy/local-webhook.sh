#!/usr/bin/env bash
# Registra el webhook interno de Evolution hacia Laravel en modo local.
# (El botón del panel usa APP_URL http://localhost:8010, que desde el contenedor
# de Evolution apunta a sí mismo; por eso aquí usamos el nombre de servicio "web".)
set -euo pipefail
cd "$(dirname "$0")"

APIKEY="$(grep '^EVOLUTION_APIKEY=' .env.local | cut -d= -f2)"

curl -fsS -X POST "http://localhost:8080/webhook/set/barberia" \
  -H "apikey: ${APIKEY}" \
  -H "Content-Type: application/json" \
  -d '{"webhook":{"enabled":true,"url":"http://web:80/webhook/evolution","events":["MESSAGES_UPSERT","MESSAGES_UPDATE","CONNECTION_UPDATE","QRCODE_UPDATED","LOGOUT_INSTANCE","REMOVE_INSTANCE"],"byEvents":false,"base64":false}}'

echo
echo "Webhook local apuntando a http://web:80/webhook/evolution"
