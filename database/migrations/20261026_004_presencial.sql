-- MultiPro: auditoria presencial, sin proveedor ni movimientos automaticos de dinero.
BEGIN;
SET LOCAL lock_timeout='5s';
CREATE TABLE reserva_movimientos_presenciales (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    fk_reserva INT NOT NULL REFERENCES reservas(id) ON DELETE RESTRICT,
    fk_entidad INT NOT NULL REFERENCES entidades(id) ON DELETE RESTRICT,
    tipo VARCHAR(25) NOT NULL CHECK(tipo IN ('cobro','devolucion_solicitada','devolucion')),
    importe_ars NUMERIC(12,2) NOT NULL CHECK(importe_ars>0 AND importe_ars<>'NaN'::numeric),
    moneda CHAR(3) NOT NULL DEFAULT 'ARS' CHECK(moneda='ARS'),
    fk_actor INT NOT NULL REFERENCES usuarios(id) ON DELETE RESTRICT,
    registrado_en TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    UNIQUE(fk_reserva,tipo)
);
CREATE INDEX idx_presencial_entidad ON reserva_movimientos_presenciales(fk_entidad,registrado_en);
CREATE FUNCTION multipro_validar_movimiento_presencial() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r reservas%ROWTYPE; entidad INT; admin BOOLEAN;
BEGIN
    SELECT * INTO STRICT r FROM reservas WHERE id=NEW.fk_reserva FOR UPDATE;
    SELECT fk_entidad INTO STRICT entidad FROM espacios WHERE id=r.fk_espacio FOR SHARE;
    IF r.modalidad_pago<>'presencial' OR entidad IS DISTINCT FROM NEW.fk_entidad OR
       r.importe_total_ars<>NEW.importe_ars OR r.moneda<>NEW.moneda THEN
        RAISE EXCEPTION 'Movimiento no corresponde a reserva presencial, entidad o importe';
    END IF;
    SELECT EXISTS(SELECT 1 FROM usuarios u JOIN roles ro ON ro.id=u.fk_rol
        WHERE u.id=NEW.fk_actor AND u.estado AND u.fk_entidad=entidad AND lower(ro.nombre)='administrador') INTO admin;
    IF NEW.tipo='cobro' AND (NOT admin OR r.estado<>'pendiente_presencial') THEN
        RAISE EXCEPTION 'Cobro requiere administrador de entidad y reserva pendiente';
    END IF;
    IF NEW.tipo IN ('devolucion_solicitada','devolucion') THEN
        IF r.estado<>'cancelado' OR NOT EXISTS(SELECT 1 FROM reserva_movimientos_presenciales
            WHERE fk_reserva=r.id AND tipo='cobro') THEN
            RAISE EXCEPTION 'Devolucion requiere cancelacion y cobro registrado';
        END IF;
        IF NEW.tipo='devolucion_solicitada' AND (NEW.fk_actor IS DISTINCT FROM r.cancelado_por_usuario OR
           NOT (r.cancelacion_origen='administrador' AND admin OR
                r.cancelacion_origen='usuario' AND NEW.fk_actor=r.fk_usuario AND
                ((r.fecha_de_asistencia+r.horario) AT TIME ZONE 'America/Argentina/Buenos_Aires')>=r.cancelado_en+interval '24 hours')) THEN
            RAISE EXCEPTION 'Cancelacion sin derecho a devolucion total';
        END IF;
        IF NEW.tipo='devolucion' AND (NOT admin OR NOT EXISTS(SELECT 1 FROM reserva_movimientos_presenciales
            WHERE fk_reserva=r.id AND tipo='devolucion_solicitada')) THEN
            RAISE EXCEPTION 'Devolucion requiere administrador y solicitud previa';
        END IF;
    END IF;
    NEW.registrado_en:=clock_timestamp();
    RETURN NEW;
END $$;
CREATE TRIGGER validar_movimiento_presencial BEFORE INSERT ON reserva_movimientos_presenciales
    FOR EACH ROW EXECUTE FUNCTION multipro_validar_movimiento_presencial();
CREATE FUNCTION multipro_presencial_inmutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Los movimientos presenciales son auditoria inmutable'; END $$;
CREATE TRIGGER presencial_inmutable BEFORE UPDATE OR DELETE ON reserva_movimientos_presenciales
    FOR EACH ROW EXECUTE FUNCTION multipro_presencial_inmutable();
CREATE FUNCTION multipro_reserva_presencial_inmutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.modalidad_pago='presencial' AND ROW(NEW.fk_espacio,NEW.fk_usuario,NEW.fecha_de_asistencia,NEW.horario,
       NEW.importe_total_ars,NEW.moneda,NEW.modalidad_pago) IS DISTINCT FROM
       ROW(OLD.fk_espacio,OLD.fk_usuario,OLD.fecha_de_asistencia,OLD.horario,OLD.importe_total_ars,OLD.moneda,OLD.modalidad_pago) THEN
        RAISE EXCEPTION 'Reserva presencial: identidad e importe congelados';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER reserva_presencial_inmutable BEFORE UPDATE ON reservas
    FOR EACH ROW EXECUTE FUNCTION multipro_reserva_presencial_inmutable();
CREATE FUNCTION multipro_entidad_presencial_inmutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.fk_entidad IS DISTINCT FROM OLD.fk_entidad AND EXISTS(SELECT 1 FROM reservas
        WHERE fk_espacio=OLD.id AND modalidad_pago='presencial') THEN
        RAISE EXCEPTION 'Espacio con reservas presenciales: entidad inmutable';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER entidad_presencial_inmutable BEFORE UPDATE ON espacios
    FOR EACH ROW EXECUTE FUNCTION multipro_entidad_presencial_inmutable();
COMMIT;
