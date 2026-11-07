BEGIN;
SET LOCAL lock_timeout='5s';
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM entidades WHERE fk_localidad IS NOT NULL) OR EXISTS (SELECT 1 FROM localidades) OR EXISTS (SELECT 1 FROM provincias) THEN
        RAISE EXCEPTION 'Hay ubicaciones registradas: conservar datos y aplicar una corrección compatible.';
    END IF;
END $$;
ALTER TABLE entidades DROP COLUMN fk_localidad;
DROP TABLE localidades;
DROP TABLE provincias;
COMMIT;
