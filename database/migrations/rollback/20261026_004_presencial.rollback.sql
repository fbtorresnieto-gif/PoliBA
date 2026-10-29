BEGIN;
SET LOCAL lock_timeout='5s';
LOCK TABLE reservas, reserva_movimientos_presenciales IN ACCESS EXCLUSIVE MODE;
DO $$ BEGIN
    IF EXISTS(SELECT 1 FROM reserva_movimientos_presenciales) OR EXISTS(SELECT 1 FROM reservas WHERE modalidad_pago='presencial') THEN
        RAISE EXCEPTION 'Rollback 004 rechazado: existen reservas o movimientos presenciales';
    END IF;
END $$;
DROP TRIGGER reserva_presencial_inmutable ON reservas;
DROP TRIGGER entidad_presencial_inmutable ON espacios;
DROP TABLE reserva_movimientos_presenciales;
DROP FUNCTION multipro_validar_movimiento_presencial(),multipro_presencial_inmutable(),
    multipro_reserva_presencial_inmutable(),multipro_entidad_presencial_inmutable();
COMMIT;
