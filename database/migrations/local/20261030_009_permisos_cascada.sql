-- Solo rol de staging local. La asignación productiva se preparará en su plan de migración.
BEGIN;
GRANT SELECT,INSERT,UPDATE ON provincias,localidades TO multipro_app_local;
GRANT USAGE ON SEQUENCE provincias_id_seq,localidades_id_seq,operaciones_estado_id_seq TO multipro_app_local;
GRANT SELECT,INSERT ON operaciones_estado TO multipro_app_local;
REVOKE UPDATE,DELETE ON operaciones_estado FROM multipro_app_local;
REVOKE DELETE ON provincias,localidades,entidades,actividades,modulos,espacios,inscripcion,asistencia FROM multipro_app_local;
COMMIT;
