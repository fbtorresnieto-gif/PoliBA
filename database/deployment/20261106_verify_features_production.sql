-- Verificación de solo lectura posterior a 008-011 y sus permisos.
\set ON_ERROR_STOP on
\if :{?app_role}
\else
\echo 'ERROR: falta -v app_role=NOMBRE_CONFIRMADO'
\quit 3
\endif

BEGIN READ ONLY;
DO $$
DECLARE faltantes TEXT;
BEGIN
    SELECT string_agg(nombre,', ' ORDER BY nombre) INTO faltantes
    FROM (VALUES('provincias'),('localidades'),('operaciones_estado')) requeridas(nombre)
    WHERE to_regclass('public.'||nombre) IS NULL;
    IF faltantes IS NOT NULL THEN RAISE EXCEPTION 'Faltan tablas de 008-011: %',faltantes; END IF;

    IF NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='entidades' AND column_name='fk_localidad')
       OR NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='provincias' AND column_name='codigo_georef')
       OR NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='asistencia' AND column_name='registrado_por')
       OR NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='asistencia' AND column_name='actualizado_en') THEN
        RAISE EXCEPTION 'Faltan columnas de ubicación o auditoría';
    END IF;

    IF NOT EXISTS(SELECT 1 FROM pg_trigger WHERE tgname='guardar_asistencia' AND NOT tgisinternal)
       OR NOT EXISTS(SELECT 1 FROM pg_trigger WHERE tgname='guardar_cierre' AND NOT tgisinternal)
       OR NOT EXISTS(SELECT 1 FROM pg_trigger WHERE tgname='conservar_entidad' AND NOT tgisinternal) THEN
        RAISE EXCEPTION 'Faltan triggers de cascada o integridad';
    END IF;
END $$;
ROLLBACK;

SET ROLE :"app_role";
BEGIN READ ONLY;
DO $$ BEGIN
    IF NOT has_table_privilege(current_user,'provincias','SELECT,INSERT,UPDATE')
       OR NOT has_table_privilege(current_user,'localidades','SELECT,INSERT,UPDATE')
       OR has_table_privilege(current_user,'provincias','DELETE')
       OR has_table_privilege(current_user,'localidades','DELETE') THEN
        RAISE EXCEPTION 'Permisos incompatibles sobre catálogo de ubicación';
    END IF;
    IF NOT has_table_privilege(current_user,'operaciones_estado','SELECT,INSERT')
       OR has_table_privilege(current_user,'operaciones_estado','UPDATE,DELETE') THEN
        RAISE EXCEPTION 'Permisos incompatibles sobre auditoría de estados';
    END IF;
    IF NOT has_function_privilege(current_user,'multipro_modulo_disponible(integer)','EXECUTE')
       OR NOT has_function_privilege(current_user,'multipro_pendientes_economicos(integer)','EXECUTE') THEN
        RAISE EXCEPTION 'Faltan permisos de consulta funcional';
    END IF;
END $$;
SELECT count(*) FROM provincias;
SELECT count(*) FROM localidades;
SELECT multipro_pendientes_economicos(id) FROM entidades ORDER BY id LIMIT 1;
ROLLBACK;
RESET ROLE;

SELECT 'FEATURECHECK MULTIPRO OK' AS resultado;
