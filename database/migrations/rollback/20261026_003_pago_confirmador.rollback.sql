BEGIN;
SET LOCAL lock_timeout='5s';
LOCK TABLE pago_transacciones IN ACCESS EXCLUSIVE MODE;
DO $$ BEGIN
    IF EXISTS(SELECT 1 FROM pago_transacciones WHERE confirmo_reserva) THEN
        RAISE EXCEPTION 'Rollback 003 rechazado: hay pagos confirmadores';
    END IF;
END $$;
DROP TRIGGER conservar_pago_confirmador ON pago_transacciones;
DROP FUNCTION multipro_conservar_pago_confirmador();
DROP INDEX uq_pago_confirmador_reserva;
ALTER TABLE pago_transacciones DROP CONSTRAINT pago_confirmador_aprobado, DROP COLUMN confirmo_reserva;
COMMIT;
