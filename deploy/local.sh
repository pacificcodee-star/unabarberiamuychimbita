#!/usr/bin/env bash
# Levanta el stack completo en tu PC con Docker Desktop (sin HTTPS).
set -euo pipefail
cd "$(dirname "$0")"

if ! docker info >/dev/null 2>&1; then
  echo "Docker no está corriendo. Abre Docker Desktop y espera a que diga 'Engine running'."
  exit 1
fi

docker compose -f docker-compose.yml -f docker-compose.local.yml --env-file .env.local up -d --build

echo
echo "Panel:        http://localhost:8010"
echo "Evolution:    http://localhost:8080 (API directa, apikey en deploy/.env.local)"
echo
echo "Siguiente paso (una sola vez), con el stack arriba:"
echo "  bash local-webhook.sh"
