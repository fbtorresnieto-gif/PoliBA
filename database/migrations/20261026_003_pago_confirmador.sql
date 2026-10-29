-- MultiPro: conservar la identidad del cobro que obtuvo el turno.
BEGIN;
SET LOCAL lock_timeout='5s';
ALTER TABLE pago_transacciones ADD COLUMN confirmo_reserva BOOLEAN NOT NULL DEFAULT FALSE,
    ADD CONSTRAINT pago_confirmador_aprobado CHECK (NOT confirmo_reserva OR estado IN ('aprobado','reembolsado','contracargo'));
CREATE UNIQUE INDEX uq_pago_confirmador_reserva ON pago_transacciones(fk_reserva) WHERE confirmo_reserva;
CREATE FUNCTION multipro_conservar_pago_confirmador() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.confirmo_reserva AND NOT NEW.confirmo_reserva THEN
        RAISE EXCEPTION 'El pago confirmador forma parte del historial inmutable';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER conservar_pago_confirmador BEFORE UPDATE ON pago_transacciones
    FOR EACH ROW EXECUTE FUNCTION multipro_conservar_pago_confirmador();
COMMIT;
