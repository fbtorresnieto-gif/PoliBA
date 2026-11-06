BEGIN;
SET LOCAL lock_timeout='5s';
DO $$ BEGIN
IF EXISTS(SELECT 1 FROM operaciones_estado) OR EXISTS(SELECT 1 FROM inscripcion WHERE suspension_operacion IS NOT NULL) OR EXISTS(SELECT 1 FROM entidades WHERE cierre_administrativo_en IS NOT NULL) THEN
RAISE EXCEPTION 'Hay historia de bajas, suspensiones o cierres: no se permite eliminarla'; END IF;
END $$;
DROP TRIGGER guardar_agenda_modulo ON modulos;
DROP TRIGGER guardar_agenda_dias ON dias_modulos;
DROP FUNCTION multipro_guardar_agenda();
DROP TRIGGER guardar_inscripcion ON inscripcion;
DROP TRIGGER guardar_modulo ON modulos;
DROP TRIGGER proteger_administrador ON usuarios;
DROP TRIGGER guardar_asistencia ON asistencia;
DROP TRIGGER guardar_espacio ON espacios;
DROP TRIGGER guardar_actividad ON actividades;
DROP TRIGGER guardar_cierre ON entidades;
DROP TRIGGER conservar_entidad ON entidades;
DROP TRIGGER conservar_actividad ON actividades;
DROP TRIGGER conservar_espacio ON espacios;
DROP TRIGGER conservar_modulo ON modulos;
DROP TRIGGER conservar_inscripcion ON inscripcion;
DROP TRIGGER conservar_asistencia ON asistencia;
DROP FUNCTION multipro_guardar_inscripcion();
DROP FUNCTION multipro_guardar_modulo();
DROP FUNCTION multipro_proteger_administrador();
DROP FUNCTION multipro_guardar_asistencia();
DROP FUNCTION multipro_guardar_oferta();
DROP FUNCTION multipro_guardar_cierre();
DROP FUNCTION multipro_conservar_historia();
DROP FUNCTION multipro_modulo_disponible(INTEGER);
DROP FUNCTION multipro_pendientes_economicos(INTEGER);
DROP INDEX inscripciones_suspendidas;
ALTER TABLE inscripcion DROP COLUMN suspension_operacion;
ALTER TABLE entidades DROP COLUMN desactivacion_operacion;
ALTER TABLE actividades DROP COLUMN desactivacion_operacion;
ALTER TABLE modulos DROP COLUMN desactivacion_operacion;
ALTER TABLE espacios DROP COLUMN desactivacion_operacion;
ALTER TABLE entidades DROP COLUMN cierre_administrativo_en;
DROP TABLE operaciones_estado;
CREATE OR REPLACE FUNCTION multipro_validar_movimiento_presencial() RETURNS trigger LANGUAGE plpgsql AS $$
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
COMMIT;
