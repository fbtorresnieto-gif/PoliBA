-- Migración: token secreto para compartir reservas por link.
-- Ejecutar UNA sola vez sobre bases ya existentes (local y Railway).
-- Requiere PostgreSQL 13+ (gen_random_uuid() incluido de fábrica).
-- Al agregar la columna con un DEFAULT volátil, PostgreSQL asigna un token
-- distinto a cada reserva existente.

ALTER TABLE reservas
    ADD COLUMN IF NOT EXISTS token_compartir VARCHAR(32) NOT NULL
    DEFAULT replace(gen_random_uuid()::text, '-', '');

CREATE UNIQUE INDEX IF NOT EXISTS uq_reservas_token_compartir
    ON reservas (token_compartir);
