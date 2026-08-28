#!/usr/bin/env bash
#
# Las pruebas de usuario, por el navegador.
#
#   ./e2e/run.sh                       todas
#   ./e2e/run.sh tests/cocina.spec.ts  una
#   ./e2e/run.sh --grep "comanda"      las que digan eso
#   ./e2e/run.sh --limpio              rehaciendo la base antes
#
# Siembra SIEMPRE antes de correr. Es la diferencia entre una suite que se
# puede correr dos veces seguidas y una que hay que reparar a mano cada vez.

set -euo pipefail
cd "$(dirname "$0")/.."

CLEAN=0
ARGS=()
for arg in "$@"; do
    if [[ "$arg" == "--limpio" ]]; then CLEAN=1; else ARGS+=("$arg"); fi
done

if ! docker compose ps --status running --services 2>/dev/null | grep -q '^nginx$'; then
    echo "El entorno no está arriba. Levántalo con:  make up" >&2
    exit 1
fi

if [[ "$CLEAN" -eq 1 ]]; then
    echo "⚠  Rehaciendo la base de desarrollo desde cero…"
    docker compose exec -T api php artisan migrate:fresh --database=pgsql_owner --force
fi

echo "→ Sembrando los negocios de demostración…"
docker compose exec -T api php artisan db:seed --force

# El resolutor cachea el negocio en Redis. Si la siembra cambió un
# identificador y no se limpia, el síntoma engaña: /me responde bien —viene de
# caché— y TODAS las consultas devuelven cero filas, porque RLS filtra por un
# identificador que ya no existe.
docker compose exec -T api php artisan cache:clear >/dev/null

# Se cierra lo que quedó abierto de corridas anteriores.
#
# Las pantallas de trabajo enseñan lo que está VIVO y tienen tope: sin esto,
# los pedidos de las pruebas de ayer se acumulan hasta llenarlas, y los que
# crea esta corrida dejan de caber. La pantalla lo AVISA —para eso está el
# aviso— pero la prueba que busca el suyo no lo encuentra y falla por un motivo
# que no tiene nada que ver con lo que estaba probando.
echo "→ Cerrando lo que quedó abierto de otras corridas…"
docker compose exec -T api php artisan demo:limpiar --horas=0 >/dev/null

echo "→ Corriendo las pruebas…"
exec docker compose run --rm e2e npx playwright test "${ARGS[@]}"
