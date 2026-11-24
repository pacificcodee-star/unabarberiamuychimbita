#!/bin/sh
set -eu

wait_for_db() {
  php -r '
    $dsn = sprintf(
        "mysql:host=%s;port=%s;dbname=%s",
        getenv("DB_HOST") ?: "db",
        getenv("DB_PORT") ?: "3306",
        getenv("DB_DATABASE") ?: "barberia"
    );
    for ($i = 0; $i < 90; $i++) {
        try {
            new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
            exit(0);
        } catch (Throwable $e) {
            sleep(2);
        }
    }
    fwrite(STDERR, "MySQL no respondió a tiempo\n");
    exit(1);
  '
}

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# PHP-FPM según la máquina. La imagen oficial trae solo 5 procesos (pm.max_children = 5): con eso el
# sitio se saturaba en ~10 peticiones/s. Se calcula con la RAM y los núcleos reales; se puede fijar con
# PHP_FPM_MAX_CHILDREN en deploy/.env.
configure_php_fpm() {
  mem_mb=$(awk '/MemTotal/ {print int($2 / 1024)}' /proc/meminfo)
  limit=$(cat /sys/fs/cgroup/memory.max 2>/dev/null || echo max)
  if [ "$limit" != "max" ] && [ -n "$limit" ]; then
    limit_mb=$((limit / 1048576))
    [ "$limit_mb" -lt "$mem_mb" ] && mem_mb=$limit_mb
  fi
  cpus=$(nproc 2>/dev/null || echo 1)
  # Un tercio de la RAM para PHP (el resto: MySQL, Evolution, PostgreSQL, Redis) a ~48 MB por proceso,
  # y como mucho 12 procesos por núcleo (casi todo el tiempo esperan a la base de datos).
  children=$((mem_mb / 3 / 48))
  max_by_cpu=$((cpus * 12))
  [ "$children" -gt "$max_by_cpu" ] && children=$max_by_cpu
  [ "$children" -lt 5 ] && children=5
  children=${PHP_FPM_MAX_CHILDREN:-$children}
  spare_min=$((children / 4)); [ "$spare_min" -lt 2 ] && spare_min=2
  spare_max=$((children / 2)); [ "$spare_max" -le "$spare_min" ] && spare_max=$((spare_min + 1))
  cat > /usr/local/etc/php-fpm.d/zz-barberia-pool.conf <<EOF
[www]
pm = dynamic
pm.max_children = ${children}
pm.start_servers = ${spare_min}
pm.min_spare_servers = ${spare_min}
pm.max_spare_servers = ${spare_max}
; Recicla cada proceso tras 1000 peticiones (evita que una fuga de memoria crezca sin límite).
pm.max_requests = 1000
; Cola de espera más larga para picos (por defecto 511).
listen.backlog = 4096
EOF
  # OPcache de producción: el código no cambia dentro del contenedor (cada versión es una imagen nueva),
  # así que no hace falta revisar la fecha de cada archivo en cada petición.
  cat > /usr/local/etc/php/conf.d/zz-barberia-opcache.ini <<EOF
opcache.enable = 1
opcache.memory_consumption = 192
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = ${OPCACHE_VALIDATE_TIMESTAMPS:-0}
realpath_cache_size = 4096K
realpath_cache_ttl = 600
EOF
  echo "PHP-FPM: ${children} procesos (RAM ${mem_mb} MB, ${cpus} núcleos)"
}

case "${ROLE:-web}" in
  scheduler)
    wait_for_db
    exec php artisan schedule:work
    ;;
  queue)
    wait_for_db
    exec php artisan queue:work --sleep=1 --tries=3 --max-time=3600
    ;;
  *)
    wait_for_db
    configure_php_fpm
    php artisan migrate --force
    php artisan config:cache
    # Rutas y eventos precompilados: cada petición se ahorra leer routes/web.php y buscar oyentes.
    php artisan route:cache
    php artisan event:cache
    php artisan view:cache
    # Versión nueva: fuera las páginas públicas guardadas de la versión anterior.
    mkdir -p public/page-cache && chown www-data:www-data public/page-cache
    php artisan barberia:page-cache vaciar
    # Si el Caddyfile no es válido (p. ej. un cambio a mano), se usa el de respaldo en vez de dejar el sitio caído.
    if ! caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1; then
      echo "Caddyfile no válido: se usa deploy/caddy/Caddyfile.respaldo" >&2
      cp /etc/caddy/Caddyfile.respaldo /etc/caddy/Caddyfile
    fi
    # Copias WebP de las imágenes del sitio y de las subidas, en segundo plano para no demorar el arranque.
    (php artisan barberia:webp >/proc/1/fd/1 2>&1 || true) &
    exec supervisord -c /etc/supervisord.conf
    ;;
esac
