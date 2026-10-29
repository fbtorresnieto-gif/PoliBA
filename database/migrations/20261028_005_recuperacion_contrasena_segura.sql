-- MultiPro - Recuperacion de contrasena segura y preparada para correo transaccional.
-- Invalida y depura tokens legacy en texto claro; no modifica usuarios ni reservas.
BEGIN;
SET LOCAL lock_timeout = '5s';

LOCK TABLE password_resets IN ACCESS EXCLUSIVE MODE;
LOCK TABLE usuarios IN SHARE MODE;

-- Los tokens anteriores estaban almacenados en texto claro. Todos estaban pensados
-- para durar una hora, por lo que se invalidan y eliminan antes del nuevo contrato.
DELETE FROM password_resets;

ALTER TABLE password_resets
    DROP COLUMN email,
    DROP COLUMN token,
    DROP COLUMN usado,
    ALTER COLUMN created_at TYPE TIMESTAMPTZ
        USING created_at AT TIME ZONE current_setting('TimeZone'),
    ALTER COLUMN created_at SET NOT NULL,
    ALTER COLUMN created_at SET DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN fk_usuario INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    ADD COLUMN token_hash CHAR(64) NOT NULL,
    ADD COLUMN expires_at TIMESTAMPTZ NOT NULL,
    ADD COLUMN enviado_en TIMESTAMPTZ,
    ADD COLUMN usado_en TIMESTAMPTZ,
    ADD CONSTRAINT password_resets_token_hash_hex
        CHECK (token_hash ~ '^[0-9a-f]{64}$'),
    ADD CONSTRAINT password_resets_expiracion_valida
        CHECK (expires_at > created_at),
    ADD CONSTRAINT password_resets_envio_temporal_valido
        CHECK (enviado_en IS NULL OR enviado_en >= created_at),
    ADD CONSTRAINT password_resets_uso_temporal_valido
        CHECK (usado_en IS NULL OR usado_en >= created_at);

CREATE UNIQUE INDEX uq_password_resets_token_hash
    ON password_resets(token_hash);
CREATE UNIQUE INDEX uq_password_resets_activo_usuario
    ON password_resets(fk_usuario)
    WHERE usado_en IS NULL;
CREATE INDEX idx_password_resets_usuario_recientes
    ON password_resets(fk_usuario, created_at DESC);
CREATE INDEX idx_password_resets_expiracion
    ON password_resets(expires_at)
    WHERE usado_en IS NULL;

COMMIT;
