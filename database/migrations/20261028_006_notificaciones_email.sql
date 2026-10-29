-- MultiPro - Bandeja transaccional de notificaciones y auditoria de promociones.
BEGIN;
SET LOCAL lock_timeout='5s';

CREATE TABLE inscripcion_promociones (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    fk_inscripcion INT NOT NULL REFERENCES inscripcion(id) ON DELETE RESTRICT,
    fk_modulo_anterior INT NOT NULL REFERENCES modulos(id) ON DELETE RESTRICT,
    fk_modulo_nuevo INT NOT NULL REFERENCES modulos(id) ON DELETE RESTRICT,
    fk_actor INT NOT NULL REFERENCES usuarios(id) ON DELETE RESTRICT,
    registrado_en TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    CHECK (fk_modulo_anterior <> fk_modulo_nuevo)
);

CREATE INDEX idx_inscripcion_promociones_inscripcion
    ON inscripcion_promociones(fk_inscripcion, registrado_en DESC);

CREATE TABLE notificaciones_email (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    clave_evento VARCHAR(140) NOT NULL UNIQUE
        CHECK (clave_evento ~ '^[a-z0-9:_-]{5,140}$'),
    tipo VARCHAR(40) NOT NULL CHECK (tipo IN (
        'promocion_modulo',
        'reserva_presencial_creada',
        'cobro_presencial_registrado',
        'reserva_cancelada',
        'devolucion_pendiente',
        'devolucion_realizada'
    )),
    fk_usuario INT NOT NULL REFERENCES usuarios(id) ON DELETE RESTRICT,
    datos JSONB NOT NULL CHECK (jsonb_typeof(datos)='object'),
    estado VARCHAR(15) NOT NULL DEFAULT 'pendiente'
        CHECK (estado IN ('pendiente','procesando','enviada','error','omitida')),
    intentos SMALLINT NOT NULL DEFAULT 0 CHECK (intentos BETWEEN 0 AND 20),
    proximo_intento_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    bloqueo_hasta TIMESTAMPTZ,
    codigo_error VARCHAR(40) CHECK (codigo_error ~ '^[A-Z0-9_]+$'),
    proveedor_message_id VARCHAR(255),
    creado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enviado_en TIMESTAMPTZ,
    CHECK (estado <> 'procesando' OR bloqueo_hasta IS NOT NULL),
    CHECK (estado <> 'enviada' OR enviado_en IS NOT NULL)
);

CREATE INDEX idx_notificaciones_email_pendientes
    ON notificaciones_email(estado, proximo_intento_en, bloqueo_hasta, creado_en)
    WHERE estado IN ('pendiente','procesando','error');
CREATE INDEX idx_notificaciones_email_usuario
    ON notificaciones_email(fk_usuario, creado_en DESC);

CREATE FUNCTION multipro_promocion_inmutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Las promociones registradas son auditoria inmutable';
END $$;
CREATE TRIGGER promocion_inmutable BEFORE UPDATE OR DELETE ON inscripcion_promociones
    FOR EACH ROW EXECUTE FUNCTION multipro_promocion_inmutable();

COMMIT;
