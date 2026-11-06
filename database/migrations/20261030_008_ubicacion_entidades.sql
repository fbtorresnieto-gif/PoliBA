-- Ubicación estructurada. No interpreta direcciones históricas.
BEGIN;
SET LOCAL lock_timeout='5s';
CREATE TABLE provincias (id SERIAL PRIMARY KEY, nombre VARCHAR(100) NOT NULL CHECK (length(btrim(nombre)) BETWEEN 1 AND 100));
CREATE UNIQUE INDEX provincias_nombre_unico ON provincias(lower(nombre));
CREATE TABLE localidades (id SERIAL PRIMARY KEY, fk_provincia INTEGER NOT NULL REFERENCES provincias(id) ON DELETE RESTRICT, nombre VARCHAR(100) NOT NULL CHECK (length(btrim(nombre)) BETWEEN 1 AND 100));
CREATE UNIQUE INDEX localidades_nombre_unico ON localidades(fk_provincia,lower(nombre));
ALTER TABLE entidades ADD COLUMN fk_localidad INTEGER REFERENCES localidades(id) ON DELETE RESTRICT;
CREATE INDEX entidades_localidad ON entidades(fk_localidad);
COMMIT;
