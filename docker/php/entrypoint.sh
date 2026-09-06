#!/usr/bin/env bash
#
# What happens every time an API container starts.
#
# The order matters, and there is an underlying decision: only the web process
# migrates. If the queue and the scheduler migrated too, three containers
# starting at once would run the same migrations in parallel — and two
# simultaneous `ALTER TABLE`s on one table is how a database breaks on deploy.
set -euo pipefail

esperar_a_postgres() {
    local intentos=0

    until php -r "
        try { new PDO(
            'pgsql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.getenv('DB_DATABASE'),
            getenv('DB_USERNAME'), getenv('DB_PASSWORD')
        ); } catch (Throwable \$e) { exit(1); }
    " 2>/dev/null; do
        intentos=$((intentos + 1))

        if [ "$intentos" -gt 60 ]; then
            echo "La base de datos no respondió en 60 intentos. Aquí paramos." >&2
            exit 1
        fi

        sleep 1
    done
}

esperar_a_postgres

if [ "${1:-}" = "php-fpm" ]; then
    echo "→ Migrando…"
    # As the schema OWNER: `kombo_app` cannot create tables, and that is exactly
    # the guarantee that makes RLS mean something.
    php artisan migrate --database=pgsql_owner --force

    echo "→ Enlazando el almacenamiento público…"
    # Without this, product photos upload and are not visible.
    php artisan storage:link || true

    echo "→ Cacheando configuración y rutas…"
    # The biggest win on a slow machine after opcache. Safe here because there is
    # not a single `env()` call outside `config/` — a test watches for it — and
    # with the config cached, `env()` returns null and the failure shows up far
    # from its cause.
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
else
    # The queue and the scheduler wait for the web process to have migrated.
    # Starting on a half-applied schema is worse than starting late.
    echo "→ Esperando a que las migraciones estén hechas…"

    until php artisan migrate:status --database=pgsql_owner 2>/dev/null | grep -q "Ran"; do
        sleep 2
    done
fi

exec "$@"
