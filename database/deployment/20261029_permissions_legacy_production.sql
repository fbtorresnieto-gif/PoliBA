-- MultiPro: acceso operativo del rol web a las tablas heredadas.
-- Lista explicita para no conceder permisos amplios a futuras tablas de pagos.
\set ON_ERROR_STOP on
\if :{?app_role}
\else
\echo 'ERROR: falta -v app_role=NOMBRE_CONFIRMADO'
\quit 3
\endif

BEGIN;
REVOKE ALL ON
    actividades,api_sesiones,asistencia,categoria,dias,dias_modulos,entidades,espacios,
    inscripcion,menores,modulos,novedades,password_resets,reservas,roles,subcategorias,usuarios
    FROM :"app_role";
REVOKE ALL ON SEQUENCE
    actividades_id_seq,api_sesiones_id_seq,asistencia_id_seq,categoria_id_seq,dias_id_seq,
    dias_modulos_id_seq,entidades_id_seq,espacios_id_seq,inscripcion_id_seq,menores_id_seq,
    modulos_id_seq,novedades_id_seq,password_resets_id_seq,reservas_id_seq,roles_id_seq,
    subcategorias_id_seq,usuarios_id_seq
    FROM :"app_role";

GRANT SELECT,INSERT,UPDATE,DELETE ON
    actividades,api_sesiones,asistencia,categoria,dias,dias_modulos,entidades,espacios,
    inscripcion,menores,modulos,novedades,password_resets,reservas,roles,subcategorias,usuarios
    TO :"app_role";
GRANT USAGE ON SEQUENCE
    actividades_id_seq,api_sesiones_id_seq,asistencia_id_seq,categoria_id_seq,dias_id_seq,
    dias_modulos_id_seq,entidades_id_seq,espacios_id_seq,inscripcion_id_seq,menores_id_seq,
    modulos_id_seq,novedades_id_seq,password_resets_id_seq,reservas_id_seq,roles_id_seq,
    subcategorias_id_seq,usuarios_id_seq
    TO :"app_role";
COMMIT;
