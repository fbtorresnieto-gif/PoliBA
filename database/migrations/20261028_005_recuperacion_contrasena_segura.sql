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

-- La solicitud web se registra antes de consultar usuarios o contactar al
-- proveedor. El worker resuelve la cuenta en segundo plano para que la
-- latencia HTTP no revele si el email existe.
CREATE TABLE password_reset_solicitudes (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    email_normalizado VARCHAR(254) NOT NULL,
    email_hash CHAR(64) NOT NULL CHECK (email_hash ~ '^[0-9a-f]{64}$'),
    estado VARCHAR(15) NOT NULL DEFAULT 'pendiente'
        CHECK (estado IN ('pendiente','procesando','procesada','error','omitida')),
    intentos SMALLINT NOT NULL DEFAULT 0 CHECK (intentos BETWEEN 0 AND 5),
    proximo_intento_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    bloqueo_hasta TIMESTAMPTZ,
    codigo_error VARCHAR(40) CHECK (codigo_error ~ '^[A-Z0-9_]+$'),
    creado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    procesado_en TIMESTAMPTZ,
    CHECK (estado <> 'procesando' OR bloqueo_hasta IS NOT NULL),
    CHECK (estado <> 'procesada' OR procesado_en IS NOT NULL)
);

CREATE UNIQUE INDEX uq_password_reset_solicitud_pendiente
    ON password_reset_solicitudes(email_hash)
    WHERE estado IN ('pendiente','procesando','error');
CREATE INDEX idx_password_reset_solicitudes_trabajo
    ON password_reset_solicitudes(estado,proximo_intento_en,bloqueo_hasta,creado_en)
    WHERE estado IN ('pendiente','procesando','error');

COMMIT;
