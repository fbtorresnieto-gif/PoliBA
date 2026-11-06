-- El rollback elimina los metadatos de auditoria registrados desde la migracion.
BEGIN;
SET LOCAL lock_timeout='5s';

DO $$ BEGIN
    IF EXISTS(SELECT 1 FROM asistencia WHERE registrado_por IS NOT NULL) THEN
        RAISE EXCEPTION 'La auditoría de asistencia ya contiene historia; no se puede eliminar';
    END IF;
END $$;

ALTER TABLE asistencia
    DROP COLUMN actualizado_en,
    DROP COLUMN creado_en,
    DROP COLUMN registrado_por;

COMMIT;
