DO $$ BEGIN
    IF current_database()<>'multipro_staging_local' AND current_database() !~ '^multipro_test_pagos_[0-9]{14}$' THEN
        RAISE EXCEPTION 'Permisos presenciales limitados al entorno local';
    END IF;
END $$;
REVOKE ALL ON reserva_movimientos_presenciales FROM PUBLIC,multipro_app_local;
REVOKE ALL ON SEQUENCE reserva_movimientos_presenciales_id_seq FROM PUBLIC,multipro_app_local;
GRANT SELECT,INSERT ON reserva_movimientos_presenciales TO multipro_app_local;
GRANT USAGE ON SEQUENCE reserva_movimientos_presenciales_id_seq TO multipro_app_local;
