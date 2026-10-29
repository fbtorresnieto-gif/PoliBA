-- MultiPro: pruebas solo en base temporal. No persiste datos sinteticos.
\set ON_ERROR_STOP on
BEGIN;
CREATE FUNCTION pg_temp.rechaza(sql_prueba TEXT) RETURNS void LANGUAGE plpgsql AS $$
DECLARE rechazado BOOLEAN := false;
BEGIN
    BEGIN EXECUTE sql_prueba;
    EXCEPTION WHEN check_violation OR unique_violation OR foreign_key_violation OR restrict_violation OR raise_exception THEN
        rechazado := true;
    END;
    IF NOT rechazado THEN RAISE EXCEPTION 'Prueba negativa acepto datos invalidos: %', sql_prueba; END IF;
END $$;

DO $$
DECLARE e INT; otra INT; espacio INT; usuario INT; reserva INT; gratuita INT;
        conexion BIGINT; conexion_otra BIGINT; pago BIGINT; evento BIGINT; reembolso BIGINT;
        base_count INT; firma TEXT; reserva_firma TEXT;
BEGIN
    SELECT count(*),md5(string_agg(to_jsonb(r)::text,',' ORDER BY id)) INTO base_count,firma FROM reservas r;
    SELECT id,fk_entidad INTO espacio,e FROM espacios WHERE fk_entidad IS NOT NULL ORDER BY id LIMIT 1;
    SELECT id INTO otra FROM entidades WHERE id<>e ORDER BY id LIMIT 1;
    SELECT id INTO usuario FROM usuarios ORDER BY id LIMIT 1;
    IF otra IS NULL OR usuario IS NULL OR espacio IS NULL THEN RAISE EXCEPTION 'Faltan fixtures de referencia'; END IF;
    INSERT INTO pago_conexiones(fk_entidad,proveedor,ambiente,modalidad_conexion,cuenta_proveedor,referencia_credencial)
      VALUES(e,'mercadopago','prueba','oauth','cuenta_sintetica_002',gen_random_uuid()) RETURNING id INTO conexion;
    INSERT INTO pago_conexiones(fk_entidad,proveedor,ambiente,modalidad_conexion,cuenta_proveedor,referencia_credencial)
      VALUES(otra,'mercadopago','prueba','oauth','otra_sintetica_002',gen_random_uuid()) RETURNING id INTO conexion_otra;
    INSERT INTO reservas(fk_espacio,fk_usuario,fecha_de_asistencia,horario)
      VALUES(espacio,usuario,'2099-01-01','01:00') RETURNING id INTO gratuita;
    UPDATE reservas SET estado='cancelado',cancelado_en=now(),cancelacion_origen='usuario',motivo_cancelacion='Prueba sintetica' WHERE id=gratuita;
    PERFORM pg_temp.rechaza(format('INSERT INTO pago_transacciones(fk_reserva,fk_entidad,fk_conexion,importe_ars) VALUES(%s,%s,%s,100)',gratuita,e,conexion));
    INSERT INTO reservas(fk_espacio,fk_usuario,fecha_de_asistencia,horario,estado,modalidad_pago,importe_total_ars,pago_vence_en)
      VALUES(espacio,usuario,'2099-01-02','01:00','pendiente_pago','online',100,now()+interval '15 minutes') RETURNING id INTO reserva;
    PERFORM pg_temp.rechaza(format('INSERT INTO pago_transacciones(fk_reserva,fk_entidad,fk_conexion,importe_ars) VALUES(%s,%s,%s,99)',reserva,e,conexion));
    PERFORM pg_temp.rechaza(format('INSERT INTO pago_transacciones(fk_reserva,fk_entidad,fk_conexion,importe_ars) VALUES(%s,%s,%s,100)',reserva,otra,conexion_otra));
    PERFORM pg_temp.rechaza(format('INSERT INTO pago_transacciones(fk_reserva,fk_entidad,fk_conexion,importe_ars) VALUES(%s,%s,%s,100)',reserva,e,conexion_otra));
    INSERT INTO pago_transacciones(fk_reserva,fk_entidad,fk_conexion,importe_ars) VALUES(reserva,e,conexion,100) RETURNING id INTO pago;
    PERFORM pg_temp.rechaza(format('UPDATE pago_transacciones SET importe_ars=101 WHERE id=%s',pago));
    PERFORM pg_temp.rechaza(format('UPDATE reservas SET importe_total_ars=101 WHERE id=%s',reserva));
    PERFORM pg_temp.rechaza(format('UPDATE espacios SET fk_entidad=%s WHERE id=%s',otra,espacio));
    PERFORM pg_temp.rechaza(format('UPDATE pago_conexiones SET cuenta_proveedor=''cuenta_cambiada'' WHERE id=%s',conexion));
    PERFORM pg_temp.rechaza(format('UPDATE pago_transacciones SET estado=''aprobado'' WHERE id=%s',pago));
    PERFORM pg_temp.rechaza(format('INSERT INTO pago_reembolsos(fk_pago,fk_conexion,importe_ars,motivo) VALUES(%s,%s,100,''pago_tardio'')',pago,conexion));
    UPDATE pago_transacciones SET estado='aprobado',pago_proveedor_id='pago_sintetico_002',verificado_en=now() WHERE id=pago;
    PERFORM pg_temp.rechaza(format('INSERT INTO pago_transacciones(fk_reserva,fk_entidad,fk_conexion,importe_ars,pago_proveedor_id) VALUES(%s,%s,%s,100,''pago_sintetico_002'')',reserva,e,conexion));
    INSERT INTO pago_webhook_eventos(fk_conexion,evento_proveedor_id,recurso_proveedor_id,tipo,fk_pago,firma_validada_en)
      VALUES(conexion,'evento_sintetico_002','pago_sintetico_002','payment',pago,now()) RETURNING id INTO evento;
    PERFORM pg_temp.rechaza(format('INSERT INTO pago_webhook_eventos(fk_conexion,evento_proveedor_id,recurso_proveedor_id,tipo,firma_validada_en) VALUES(%s,''evento_sintetico_002'',''pago_sintetico_002'',''payment'',now())',conexion));
    PERFORM pg_temp.rechaza(format('UPDATE pago_webhook_eventos SET fk_conexion=%s WHERE id=%s',conexion_otra,evento));
    PERFORM pg_temp.rechaza(format('UPDATE pago_webhook_eventos SET estado=''procesado'' WHERE id=%s',evento));
    UPDATE pago_webhook_eventos SET estado='procesando',intentos=1,bloqueo_hasta=now()+interval '1 minute' WHERE id=evento;
    UPDATE pago_webhook_eventos SET estado='procesado',procesado_en=now(),bloqueo_hasta=NULL WHERE id=evento;
    PERFORM pg_temp.rechaza(format('INSERT INTO pago_reembolsos(fk_pago,fk_conexion,importe_ars,motivo) VALUES(%s,%s,99,''pago_tardio'')',pago,conexion));
    INSERT INTO pago_reembolsos(fk_pago,fk_conexion,importe_ars,motivo) VALUES(pago,conexion,100,'pago_tardio') RETURNING id INTO reembolso;
    PERFORM pg_temp.rechaza(format('INSERT INTO pago_reembolsos(fk_pago,fk_conexion,importe_ars,motivo) VALUES(%s,%s,100,''pago_tardio'')',pago,conexion));
    PERFORM pg_temp.rechaza(format('UPDATE pago_reembolsos SET estado=''aprobado'' WHERE id=%s',reembolso));
    UPDATE pago_reembolsos SET estado='aprobado',reembolso_proveedor_id='reembolso_sintetico_002',verificado_en=now() WHERE id=reembolso;
    PERFORM pg_temp.rechaza(format('DELETE FROM reservas WHERE id=%s',reserva));
    PERFORM pg_temp.rechaza(format('DELETE FROM pago_conexiones WHERE id=%s',conexion));
    SELECT md5(string_agg(to_jsonb(r)::text,',' ORDER BY id)) INTO reserva_firma FROM reservas r WHERE id NOT IN(reserva,gratuita);
    IF firma IS DISTINCT FROM reserva_firma OR (SELECT count(*) FROM reservas)<>base_count+2 THEN
      RAISE EXCEPTION 'Se alteraron reservas preexistentes';
    END IF;
    RAISE NOTICE 'OK: 19 rechazos esperados, flujo pago/webhook/reembolso y reserva gratuita; historico intacto';
END $$;
ROLLBACK;
