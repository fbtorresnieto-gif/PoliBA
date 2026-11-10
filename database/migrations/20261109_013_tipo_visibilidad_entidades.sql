-- Distingue entidades públicas y privadas sin ocultar registros existentes.
BEGIN;
SET LOCAL lock_timeout='5s';

ALTER TABLE entidades
    ADD COLUMN IF NOT EXISTS tipo_entidad VARCHAR(7) NOT NULL DEFAULT 'publica';

ALTER TABLE entidades
    DROP CONSTRAINT IF EXISTS chk_entidades_tipo_entidad;
ALTER TABLE entidades
    ADD CONSTRAINT chk_entidades_tipo_entidad
    CHECK (tipo_entidad IN ('publica', 'privada'));

CREATE INDEX IF NOT EXISTS idx_entidades_tipo_estado
    ON entidades (tipo_entidad, estado, id);

COMMIT;
