-- Registra y protege la fecha real de ingreso a lista de espera.
BEGIN;
SET LOCAL lock_timeout='5s';

ALTER TABLE inscripcion
    ADD COLUMN IF NOT EXISTS fecha_le TIMESTAMP;

-- Recupera las filas que ya estaban esperando antes de esta corrección.
UPDATE inscripcion
SET fecha_le = COALESCE(fecha_le, fecha, CURRENT_TIMESTAMP)
WHERE lista_espera IS TRUE
  AND fecha_le IS NULL;

CREATE OR REPLACE FUNCTION multipro_registrar_fecha_lista_espera()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.lista_espera IS TRUE
       AND (TG_OP = 'INSERT' OR OLD.lista_espera IS DISTINCT FROM TRUE) THEN
        -- Cada reingreso obtiene una nueva posición cronológica.
        NEW.fecha_le := clock_timestamp();
    ELSIF NEW.lista_espera IS TRUE AND NEW.fecha_le IS NULL THEN
        -- Protección ante actualizaciones de filas históricas incompletas.
        NEW.fecha_le := COALESCE(NEW.fecha, clock_timestamp());
    END IF;

    -- Al salir de la espera se conserva la fecha como historia.
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_inscripcion_fecha_lista_espera ON inscripcion;
CREATE TRIGGER trg_inscripcion_fecha_lista_espera
BEFORE INSERT OR UPDATE OF lista_espera, fecha_le ON inscripcion
FOR EACH ROW
EXECUTE FUNCTION multipro_registrar_fecha_lista_espera();

ALTER TABLE inscripcion
    DROP CONSTRAINT IF EXISTS chk_inscripcion_fecha_le;
ALTER TABLE inscripcion
    ADD CONSTRAINT chk_inscripcion_fecha_le
    CHECK (lista_espera IS NOT TRUE OR fecha_le IS NOT NULL);

CREATE INDEX IF NOT EXISTS idx_inscripcion_lista_espera_orden
    ON inscripcion (fk_modulo, fecha_le, id)
    WHERE lista_espera IS TRUE AND estado = 'activo';

COMMIT;
