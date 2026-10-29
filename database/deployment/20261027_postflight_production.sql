-- MultiPro: verificación posterior a migraciones 001-007.
-- Es de solo lectura y exige que el pago online continúe bloqueado.
\set ON_ERROR_STOP on
BEGIN READ ONLY;

DO $$
DECLARE
    faltantes TEXT;
BEGIN
    SELECT string_agg(nombre, ', ' ORDER BY nombre) INTO faltantes
    FROM (VALUES
        ('pago_conexiones'),
        ('pago_transacciones'),
        ('pago_webhook_eventos'),
        ('pago_reembolsos'),
        ('reserva_movimientos_presenciales'),
        ('password_resets'),
        ('inscripcion_promociones'),
        ('notificaciones_email')
    ) AS requeridas(nombre)
    WHERE to_regclass('public.' || nombre) IS NULL;
    IF faltantes IS NOT NULL THEN
        RAISE EXCEPTION 'Faltan tablas del MVP: %', faltantes;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema='public' AND table_name='pago_transacciones' AND column_name='confirmo_reserva'
    ) THEN
        RAISE EXCEPTION 'Falta la migración 003: pago_transacciones.confirmo_reserva';
    END IF;

    IF to_regclass('public.uq_reserva_ocupante_espacio_fecha_hora') IS NULL
       OR to_regclass('public.uq_reserva_ocupante_usuario_fecha_hora') IS NULL
       OR to_regclass('public.uq_pago_confirmador_reserva') IS NULL THEN
        RAISE EXCEPTION 'Faltan índices críticos de reservas o pagos';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname='validar_movimiento_presencial' AND NOT tgisinternal)
       OR NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname='reserva_presencial_inmutable' AND NOT tgisinternal)
       OR NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname='validar_pago' AND NOT tgisinternal) THEN
        RAISE EXCEPTION 'Faltan triggers críticos de integridad';
    END IF;

    IF EXISTS (SELECT 1 FROM espacios WHERE cobro_online_habilitado) THEN
        RAISE EXCEPTION 'Pago online quedó habilitado y debe permanecer bloqueado en este MVP';
    END IF;

    IF EXISTS (SELECT 1 FROM pago_conexiones) THEN
        RAISE EXCEPTION 'Existen conexiones de proveedor; no pertenecen a este despliegue';
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema='public' AND table_name='password_resets' AND column_name IN ('token','email')
    ) OR NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema='public' AND table_name='password_resets' AND column_name='token_hash'
    ) THEN
        RAISE EXCEPTION 'La migración 005 de recuperación segura no está completa';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname='promocion_inmutable' AND NOT tgisinternal)
       OR NOT EXISTS (
           SELECT 1 FROM pg_constraint
           WHERE conrelid='public.notificaciones_email'::regclass
             AND contype='u'
             AND conkey=ARRAY[(SELECT attnum FROM pg_attribute WHERE attrelid='public.notificaciones_email'::regclass AND attname='clave_evento')]::smallint[]
       ) THEN
        RAISE EXCEPTION 'La migración 006 de notificaciones no está completa';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='notificaciones_email' AND column_name='fk_reserva')
       OR NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='notificaciones_email' AND column_name='requiere_reserva_confirmada') THEN
        RAISE EXCEPTION 'La migración 007 de recordatorios no está completa';
    END IF;
END
$$;

SELECT 'POSTCHECK MULTIPRO OK' AS resultado,
       current_database() AS base,
       current_user AS rol_migrador;
SELECT count(*) AS reservas_despues,
       count(*) FILTER (WHERE estado = 'reservado') AS reservadas_despues,
       count(*) FILTER (WHERE estado = 'cancelado') AS canceladas_despues,
       count(*) FILTER (WHERE estado = 'pendiente_presencial') AS presenciales_pendientes
FROM reservas;
SELECT count(*) AS espacios_despues,
       count(*) FILTER (WHERE precio_hora_ars > 0) AS espacios_con_precio,
       count(*) FILTER (WHERE pago_presencial_habilitado) AS presencial_habilitado,
       count(*) FILTER (WHERE cobro_online_habilitado) AS online_habilitado
FROM espacios;
SELECT count(*) AS movimientos_presenciales FROM reserva_movimientos_presenciales;
SELECT count(*) AS recuperaciones_nuevas,
       count(*) FILTER (WHERE usado_en IS NULL AND enviado_en IS NOT NULL AND expires_at>CURRENT_TIMESTAMP) AS recuperaciones_activas
FROM password_resets;
SELECT count(*) AS promociones_registradas FROM inscripcion_promociones;
SELECT count(*) AS notificaciones_total,
       count(*) FILTER (WHERE estado='pendiente') AS notificaciones_pendientes,
       count(*) FILTER (WHERE estado='error') AS notificaciones_con_error
FROM notificaciones_email;

ROLLBACK;
