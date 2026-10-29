-- Rollback protegido: solo es posible antes de crear solicitudes con el modelo nuevo.
BEGIN;
SET LOCAL lock_timeout = '5s';
LOCK TABLE password_resets IN ACCESS EXCLUSIVE MODE;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM password_resets) THEN
        RAISE EXCEPTION 'Rollback 005 rechazado: existen solicitudes del modelo seguro';
    END IF;
END
$$;

DROP INDEX idx_password_resets_expiracion;
DROP INDEX idx_password_resets_usuario_recientes;
DROP INDEX uq_password_resets_activo_usuario;
DROP INDEX uq_password_resets_token_hash;

ALTER TABLE password_resets
    DROP CONSTRAINT password_resets_uso_temporal_valido,
    DROP CONSTRAINT password_resets_envio_temporal_valido,
    DROP CONSTRAINT password_resets_expiracion_valida,
    DROP CONSTRAINT password_resets_token_hash_hex,
    DROP COLUMN usado_en,
    DROP COLUMN enviado_en,
    DROP COLUMN expires_at,
    DROP COLUMN token_hash,
    DROP COLUMN fk_usuario,
    ALTER COLUMN created_at TYPE TIMESTAMP WITHOUT TIME ZONE
        USING created_at AT TIME ZONE current_setting('TimeZone'),
    ADD COLUMN email TEXT NOT NULL,
    ADD COLUMN token TEXT NOT NULL,
    ADD COLUMN usado BOOLEAN DEFAULT FALSE;

ALTER TABLE password_resets ADD CONSTRAINT password_resets_token_key UNIQUE(token);
COMMIT;
