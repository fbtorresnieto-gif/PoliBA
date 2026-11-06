-- MultiPro: permisos mínimos para filtros, cascadas y auditoría de asistencia.
-- Ejecutar con psql -v app_role=NOMBRE_CONFIRMADO después de 008-011.
\set ON_ERROR_STOP on
\if :{?app_role}
\else
\echo 'ERROR: falta -v app_role=NOMBRE_CONFIRMADO'
\quit 3
\endif

BEGIN;
REVOKE ALL ON provincias,localidades,operaciones_estado FROM PUBLIC;
REVOKE ALL ON SEQUENCE provincias_id_seq,localidades_id_seq,operaciones_estado_id_seq FROM PUBLIC;

GRANT SELECT,INSERT,UPDATE ON provincias,localidades TO :"app_role";
GRANT USAGE ON SEQUENCE provincias_id_seq,localidades_id_seq TO :"app_role";
REVOKE DELETE,TRUNCATE,REFERENCES,TRIGGER ON provincias,localidades FROM :"app_role";

GRANT SELECT,INSERT ON operaciones_estado TO :"app_role";
GRANT USAGE ON SEQUENCE operaciones_estado_id_seq TO :"app_role";
REVOKE UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER ON operaciones_estado FROM :"app_role";

REVOKE EXECUTE ON FUNCTION multipro_modulo_disponible(INTEGER),multipro_pendientes_economicos(INTEGER) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION multipro_modulo_disponible(INTEGER),multipro_pendientes_economicos(INTEGER) TO :"app_role";
COMMIT;
