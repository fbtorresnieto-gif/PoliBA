-- MultiPro: politica EXCLUSIVA del rol de staging local. No usar en Railway.
-- Ejecutar en la misma transaccion que 002 o como transaccion independiente.
DO $$ BEGIN
    IF current_database() <> 'multipro_staging_local'
       AND current_database() !~ '^multipro_test_002_[0-9]{14}$' THEN
        RAISE EXCEPTION 'Politica de permisos permitida solo en staging o prueba local';
    END IF;
END $$;

REVOKE ALL PRIVILEGES ON TABLE pago_conexiones, pago_transacciones,
    pago_webhook_eventos, pago_reembolsos FROM PUBLIC, multipro_app_local;
REVOKE ALL PRIVILEGES ON SEQUENCE pago_conexiones_id_seq, pago_transacciones_id_seq,
    pago_webhook_eventos_id_seq, pago_reembolsos_id_seq FROM PUBLIC, multipro_app_local;
-- Los triggers de reservas/espacios consultan la existencia de pagos como invocador.
GRANT SELECT ON pago_transacciones TO multipro_app_local;
-- Sin servicio de pagos implementado: ninguna escritura de pagos habilitada.
-- No cambia privilegios predeterminados ni permisos del modelo anterior.
