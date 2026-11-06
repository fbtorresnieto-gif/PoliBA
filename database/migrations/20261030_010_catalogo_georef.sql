-- Metadatos del catálogo oficial; conserva todos los IDs y vínculos locales existentes.
BEGIN;
SET LOCAL lock_timeout='5s';
ALTER TABLE provincias ADD COLUMN codigo_georef VARCHAR(16) UNIQUE;
ALTER TABLE localidades ADD COLUMN codigo_georef VARCHAR(20) UNIQUE,
    ADD COLUMN departamento_id VARCHAR(16), ADD COLUMN departamento VARCHAR(100), ADD COLUMN categoria VARCHAR(100);
DROP INDEX localidades_nombre_unico;
-- Georef distingue localidades homónimas por código. La unicidad por nombre solo rige para altas manuales.
CREATE UNIQUE INDEX localidades_manual_nombre_unico ON localidades(fk_provincia,lower(nombre)) WHERE codigo_georef IS NULL;
CREATE INDEX localidades_provincia_nombre ON localidades(fk_provincia,nombre);
COMMIT;
