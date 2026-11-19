#!/usr/bin/env bash
# Despliegue en un VPS Ubuntu/Debian con Docker instalado.
# Uso:  ./deploy.sh
set -euo pipefail
cd "$(dirname "$0")"

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker no está instalado. Instálalo con:"
  echo "  curl -fsSL https://get.docker.com | sudo sh"
  echo "  sudo usermod -aG docker \$USER && newgrp docker"
  exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
  echo "Falta el plugin 'docker compose'. Instala docker-compose-plugin."
  exit 1
fi

rand() { openssl rand -hex "${1:-16}"; }

if [ ! -f .env ]; then
  PUBLIC_IP="${PUBLIC_IP:-$(curl -fsS https://api.ipify.org || true)}"
  if [ -z "${PUBLIC_IP}" ]; then
    echo "No pude detectar la IP pública. Edita .env.deploy.example y vuelve a ejecutar."
    exit 1
  fi

  echo "Generando .env con secretos aleatorios para ${PUBLIC_IP}..."
  sed \
    -e "s/TU_IP/${PUBLIC_IP}/g" \
    -e "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32 | tr -d '\n')|" \
    -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$(rand)|" \
    -e "s|^DB_ROOT_PASSWORD=.*|DB_ROOT_PASSWORD=$(rand)|" \
    -e "s|^EVOLUTION_APIKEY=.*|EVOLUTION_APIKEY=$(rand 24)|" \
    -e "s|^EVOLUTION_DB_PASSWORD=.*|EVOLUTION_DB_PASSWORD=$(rand)|" \
    .env.deploy.example > .env
  chmod 600 .env
fi

RAM_MB="$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 9999)"
if [ "${RAM_MB}" -lt 1800 ] && [ -z "$(swapon --show 2>/dev/null)" ]; then
  echo
  echo "AVISO: este servidor tiene ${RAM_MB} MB de RAM y no tiene swap."
  echo "Crea 2 GB de swap antes de continuar (sección 2 del README) o el build puede morir."
  echo
fi

echo "Construyendo la imagen de Laravel (en 1 GB de RAM tarda 15-30 min)..."
docker compose build web

echo "Arrancando servicios..."
docker compose up -d

echo "Esperando a que el panel responda..."
APP_HOST="$(grep '^APP_HOST=' .env | cut -d= -f2)"
WA_HOST="$(grep '^WA_HOST=' .env | cut -d= -f2)"

for _ in $(seq 1 60); do
  if curl -fsSk "https://${APP_HOST}/login" >/dev/null 2>&1; then
    break
  fi
  sleep 5
done

echo
echo "Listo. URLs:"
echo "  Panel:        https://${APP_HOST}"
echo "  Evolution API: https://${WA_HOST}"
echo
echo "Siguiente paso: crea tu usuario administrador (ver sección 4 del README)."
echo "  Solo tu usuario:"
echo "    docker compose exec web php artisan tinker --execute=\"App\\\\Models\\\\User::updateOrCreate(['email' => 'TU@CORREO.com'], ['name' => 'Tu Nombre', 'password' => 'TU_CLAVE', 'role' => 'admin', 'active' => true]);\""
echo "  Con datos de demostración:"
echo "    docker compose exec web php artisan db:seed --force"
echo
echo "Luego: menú WhatsApp -> Conectar (escanea el QR) -> Conectar webhook automático."
