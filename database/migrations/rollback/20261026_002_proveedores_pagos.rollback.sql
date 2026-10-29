-- MultiPro - rollback 002: rechaza perdida de configuracion o historial.
BEGIN;
SET LOCAL lock_timeout = '5s';
LOCK TABLE pago_conexiones, pago_transacciones, pago_webhook_eventos, pago_reembolsos IN ACCESS EXCLUSIVE MODE;
DO $$ BEGIN
    IF EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public'
        AND table_name='pago_transacciones' AND column_name='confirmo_reserva') THEN
        RAISE EXCEPTION 'Rollback 002 rechazado: revertir primero la migracion 003';
    END IF;
    IF EXISTS (SELECT 1 FROM pago_conexiones) OR EXISTS (SELECT 1 FROM pago_transacciones)
       OR EXISTS (SELECT 1 FROM pago_webhook_eventos) OR EXISTS (SELECT 1 FROM pago_reembolsos) THEN
        RAISE EXCEPTION 'Rollback 002 rechazado: existen configuraciones o historial de pagos';
    END IF;
END $$;
DROP TRIGGER proteger_reserva_pago ON reservas;
DROP TRIGGER proteger_entidad_espacio_pago ON espacios;
DROP TABLE pago_reembolsos, pago_webhook_eventos, pago_transacciones, pago_conexiones;
DROP FUNCTION multipro_validar_reembolso(), multipro_validar_pago(),
    multipro_proteger_reserva_pago(), multipro_proteger_conexion_pago(),
    multipro_proteger_entidad_espacio_pago();
COMMIT;
