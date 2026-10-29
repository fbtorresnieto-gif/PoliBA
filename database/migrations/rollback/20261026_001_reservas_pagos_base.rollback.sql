-- MultiPro - Rollback protegido de la migración 001
--
-- Se niega a continuar si ya existen reservas o precios del nuevo modelo.
-- Esto evita una pérdida silenciosa de información de pagos.

BEGIN;

LOCK TABLE espacios IN SHARE ROW EXCLUSIVE MODE;
LOCK TABLE reservas IN SHARE ROW EXCLUSIVE MODE;

DO $$
BEGIN
    IF to_regclass('public.reserva_movimientos_presenciales') IS NOT NULL THEN
        RAISE EXCEPTION 'Rollback 001 rechazado: revertir primero la migracion 004';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM espacios
        WHERE precio_hora_ars <> 0.00
           OR cobro_online_habilitado
           OR pago_presencial_habilitado
    ) THEN
        RAISE EXCEPTION
            'Rollback rechazado: existen espacios configurados para cobro.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM reservas
        WHERE estado NOT IN ('reservado', 'cancelado')
           OR importe_total_ars <> 0.00
           OR modalidad_pago <> 'gratuito'
           OR pago_vence_en IS NOT NULL
    ) THEN
        RAISE EXCEPTION
            'Rollback rechazado: existen reservas creadas con el nuevo modelo.';
    END IF;
END
$$;

DROP INDEX IF EXISTS idx_reservas_usuario_historial;
DROP INDEX IF EXISTS idx_reservas_pago_pendiente_vencimiento;
DROP INDEX IF EXISTS uq_reserva_ocupante_usuario_fecha_hora;
DROP INDEX IF EXISTS uq_reserva_ocupante_espacio_fecha_hora;

CREATE UNIQUE INDEX uq_reserva_activa_espacio_fecha_hora
    ON reservas (fk_espacio, fecha_de_asistencia, horario)
    WHERE estado = 'reservado';

ALTER TABLE reservas
    DROP CONSTRAINT IF EXISTS reservas_cancelacion_auditada,
    DROP CONSTRAINT IF EXISTS reservas_cancelacion_origen_valido,
    DROP CONSTRAINT IF EXISTS reservas_pendiente_pago_con_vencimiento,
    DROP CONSTRAINT IF EXISTS reservas_importe_modalidad_coherente,
    DROP CONSTRAINT IF EXISTS reservas_estado_modalidad_coherente,
    DROP CONSTRAINT IF EXISTS reservas_modalidad_pago_valida,
    DROP CONSTRAINT IF EXISTS reservas_duracion_mvp_una_hora,
    DROP CONSTRAINT IF EXISTS reservas_moneda_ars,
    DROP CONSTRAINT IF EXISTS reservas_importe_total_ars_no_negativo,
    DROP CONSTRAINT IF EXISTS reservas_estado_valido,
    DROP COLUMN IF EXISTS cancelacion_legacy_sin_auditoria,
    DROP COLUMN IF EXISTS cancelado_por_usuario,
    DROP COLUMN IF EXISTS motivo_cancelacion,
    DROP COLUMN IF EXISTS cancelacion_origen,
    DROP COLUMN IF EXISTS cancelado_en,
    DROP COLUMN IF EXISTS actualizado_en,
    DROP COLUMN IF EXISTS creado_en,
    DROP COLUMN IF EXISTS pago_vence_en,
    DROP COLUMN IF EXISTS modalidad_pago,
    DROP COLUMN IF EXISTS duracion_minutos,
    DROP COLUMN IF EXISTS moneda,
    DROP COLUMN IF EXISTS importe_total_ars;

ALTER TABLE reservas
    ALTER COLUMN estado DROP NOT NULL;

ALTER TABLE espacios
    DROP CONSTRAINT IF EXISTS espacios_precio_hora_ars_no_negativo,
    DROP COLUMN IF EXISTS pago_presencial_habilitado,
    DROP COLUMN IF EXISTS cobro_online_habilitado,
    DROP COLUMN IF EXISTS precio_hora_ars;

COMMIT;
