BEGIN;
SET LOCAL lock_timeout='5s';
DO $$ BEGIN
    IF EXISTS(SELECT 1 FROM provincias WHERE codigo_georef IS NOT NULL) OR EXISTS(SELECT 1 FROM localidades WHERE codigo_georef IS NOT NULL) THEN
        RAISE EXCEPTION 'Catálogo oficial cargado: conservar códigos e historia, no eliminar datos mediante rollback';
    END IF;
END $$;
DROP INDEX localidades_provincia_nombre;
DROP INDEX localidades_manual_nombre_unico;
ALTER TABLE localidades DROP COLUMN codigo_georef,DROP COLUMN departamento_id,DROP COLUMN departamento,DROP COLUMN categoria;
ALTER TABLE provincias DROP COLUMN codigo_georef;
CREATE UNIQUE INDEX localidades_nombre_unico ON localidades(fk_provincia,lower(nombre));
COMMIT;
