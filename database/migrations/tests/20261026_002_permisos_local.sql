-- MultiPro: comprobar permisos efectivos y reserva gratuita con rol de aplicacion.
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL ROLE multipro_app_local;
DO $$
DECLARE tabla TEXT; privilegio TEXT; espacio INT; usuario INT; reserva INT;
BEGIN
    FOREACH tabla IN ARRAY ARRAY['pago_conexiones','pago_transacciones','pago_webhook_eventos','pago_reembolsos'] LOOP
        FOREACH privilegio IN ARRAY ARRAY['INSERT','UPDATE','DELETE','TRUNCATE','REFERENCES','TRIGGER'] LOOP
            IF has_table_privilege(current_user, tabla, privilegio) THEN
                RAISE EXCEPTION 'Permiso inesperado: % %',tabla,privilegio;
            END IF;
        END LOOP;
        IF has_sequence_privilege(current_user, tabla || '_id_seq', 'USAGE')
           OR has_sequence_privilege(current_user, tabla || '_id_seq', 'UPDATE') THEN
            RAISE EXCEPTION 'Acceso inesperado a secuencia: %',tabla;
        END IF;
    END LOOP;
    IF NOT has_table_privilege(current_user,'pago_transacciones','SELECT') THEN
        RAISE EXCEPTION 'Falta lectura requerida por triggers de reservas';
    END IF;
    FOREACH tabla IN ARRAY ARRAY['pago_conexiones','pago_webhook_eventos','pago_reembolsos'] LOOP
        IF has_table_privilege(current_user,tabla,'SELECT') THEN
            RAISE EXCEPTION 'Lectura innecesaria: %',tabla;
        END IF;
    END LOOP;
    -- Rechazo real del motor, incluso si no hay filas que borrar.
    BEGIN
        DELETE FROM pago_transacciones WHERE false;
        RAISE EXCEPTION 'Se permitio DELETE de pagos';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    SELECT id INTO espacio FROM espacios ORDER BY id LIMIT 1;
    SELECT id INTO usuario FROM usuarios ORDER BY id LIMIT 1;
    INSERT INTO reservas(fk_espacio,fk_usuario,fecha_de_asistencia,horario)
      VALUES(espacio,usuario,'2099-02-01','01:00') RETURNING id INTO reserva;
    IF NOT EXISTS(SELECT 1 FROM reservas WHERE id=reserva AND modalidad_pago='gratuito'
                  AND importe_total_ars=0 AND estado='reservado') THEN
        RAISE EXCEPTION 'Reserva gratuita no confirmada';
    END IF;
    UPDATE reservas SET estado='cancelado',cancelado_en=now(),cancelacion_origen='usuario',
      motivo_cancelacion='Prueba sintetica de permisos' WHERE id=reserva;
    IF EXISTS(SELECT 1 FROM pago_transacciones WHERE fk_reserva=reserva) THEN
        RAISE EXCEPTION 'Reserva gratuita genero pago';
    END IF;
    RAISE NOTICE 'OK: permisos efectivos y reserva gratuita/cancelacion como aplicacion';
END $$;
ROLLBACK;
