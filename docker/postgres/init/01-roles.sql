-- Los dos usuarios de base de datos, y por qué son dos.
--
-- Este fichero es la pieza que hace REAL el aislamiento entre negocios. Sin
-- él, Row Level Security está escrito en las migraciones y no protege nada.
--
--   kombo_owner  Es el POSTGRES_USER del contenedor, o sea SUPERUSUARIO, y por
--                tanto tiene BYPASSRLS. Es dueño del esquema y corre las
--                migraciones. La aplicación NUNCA conecta con él.
--
--   kombo_app    Con el que conecta la aplicación en cada petición. No es
--                superusuario, no tiene BYPASSRLS, y por tanto está sujeto a
--                las políticas de RLS igual que cualquiera.
--
-- Y la consecuencia que más importa: LA SUITE DE PRUEBAS CORRE COMO kombo_app.
-- Una suite que corriera como el dueño pasaría siempre en verde —incluso con
-- el aislamiento completamente roto— porque BYPASSRLS se salta toda política
-- sin decir nada. Es la peor clase de verde que existe: silencioso, y
-- comprobando algo distinto de lo que dice comprobar.

CREATE ROLE kombo_app WITH LOGIN PASSWORD 'secret';

GRANT CONNECT ON DATABASE kombo TO kombo_app;
GRANT USAGE ON SCHEMA public TO kombo_app;

-- Las tablas todavía no existen: las crean las migraciones, después de esto.
-- Los privilegios POR DEFECTO son lo que hace que toda tabla futura creada por
-- kombo_owner quede accesible a kombo_app sin que nadie tenga que acordarse de
-- otorgarla. Acordarse es exactamente lo que falla el día que hay prisa.
ALTER DEFAULT PRIVILEGES FOR ROLE kombo_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO kombo_app;
ALTER DEFAULT PRIVILEGES FOR ROLE kombo_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO kombo_app;
ALTER DEFAULT PRIVILEGES FOR ROLE kombo_owner IN SCHEMA public
    GRANT EXECUTE ON FUNCTIONS TO kombo_app;

-- Por si alguna tabla se creó antes de llegar aquí.
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO kombo_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO kombo_app;

-- ── La base de pruebas, con la MISMA separación de roles ────────────────────
-- No es una copia por comodidad: es el requisito. Si las pruebas corrieran
-- contra una base sin esta separación, las de aislamiento no probarían nada.

CREATE DATABASE kombo_test OWNER kombo_owner;

\connect kombo_test

GRANT CONNECT ON DATABASE kombo_test TO kombo_app;
GRANT USAGE ON SCHEMA public TO kombo_app;

ALTER DEFAULT PRIVILEGES FOR ROLE kombo_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO kombo_app;
ALTER DEFAULT PRIVILEGES FOR ROLE kombo_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO kombo_app;
ALTER DEFAULT PRIVILEGES FOR ROLE kombo_owner IN SCHEMA public
    GRANT EXECUTE ON FUNCTIONS TO kombo_app;

GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO kombo_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO kombo_app;
