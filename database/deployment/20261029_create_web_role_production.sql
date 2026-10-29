-- MultiPro: crea o endurece el rol de login de la aplicacion web.
-- La clave se recibe exclusivamente desde MULTIPRO_APP_DB_PASSWORD.
-- Ejecutar como rol administrador/migrador antes de cambiar PGUSER en Railway.
\set ON_ERROR_STOP on
\if :{?app_role}
\else
\echo 'ERROR: falta -v app_role=NOMBRE_CONFIRMADO'
\quit 3
\endif
\getenv app_password MULTIPRO_APP_DB_PASSWORD
\if :{?app_password}
\else
\echo 'ERROR: falta MULTIPRO_APP_DB_PASSWORD en el entorno privado de ejecucion'
\quit 3
\endif

SELECT (:'app_role' = current_user OR :'app_role' = 'postgres' OR length(:'app_role') < 3) AS app_role_invalido \gset
\if :app_role_invalido
\echo 'ERROR: el rol web no puede coincidir con el rol migrador, postgres ni ser un nombre corto'
\quit 3
\endif

BEGIN;
SELECT format('CREATE ROLE %I', :'app_role')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'app_role')
\gexec

SELECT format(
    'ALTER ROLE %I WITH LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS',
    :'app_role', :'app_password'
) \gexec
SELECT format('ALTER ROLE %I IN DATABASE %I SET search_path TO public', :'app_role', current_database()) \gexec
SELECT format('REVOKE ALL PRIVILEGES ON DATABASE %I FROM %I', current_database(), :'app_role') \gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'app_role') \gexec
SELECT format('REVOKE ALL ON SCHEMA public FROM %I', :'app_role') \gexec
SELECT format('GRANT USAGE ON SCHEMA public TO %I', :'app_role') \gexec
COMMIT;

\unset app_password
