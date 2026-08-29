#!/usr/bin/env bash
#
# Los dos usuarios de base de datos, y por qué son dos.
#
# Este fichero es la pieza que hace REAL el aislamiento entre negocios. Sin él,
# Row Level Security está escrito en las migraciones y no protege nada.
#
#   kombo_owner  Es el POSTGRES_USER del contenedor, o sea SUPERUSUARIO, y por
#                tanto tiene BYPASSRLS. Es dueño del esquema y corre las
#                migraciones. La aplicación NUNCA conecta con él.
#
#   kombo_app    Con el que conecta la aplicación en cada petición. No es
#                superusuario, no tiene BYPASSRLS, y por tanto está sujeto a
#                las políticas de RLS igual que cualquiera.
#
# Y la consecuencia que más importa: LA SUITE DE PRUEBAS CORRE COMO kombo_app.
# Una suite que corriera como el dueño pasaría siempre en verde —incluso con el
# aislamiento completamente roto— porque BYPASSRLS se salta toda política sin
# decir nada. Es la peor clase de verde que existe: silencioso, y comprobando
# algo distinto de lo que dice comprobar.
#
# ── Por qué es un script y no el .sql que era ───────────────────────────────
#
# Porque la contraseña ya no puede estar escrita aquí. En desarrollo sigue
# siendo `secret` y nadie tiene que configurar nada; en producción llega por
# `KOMBO_APP_PASSWORD` desde el `.env` del servidor. Un solo fichero para los
# dos entornos: dos ficheros parecidos es cómo uno de ellos se queda atrás.
#
# Sólo corre con el directorio de datos VACÍO. Si cambias estas contraseñas en
# un servidor que ya arrancó, este script no vuelve a ejecutarse — hay que
# hacerlo a mano con ALTER ROLE.
set -euo pipefail

app_password="${KOMBO_APP_PASSWORD:-secret}"

# En producción va vacía: una base de pruebas en el servidor del cliente no
# pinta nada. En desarrollo, sin la variable, se llama `kombo_test`.
test_database="${KOMBO_TEST_DATABASE-${POSTGRES_DB}_test}"

# `:'app_password'` es la forma que tiene psql de meter un valor como literal
# entrecomillado. Pegarlo con una plantilla de shell rompería con cualquier
# contraseña que lleve una comilla — y las contraseñas buenas las llevan.
otorgar_a_kombo_app() {
    local base="$1"

    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$base" <<-'SQL'
		GRANT USAGE ON SCHEMA public TO kombo_app;

		-- Las tablas todavía no existen: las crean las migraciones, después de
		-- esto. Los privilegios POR DEFECTO son lo que hace que toda tabla futura
		-- creada por kombo_owner quede accesible a kombo_app sin que nadie tenga
		-- que acordarse de otorgarla. Acordarse es exactamente lo que falla el
		-- día que hay prisa.
		ALTER DEFAULT PRIVILEGES FOR ROLE kombo_owner IN SCHEMA public
		    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO kombo_app;
		ALTER DEFAULT PRIVILEGES FOR ROLE kombo_owner IN SCHEMA public
		    GRANT USAGE, SELECT ON SEQUENCES TO kombo_app;
		ALTER DEFAULT PRIVILEGES FOR ROLE kombo_owner IN SCHEMA public
		    GRANT EXECUTE ON FUNCTIONS TO kombo_app;

		-- Por si alguna tabla se creó antes de llegar aquí.
		GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO kombo_app;
		GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO kombo_app;
	SQL
}

psql -v ON_ERROR_STOP=1 -v app_password="$app_password" \
    --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-'SQL'
	CREATE ROLE kombo_app WITH LOGIN PASSWORD :'app_password';
	-- :"DBNAME" lo pone psql solo: es la base a la que está conectado.
	GRANT CONNECT ON DATABASE :"DBNAME" TO kombo_app;
SQL

otorgar_a_kombo_app "$POSTGRES_DB"

# ── La base de pruebas, con la MISMA separación de roles ────────────────────
# No es una copia por comodidad: es el requisito. Si las pruebas corrieran
# contra una base sin esta separación, las de aislamiento no probarían nada.
if [ -n "$test_database" ]; then
    psql -v ON_ERROR_STOP=1 -v base="$test_database" \
        --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-'SQL'
		CREATE DATABASE :"base" OWNER kombo_owner;
		GRANT CONNECT ON DATABASE :"base" TO kombo_app;
	SQL

    otorgar_a_kombo_app "$test_database"
else
    echo "KOMBO_TEST_DATABASE vacía: no se crea base de pruebas."
fi
