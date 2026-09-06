#!/usr/bin/env bash
#
# The two database users, and why there are two.
#
# This file is what makes isolation between tenants REAL. Without it, Row
# Level Security is written in the migrations and protects nothing.
#
#   kombo_owner  The container's POSTGRES_USER, i.e. a SUPERUSER, and
#                therefore has BYPASSRLS. It owns the schema and runs the
#                migrations. The application NEVER connects with it.
#
#   kombo_app    What the application connects with on every request. Not a
#                superuser, no BYPASSRLS, and therefore subject to the RLS
#                policies like anyone else.
#
# And the consequence that matters most: THE TEST SUITE RUNS AS kombo_app. A
# suite running as the owner would always pass green — even with isolation
# completely broken — because BYPASSRLS skips every policy silently. The worst
# kind of green there is: quiet, and checking something other than what it
# claims to check.
#
# ── Why this is a script and not the .sql it used to be ─────────────────────
#
# Because the password can no longer be written here. In development it is
# still `secret` and nobody has to configure anything; in production it
# arrives via `KOMBO_APP_PASSWORD` from the server's `.env`. One file for both
# environments: two similar files is how one of them falls behind.
#
# It only runs with an EMPTY data directory. Changing these passwords on a
# server that has already started does not re-run this script — that has to
# be done by hand with ALTER ROLE.
set -euo pipefail

app_password="${KOMBO_APP_PASSWORD:-secret}"

# Empty in production: a test database on a customer's server serves no
# purpose. In development, without the variable, it is called `kombo_test`.
test_database="${KOMBO_TEST_DATABASE-${POSTGRES_DB}_test}"

# `:'app_password'` is psql's way of inserting a value as a quoted literal.
# Pasting it with a shell template would break on any password containing a
# quote — and good passwords contain them.
grant_to_kombo_app() {
    local base="$1"

    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$base" <<-'SQL'
		GRANT USAGE ON SCHEMA public TO kombo_app;

		-- The tables do not exist yet: the migrations create them after this.
		-- DEFAULT privileges are what make every future table created by
		-- kombo_owner reachable by kombo_app without anyone having to remember to
		-- grant it. Remembering is exactly what fails on a busy day.
		ALTER DEFAULT PRIVILEGES FOR ROLE kombo_owner IN SCHEMA public
		    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO kombo_app;
		ALTER DEFAULT PRIVILEGES FOR ROLE kombo_owner IN SCHEMA public
		    GRANT USAGE, SELECT ON SEQUENCES TO kombo_app;
		ALTER DEFAULT PRIVILEGES FOR ROLE kombo_owner IN SCHEMA public
		    GRANT EXECUTE ON FUNCTIONS TO kombo_app;

		-- In case a table was created before getting here.
		GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO kombo_app;
		GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO kombo_app;
	SQL
}

psql -v ON_ERROR_STOP=1 -v app_password="$app_password" \
    --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-'SQL'
	CREATE ROLE kombo_app WITH LOGIN PASSWORD :'app_password';
	-- :"DBNAME" is psql's own: the database it is connected to.
	GRANT CONNECT ON DATABASE :"DBNAME" TO kombo_app;
SQL

grant_to_kombo_app "$POSTGRES_DB"

# ── The test database, with the SAME separation of roles ────────────────────
# Not a copy for convenience: it is the requirement. If the tests ran against
# a database without this separation, the isolation ones would prove nothing.
if [ -n "$test_database" ]; then
    psql -v ON_ERROR_STOP=1 -v base="$test_database" \
        --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-'SQL'
		CREATE DATABASE :"base" OWNER kombo_owner;
		GRANT CONNECT ON DATABASE :"base" TO kombo_app;
	SQL

    grant_to_kombo_app "$test_database"
else
    echo "KOMBO_TEST_DATABASE empty: no test database created."
fi
