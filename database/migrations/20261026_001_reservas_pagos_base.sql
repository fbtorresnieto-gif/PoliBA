-- MultiPro - Base de reservas pagas (MVP)
-- Migración 001
--
-- Alcance:
--   * precio vigente por hora y medios habilitados por espacio;
--   * congelamiento del importe final en la reserva;
--   * retención de 15 minutos para pagos online;
--   * estados de reserva y auditoría de cancelación;
--   * exclusión concurrente por espacio y por usuario.
--
-- Esta migración no crea pagos, webhooks ni credenciales de proveedor.

BEGIN;

LOCK TABLE espacios IN SHARE ROW EXCLUSIVE MODE;
LOCK TABLE reservas IN SHARE ROW EXCLUSIVE MODE;

ALTER TABLE espacios
    ADD COLUMN precio_hora_ars NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
    ADD COLUMN cobro_online_habilitado BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN pago_presencial_habilitado BOOLEAN NOT NULL DEFAULT FALSE,
    ADD CONSTRAINT espacios_precio_hora_ars_no_negativo
        CHECK (precio_hora_ars >= 0.00);

ALTER TABLE reservas
    ALTER COLUMN estado SET NOT NULL,
    ADD COLUMN importe_total_ars NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
    ADD COLUMN moneda CHAR(3) NOT NULL DEFAULT 'ARS',
    ADD COLUMN duracion_minutos SMALLINT NOT NULL DEFAULT 60,
    ADD COLUMN modalidad_pago VARCHAR(20) NOT NULL DEFAULT 'gratuito',
    ADD COLUMN pago_vence_en TIMESTAMPTZ,
    ADD COLUMN creado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN actualizado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN cancelado_en TIMESTAMPTZ,
    ADD COLUMN cancelacion_origen VARCHAR(20),
    ADD COLUMN motivo_cancelacion TEXT,
    ADD COLUMN cancelado_por_usuario INT REFERENCES usuarios(id) ON DELETE SET NULL,
    ADD COLUMN cancelacion_legacy_sin_auditoria BOOLEAN NOT NULL DEFAULT FALSE;

-- Las cancelaciones anteriores a esta migración no poseen metadatos confiables.
-- Se identifican explícitamente para no inventar fecha, actor ni motivo.
UPDATE reservas
SET cancelacion_legacy_sin_auditoria = TRUE
WHERE estado = 'cancelado';

ALTER TABLE reservas
    ADD CONSTRAINT reservas_estado_valido
        CHECK (estado IN (
            'pendiente_pago',
            'pendiente_presencial',
            'reservado',
            'cancelado',
            'vencido'
        )),
    ADD CONSTRAINT reservas_importe_total_ars_no_negativo
        CHECK (importe_total_ars >= 0.00),
    ADD CONSTRAINT reservas_moneda_ars
        CHECK (moneda = 'ARS'),
    ADD CONSTRAINT reservas_duracion_mvp_una_hora
        CHECK (duracion_minutos = 60),
    ADD CONSTRAINT reservas_modalidad_pago_valida
        CHECK (modalidad_pago IN ('gratuito', 'online', 'presencial')),
    ADD CONSTRAINT reservas_estado_modalidad_coherente
        CHECK (
            estado <> 'pendiente_presencial'
            OR modalidad_pago = 'presencial'
        ),
    ADD CONSTRAINT reservas_importe_modalidad_coherente
        CHECK (
            (modalidad_pago = 'gratuito' AND importe_total_ars = 0.00)
            OR
            (modalidad_pago IN ('online', 'presencial') AND importe_total_ars > 0.00)
        ),
    ADD CONSTRAINT reservas_pendiente_pago_con_vencimiento
        CHECK (
            estado <> 'pendiente_pago'
            OR (
                modalidad_pago = 'online'
                AND pago_vence_en IS NOT NULL
                AND pago_vence_en > creado_en
                AND pago_vence_en <= creado_en + INTERVAL '15 minutes'
            )
        ),
    ADD CONSTRAINT reservas_cancelacion_origen_valido
        CHECK (
            cancelacion_origen IS NULL
            OR cancelacion_origen IN ('usuario', 'administrador', 'sistema')
        ),
    ADD CONSTRAINT reservas_cancelacion_auditada
        CHECK (
            estado <> 'cancelado'
            OR cancelacion_legacy_sin_auditoria
            OR (
                cancelado_en IS NOT NULL
                AND cancelacion_origen IS NOT NULL
                AND NULLIF(BTRIM(motivo_cancelacion), '') IS NOT NULL
            )
        );

-- El esquema versionado antiguo tenía una restricción UNIQUE total; la base
-- actualmente desplegada posee un índice parcial. Se normalizan ambos casos.
ALTER TABLE reservas
    DROP CONSTRAINT IF EXISTS uq_reserva_cancha_fecha_hora;

DROP INDEX IF EXISTS uq_reserva_activa_espacio_fecha_hora;

CREATE UNIQUE INDEX uq_reserva_ocupante_espacio_fecha_hora
    ON reservas (fk_espacio, fecha_de_asistencia, horario)
    WHERE estado IN ('pendiente_pago', 'pendiente_presencial', 'reservado');

CREATE UNIQUE INDEX uq_reserva_ocupante_usuario_fecha_hora
    ON reservas (fk_usuario, fecha_de_asistencia, horario)
    WHERE estado IN ('pendiente_pago', 'pendiente_presencial', 'reservado');

CREATE INDEX idx_reservas_pago_pendiente_vencimiento
    ON reservas (pago_vence_en)
    WHERE estado = 'pendiente_pago';

CREATE INDEX idx_reservas_usuario_historial
    ON reservas (fk_usuario, fecha_de_asistencia DESC, horario DESC);

COMMIT;
