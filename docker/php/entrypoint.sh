#!/usr/bin/env bash
#
# Lo que pasa cada vez que arranca un contenedor de la API.
#
# El orden importa, y hay una decisión de fondo: **sólo el proceso web migra**.
# Si migraran también la cola y el planificador, tres contenedores arrancando a
# la vez correrían las mismas migraciones en paralelo — y dos `ALTER TABLE`
# simultáneos sobre la misma tabla es cómo se rompe una base en un despliegue.
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
    # Como DUEÑO del esquema: `kombo_app` no puede crear tablas, y ésa es
    # exactamente la garantía que hace que RLS signifique algo.
    php artisan migrate --database=pgsql_owner --force

    echo "→ Enlazando el almacenamiento público…"
    # Sin esto, las fotos de los productos se suben y no se ven.
    php artisan storage:link || true

    echo "→ Cacheando configuración y rutas…"
    # Es lo que más rinde en una máquina lenta después de opcache. Seguro de
    # hacer aquí porque no hay una sola llamada a `env()` fuera de `config/`
    # —hay una prueba que lo vigila—: con la configuración cacheada, `env()`
    # devuelve null y el fallo aparece lejos de la causa.
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
else
    # La cola y el planificador esperan a que el proceso web haya migrado.
    # Arrancar con un esquema a medias es peor que arrancar tarde.
    echo "→ Esperando a que las migraciones estén hechas…"

    until php artisan migrate:status --database=pgsql_owner 2>/dev/null | grep -q "Ran"; do
        sleep 2
    done
fi

exec "$@"
