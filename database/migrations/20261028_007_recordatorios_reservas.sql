-- MultiPro - Confirmacion gratuita y recordatorios de turnos.
BEGIN;
SET LOCAL lock_timeout='5s';

ALTER TABLE notificaciones_email
    DROP CONSTRAINT notificaciones_email_tipo_check,
    ADD COLUMN fk_reserva BIGINT REFERENCES reservas(id) ON DELETE RESTRICT,
    ADD COLUMN requiere_reserva_confirmada BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE notificaciones_email ADD CONSTRAINT notificaciones_email_tipo_check CHECK (tipo IN (
    'promocion_modulo','reserva_gratuita_creada','reserva_presencial_creada',
    'cobro_presencial_registrado','reserva_cancelada','devolucion_pendiente',
    'devolucion_realizada','recordatorio_turno'
));

CREATE INDEX idx_notificaciones_email_reserva ON notificaciones_email(fk_reserva,creado_en DESC)
    WHERE fk_reserva IS NOT NULL;

COMMIT;
