-- MultiPro: permisos mínimos para el rol web productivo.
-- Ejecutar con psql -v app_role=NOMBRE_CONFIRMADO. No usar el rol migrador.
\set ON_ERROR_STOP on
\if :{?app_role}
\else
\echo 'ERROR: falta -v app_role=NOMBRE_CONFIRMADO'
\quit 3
\endif

BEGIN;
REVOKE ALL ON pago_conexiones,pago_transacciones,pago_webhook_eventos,pago_reembolsos,
    reserva_movimientos_presenciales,inscripcion_promociones,notificaciones_email
    FROM PUBLIC;
REVOKE ALL ON SEQUENCE pago_conexiones_id_seq,pago_transacciones_id_seq,
    pago_webhook_eventos_id_seq,pago_reembolsos_id_seq,reserva_movimientos_presenciales_id_seq,
    inscripcion_promociones_id_seq,notificaciones_email_id_seq
    FROM PUBLIC;

GRANT SELECT ON pago_conexiones TO :"app_role";
GRANT SELECT,INSERT,UPDATE ON pago_transacciones,pago_webhook_eventos,pago_reembolsos TO :"app_role";
GRANT SELECT,INSERT ON reserva_movimientos_presenciales,inscripcion_promociones TO :"app_role";
GRANT SELECT,INSERT,UPDATE ON notificaciones_email TO :"app_role";
GRANT USAGE ON SEQUENCE pago_transacciones_id_seq,pago_webhook_eventos_id_seq,
    pago_reembolsos_id_seq,reserva_movimientos_presenciales_id_seq,
    inscripcion_promociones_id_seq,notificaciones_email_id_seq TO :"app_role";

REVOKE INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER ON pago_conexiones FROM :"app_role";
REVOKE DELETE,TRUNCATE,REFERENCES,TRIGGER ON pago_transacciones,pago_webhook_eventos,
    pago_reembolsos,notificaciones_email FROM :"app_role";
REVOKE UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER ON reserva_movimientos_presenciales,
    inscripcion_promociones FROM :"app_role";
COMMIT;
