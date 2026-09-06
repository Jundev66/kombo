#!/usr/bin/env bash
#
# The end-to-end tests, through the browser.
#
#   ./e2e/run.sh                        all
#   ./e2e/run.sh tests/kitchen.spec.ts  one
#   ./e2e/run.sh --grep "ticket"        the ones matching
#   ./e2e/run.sh --clean               rebuilding the database first
#
# It ALWAYS seeds before running: the difference between a suite you can run
# twice in a row and one that has to be repaired by hand each time.

set -euo pipefail
cd "$(dirname "$0")/.."

CLEAN=0
ARGS=()
for arg in "$@"; do
    if [[ "$arg" == "--clean" ]]; then CLEAN=1; else ARGS+=("$arg"); fi
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

# The resolver caches the tenant in Redis. If seeding changed an id and the
# cache is not cleared, the symptom misleads: /me answers correctly — from
# cache — while every query returns zero rows, because RLS filters by an id
# that no longer exists.
docker compose exec -T api php artisan cache:clear >/dev/null

# Whatever earlier runs left open is closed.
#
# The working screens show what is LIVE and have a cap: without this,
# yesterday's test orders pile up until they fill them, and the ones this run
# creates no longer fit. The screen SAYS SO — that is what the notice is for —
# but the test looking for its own does not find it and fails for a reason
# unrelated to what it was checking.
echo "→ Cerrando lo que quedó abierto de otras corridas…"
docker compose exec -T api php artisan demo:clean --hours=0 >/dev/null

echo "→ Corriendo las pruebas…"
exec docker compose run --rm e2e npx playwright test "${ARGS[@]}"
