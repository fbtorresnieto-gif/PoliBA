-- MultiPro: solo staging y bases temporales locales; sin administrar conexiones ni borrar auditoria.
DO $$ BEGIN
    IF current_database()<>'multipro_staging_local' AND current_database() !~ '^multipro_test_pagos_[0-9]{14}$' THEN
        RAISE EXCEPTION 'Permisos de servicio restringidos a entorno local';
    END IF;
END $$;
GRANT SELECT ON pago_conexiones TO multipro_app_local;
GRANT SELECT,INSERT,UPDATE ON pago_transacciones,pago_webhook_eventos,pago_reembolsos TO multipro_app_local;
GRANT USAGE ON SEQUENCE pago_transacciones_id_seq,pago_webhook_eventos_id_seq,pago_reembolsos_id_seq TO multipro_app_local;
-- Reafirmar prohibiciones incluso frente a permisos predeterminados.
REVOKE DELETE,TRUNCATE,REFERENCES,TRIGGER ON pago_transacciones,pago_webhook_eventos,pago_reembolsos FROM multipro_app_local;
REVOKE INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER ON pago_conexiones FROM multipro_app_local;
