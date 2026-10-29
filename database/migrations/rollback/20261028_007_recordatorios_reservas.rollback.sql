BEGIN;
SET LOCAL lock_timeout='5s';
LOCK TABLE notificaciones_email IN ACCESS EXCLUSIVE MODE;
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM notificaciones_email WHERE tipo IN ('reserva_gratuita_creada','recordatorio_turno')) THEN
        RAISE EXCEPTION 'Rollback 007 rechazado: existe historial de confirmaciones gratuitas o recordatorios';
    END IF;
END $$;
DROP INDEX idx_notificaciones_email_reserva;
ALTER TABLE notificaciones_email DROP CONSTRAINT notificaciones_email_tipo_check;
ALTER TABLE notificaciones_email DROP COLUMN requiere_reserva_confirmada,DROP COLUMN fk_reserva;
ALTER TABLE notificaciones_email ADD CONSTRAINT notificaciones_email_tipo_check CHECK (tipo IN (
    'promocion_modulo','reserva_presencial_creada','cobro_presencial_registrado',
    'reserva_cancelada','devolucion_pendiente','devolucion_realizada'
));
COMMIT;
