-- Conserva fecha_le y su historia; retira únicamente la automatización nueva.
BEGIN;
SET LOCAL lock_timeout='5s';

DROP INDEX IF EXISTS idx_inscripcion_lista_espera_orden;
ALTER TABLE inscripcion
    DROP CONSTRAINT IF EXISTS chk_inscripcion_fecha_le;
DROP TRIGGER IF EXISTS trg_inscripcion_fecha_lista_espera ON inscripcion;
DROP FUNCTION IF EXISTS multipro_registrar_fecha_lista_espera();

COMMIT;
