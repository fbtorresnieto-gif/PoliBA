-- MultiPro: precheck de solo lectura previo a migraciones 001-007.
-- No muestra credenciales ni datos personales.
\set ON_ERROR_STOP on
BEGIN READ ONLY;

DO $$
DECLARE
    version_mayor INT := current_setting('server_version_num')::INT / 10000;
    faltantes TEXT;
BEGIN
    IF version_mayor < 13 THEN
        RAISE EXCEPTION 'PostgreSQL % no soportado: MultiPro requiere 13 o superior', version_mayor;
    END IF;

    SELECT string_agg(nombre, ', ' ORDER BY nombre) INTO faltantes
    FROM (VALUES ('entidades'), ('espacios'), ('reservas'), ('usuarios'), ('roles'), ('inscripcion'), ('modulos')) AS requeridas(nombre)
    WHERE to_regclass('public.' || nombre) IS NULL;
    IF faltantes IS NOT NULL THEN
        RAISE EXCEPTION 'Faltan tablas base requeridas: %', faltantes;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND ((table_name = 'espacios' AND column_name IN ('precio_hora_ars','cobro_online_habilitado','pago_presencial_habilitado'))
            OR (table_name = 'reservas' AND column_name IN ('importe_total_ars','modalidad_pago','pago_vence_en')))
    ) OR to_regclass('public.pago_conexiones') IS NOT NULL
      OR to_regclass('public.pago_transacciones') IS NOT NULL
      OR to_regclass('public.pago_webhook_eventos') IS NOT NULL
      OR to_regclass('public.pago_reembolsos') IS NOT NULL
      OR to_regclass('public.reserva_movimientos_presenciales') IS NOT NULL
      OR to_regclass('public.inscripcion_promociones') IS NOT NULL
      OR to_regclass('public.notificaciones_email') IS NOT NULL THEN
        RAISE EXCEPTION 'Esquema parcial o ya migrado: no aplicar 001-007 automáticamente';
    END IF;

    IF EXISTS (SELECT 1 FROM reservas WHERE estado IS NULL OR estado NOT IN ('reservado','cancelado')) THEN
        RAISE EXCEPTION 'Existen estados de reserva incompatibles con la migración 001';
    END IF;

    IF to_regclass('public.password_resets') IS NULL THEN
        RAISE EXCEPTION 'Falta la tabla base password_resets';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema='public' AND table_name='password_resets' AND column_name='token'
    ) OR EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema='public' AND table_name='password_resets' AND column_name='token_hash'
    ) THEN
        RAISE EXCEPTION 'password_resets ya fue migrada parcialmente o no tiene el contrato legacy esperado';
    END IF;

    IF EXISTS (
        SELECT 1 FROM reservas
        WHERE estado = 'reservado'
        GROUP BY fk_espacio, fecha_de_asistencia, horario
        HAVING count(*) > 1
    ) THEN
        RAISE EXCEPTION 'Hay reservas activas duplicadas para el mismo espacio y turno';
    END IF;

    IF EXISTS (
        SELECT 1 FROM reservas
        WHERE estado = 'reservado'
        GROUP BY fk_usuario, fecha_de_asistencia, horario
        HAVING count(*) > 1
    ) THEN
        RAISE EXCEPTION 'Un usuario tiene más de una reserva activa en el mismo turno';
    END IF;
END
$$;

SELECT 'PRECHECK MULTIPRO OK' AS resultado,
       current_setting('server_version') AS postgres_version,
       current_database() AS base,
       current_user AS rol_migrador;
SELECT count(*) AS reservas_antes,
       count(*) FILTER (WHERE estado = 'reservado') AS reservadas_antes,
       count(*) FILTER (WHERE estado = 'cancelado') AS canceladas_antes
FROM reservas;
SELECT count(*) AS espacios_antes FROM espacios;
SELECT count(*) AS recuperaciones_antes,
       count(*) FILTER (WHERE usado=FALSE AND created_at>CURRENT_TIMESTAMP-INTERVAL '1 hour') AS recuperaciones_activas_antes
FROM password_resets;

ROLLBACK;
