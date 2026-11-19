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

case "${ROLE:-web}" in
  scheduler)
    wait_for_db
    exec php artisan schedule:work
    ;;
  queue)
    wait_for_db
    exec php artisan queue:work --sleep=3 --tries=3 --max-time=3600
    ;;
  *)
    wait_for_db
    php artisan migrate --force
    php artisan config:cache
    php artisan view:cache
    exec supervisord -c /etc/supervisord.conf
    ;;
esac
