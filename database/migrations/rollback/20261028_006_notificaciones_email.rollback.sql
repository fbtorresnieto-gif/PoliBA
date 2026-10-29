BEGIN;
SET LOCAL lock_timeout='5s';
LOCK TABLE notificaciones_email,inscripcion_promociones IN ACCESS EXCLUSIVE MODE;
DO $$ BEGIN
    IF EXISTS(SELECT 1 FROM notificaciones_email) OR EXISTS(SELECT 1 FROM inscripcion_promociones) THEN
        RAISE EXCEPTION 'Rollback 006 rechazado: existe historial de notificaciones o promociones';
    END IF;
END $$;
DROP TABLE notificaciones_email;
DROP TRIGGER promocion_inmutable ON inscripcion_promociones;
DROP TABLE inscripcion_promociones;
DROP FUNCTION multipro_promocion_inmutable();
COMMIT;
