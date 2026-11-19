#!/bin/sh
set -eu

if [ -z "${APP_KEY:-}" ]; then
  echo "Falta APP_KEY en las variables de entorno de Render." >&2
  exit 1
fi
if [ -z "${APP_URL:-}" ]; then
  echo "Falta APP_URL (https://TU-SERVICIO.onrender.com)." >&2
  exit 1
fi

wait_for_db() {
  php -r '
    $url = getenv("DB_URL") ?: "";
    $p = parse_url($url);
    if (!$p || empty($p["host"])) {
        fwrite(STDERR, "DB_URL inválida\n");
        exit(1);
    }
    $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s", $p["host"], $p["port"] ?? 5432, ltrim($p["path"] ?? "", "/"));
    if (strpos($url, "sslmode=require") !== false) {
        $dsn .= ";sslmode=require";
    }
    $user = urldecode($p["user"] ?? "");
    $pass = urldecode($p["pass"] ?? "");
    for ($i = 0; $i < 60; $i++) {
        try {
            new PDO($dsn, $user, $pass, [PDO::ATTR_TIMEOUT => 5]);
            exit(0);
        } catch (Throwable $e) {
            sleep(2);
        }
    }
    fwrite(STDERR, "Postgres no respondió a tiempo: " . $e->getMessage() . "\n");
    exit(1);
  '
}

wait_for_db

# Evolution: una sola fuente de verdad para URL y credenciales.
export SERVER_URL="${APP_URL%/}/evolution"
export AUTHENTICATION_API_KEY="${EVOLUTION_APIKEY}"
export DATABASE_PROVIDER="postgresql"
export DATABASE_CONNECTION_URI="${DB_URL}"
export CACHE_REDIS_ENABLED="${CACHE_REDIS_ENABLED:-false}"
export CACHE_LOCAL_ENABLED="${CACHE_LOCAL_ENABLED:-true}"

cd /var/www/html
php artisan migrate --force
php artisan config:cache
php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache

exec supervisord -c /etc/supervisord.conf
