-- Auditoria basica de asistencia sin agregar tablas nuevas.
BEGIN;
SET LOCAL lock_timeout='5s';

ALTER TABLE asistencia
    ADD COLUMN registrado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    ADD COLUMN creado_en TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    ADD COLUMN actualizado_en TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp();

COMMIT;
