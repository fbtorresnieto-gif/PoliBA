-- MultiPro: verificacion de solo lectura del rol web luego de 001-007 y permisos.
\set ON_ERROR_STOP on
\if :{?app_role}
\else
\echo 'ERROR: falta -v app_role=NOMBRE_CONFIRMADO'
\quit 3
\endif

SELECT EXISTS (SELECT 1 FROM pg_roles WHERE rolname=:'app_role') AS app_role_existe \gset
\if :app_role_existe
\else
\echo 'ERROR: el rol web indicado no existe'
\quit 3
\endif

SELECT NOT (rolcanlogin AND NOT rolsuper AND NOT rolcreatedb AND NOT rolcreaterole
            AND NOT rolinherit AND NOT rolreplication AND NOT rolbypassrls) AS atributos_invalidos
FROM pg_roles WHERE rolname=:'app_role' \gset
\if :atributos_invalidos
\echo 'ERROR: el rol web tiene atributos administrativos o de herencia no permitidos'
\quit 3
\endif

SELECT EXISTS (
    SELECT 1 FROM pg_auth_members m
    JOIN pg_roles r ON r.oid=m.member
    WHERE r.rolname=:'app_role'
) AS membresias_no_permitidas \gset
\if :membresias_no_permitidas
\echo 'ERROR: el rol web pertenece a otros roles'
\quit 3
\endif

SET ROLE :"app_role";
BEGIN READ ONLY;
DO $$
DECLARE
    objeto TEXT;
    privilegio TEXT;
BEGIN
    IF NOT has_database_privilege(current_user,current_database(),'CONNECT')
       OR has_database_privilege(current_user,current_database(),'CREATE') THEN
        RAISE EXCEPTION 'Privilegios de base incompatibles para el rol web';
    END IF;
    IF NOT has_schema_privilege(current_user,'public','USAGE')
       OR has_schema_privilege(current_user,'public','CREATE') THEN
        RAISE EXCEPTION 'Privilegios de esquema incompatibles para el rol web';
    END IF;

    FOREACH objeto IN ARRAY ARRAY[
        'actividades','api_sesiones','asistencia','categoria','dias','dias_modulos','entidades','espacios',
        'inscripcion','menores','modulos','novedades','password_resets','reservas','roles','subcategorias','usuarios'
    ] LOOP
        FOREACH privilegio IN ARRAY ARRAY['SELECT','INSERT','UPDATE','DELETE'] LOOP
            IF NOT has_table_privilege(current_user,objeto,privilegio) THEN
                RAISE EXCEPTION 'Falta % sobre tabla heredada %', privilegio, objeto;
            END IF;
        END LOOP;
        IF NOT has_sequence_privilege(current_user,objeto || '_id_seq','USAGE') THEN
            RAISE EXCEPTION 'Falta USAGE sobre secuencia heredada %_id_seq', objeto;
        END IF;
    END LOOP;

    IF NOT has_table_privilege(current_user,'pago_conexiones','SELECT')
       OR has_table_privilege(current_user,'pago_conexiones','INSERT,UPDATE,DELETE')
       OR has_sequence_privilege(current_user,'pago_conexiones_id_seq','USAGE') THEN
        RAISE EXCEPTION 'Permisos incompatibles sobre pago_conexiones';
    END IF;

    FOREACH objeto IN ARRAY ARRAY['pago_transacciones','pago_webhook_eventos','pago_reembolsos','notificaciones_email'] LOOP
        FOREACH privilegio IN ARRAY ARRAY['SELECT','INSERT','UPDATE'] LOOP
            IF NOT has_table_privilege(current_user,objeto,privilegio) THEN
                RAISE EXCEPTION 'Falta % sobre %', privilegio, objeto;
            END IF;
        END LOOP;
        IF has_table_privilege(current_user,objeto,'DELETE,TRUNCATE,REFERENCES,TRIGGER') THEN
            RAISE EXCEPTION 'Permiso destructivo no permitido sobre %', objeto;
        END IF;
        IF NOT has_sequence_privilege(current_user,objeto || '_id_seq','USAGE') THEN
            RAISE EXCEPTION 'Falta USAGE sobre secuencia %_id_seq', objeto;
        END IF;
    END LOOP;

    FOREACH objeto IN ARRAY ARRAY['reserva_movimientos_presenciales','inscripcion_promociones'] LOOP
        FOREACH privilegio IN ARRAY ARRAY['SELECT','INSERT'] LOOP
            IF NOT has_table_privilege(current_user,objeto,privilegio) THEN
                RAISE EXCEPTION 'Falta % sobre %', privilegio, objeto;
            END IF;
        END LOOP;
        IF has_table_privilege(current_user,objeto,'UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER') THEN
            RAISE EXCEPTION 'Permiso de mutacion no permitido sobre %', objeto;
        END IF;
        IF NOT has_sequence_privilege(current_user,objeto || '_id_seq','USAGE') THEN
            RAISE EXCEPTION 'Falta USAGE sobre secuencia %_id_seq', objeto;
        END IF;
    END LOOP;
END
$$;

SELECT 'ROLECHECK MULTIPRO OK' AS resultado, current_user AS rol_web;
ROLLBACK;
RESET ROLE;
