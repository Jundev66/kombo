.DEFAULT_GOAL := help

# ── Entorno ─────────────────────────────────────────────────────────────────

up:               ## Levantar todo (nginx, api, colas, postgres, redis y las 5 apps)
	docker compose up -d

up-lite:          ## Sólo lo imprescindible: API, base, panel y cocina (máquinas justas)
	docker compose up -d nginx api postgres redis panel kds

setup:            ## Primera vez: .env, clave, migraciones y base de pruebas
	docker compose exec api cp -n .env.example .env || true
	docker compose exec api php artisan key:generate
	@# Sin esto, las fotos de los productos se suben y no se ven.
	docker compose exec api php artisan storage:link
	docker compose exec api php artisan migrate --database=pgsql_owner --force
	docker compose exec -e DB_DATABASE=kombo_test api \
		php artisan migrate --database=pgsql_owner --force

demo:             ## Sembrar los negocios de demostración
	docker compose exec api php artisan db:seed --force
	docker compose exec api php artisan cache:clear

logs:             ## Seguir los logs
	docker compose logs -f

down:             ## Apagar
	docker compose down

# ── El registro de trabajos ─────────────────────────────────────────────────
# El porqué de cómo están las cosas. Ver docs/trabajos/README.md.

trabajo:          ## Abrir un trabajo nuevo: make trabajo t="Paginar la carta"
	@test -n "$(t)" || (echo 'Falta el título: make trabajo t="Paginar la carta"' >&2; exit 1)
	@./scripts/trabajos.sh nuevo "$(t)"

trabajos:         ## Regenerar el índice del registro
	@./scripts/trabajos.sh indice

trabajos-check:   ## Códigos únicos, cabeceras completas e índice al día
	@./scripts/trabajos.sh verificar

# ── Verificar ───────────────────────────────────────────────────────────────
# Esto es lo que tiene que estar verde antes de decir "listo".

test:             ## Toda la suite del backend
	docker compose exec api ./vendor/bin/pest

test-arch:        ## Los límites del diseño
	docker compose exec api ./vendor/bin/pest --group=arch

test-isolation:   ## Que un negocio no pueda ver los datos de otro
	docker compose exec api ./vendor/bin/pest --group=isolation

pint:             ## Estilo de PHP
	docker compose exec api ./vendor/bin/pint

pint-check:       ## Estilo, sin tocar nada (lo que corre en CI)
	docker compose exec api ./vendor/bin/pint --test

typecheck:        ## Tipos del frontend
	docker compose run --rm --no-deps web-install npm run typecheck

size:             ## Presupuesto de bundle — rompe si alguna app se pasa
	docker compose run --rm --no-deps web-install sh -c "npm run build && npm run size"

e2e:              ## Pruebas de usuario por el navegador (siembra antes)
	./e2e/run.sh

check: trabajos-check test-arch test-isolation test pint-check typecheck size  ## Todo junto

help:
	@grep -hE '^[a-zA-Z0-9_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

.PHONY: up up-lite setup demo logs down trabajo trabajos trabajos-check test test-arch test-isolation pint pint-check typecheck size e2e check help
