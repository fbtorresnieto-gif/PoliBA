-- Retira la clasificación únicamente si ninguna entidad quedó marcada privada.
BEGIN;
SET LOCAL lock_timeout='5s';

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM entidades WHERE tipo_entidad = 'privada') THEN
        RAISE EXCEPTION 'No se puede retirar tipo_entidad mientras existan entidades privadas';
    END IF;
END
$$;

DROP INDEX IF EXISTS idx_entidades_tipo_estado;
ALTER TABLE entidades DROP CONSTRAINT IF EXISTS chk_entidades_tipo_entidad;
ALTER TABLE entidades DROP COLUMN IF EXISTS tipo_entidad;

COMMIT;
