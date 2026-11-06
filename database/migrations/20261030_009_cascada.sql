BEGIN;
SET LOCAL lock_timeout='5s';
CREATE TABLE operaciones_estado (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    fk_entidad INTEGER NOT NULL REFERENCES entidades(id) ON DELETE RESTRICT,
    recurso VARCHAR(20) NOT NULL CHECK (recurso IN ('entidad','actividad','modulo','espacio','administrador')),
    recurso_id INTEGER NOT NULL,
    accion VARCHAR(30) NOT NULL,
    fk_actor INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE RESTRICT,
    motivo VARCHAR(500) NOT NULL CHECK (length(btrim(motivo))>0),
    situacion_anterior JSONB NOT NULL,
    creado_en TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);
CREATE TRIGGER operaciones_estado_inmutable BEFORE UPDATE OR DELETE ON operaciones_estado FOR EACH ROW EXECUTE FUNCTION multipro_presencial_inmutable();
ALTER TABLE entidades ADD COLUMN cierre_administrativo_en TIMESTAMPTZ;
ALTER TABLE entidades ADD COLUMN desactivacion_operacion BIGINT REFERENCES operaciones_estado(id) ON DELETE RESTRICT;
ALTER TABLE actividades ADD COLUMN desactivacion_operacion BIGINT REFERENCES operaciones_estado(id) ON DELETE RESTRICT;
ALTER TABLE espacios ADD COLUMN desactivacion_operacion BIGINT REFERENCES operaciones_estado(id) ON DELETE RESTRICT;
ALTER TABLE modulos ADD COLUMN desactivacion_operacion BIGINT REFERENCES operaciones_estado(id) ON DELETE RESTRICT;
ALTER TABLE inscripcion ADD COLUMN suspension_operacion BIGINT REFERENCES operaciones_estado(id) ON DELETE RESTRICT;
CREATE INDEX inscripciones_suspendidas ON inscripcion(fk_modulo) WHERE estado='suspendido';

CREATE FUNCTION multipro_modulo_disponible(modulo_id INTEGER) RETURNS BOOLEAN LANGUAGE sql STABLE AS $$
    SELECT EXISTS(SELECT 1 FROM modulos m JOIN entidades e ON e.id=m.fk_entidad
        JOIN actividades a ON a.id=m.fk_actividad AND a.fk_entidad=e.id
        LEFT JOIN espacios s ON s.id=m.fk_espacio
        WHERE m.id=modulo_id AND m.estado AND e.estado AND e.cierre_administrativo_en IS NULL AND a.estado
        AND (m.fk_espacio IS NULL OR s.estado AND s.fk_entidad=e.id)
        AND (m.fk_usuario_profesor IS NULL OR EXISTS(SELECT 1 FROM usuarios u WHERE u.id=m.fk_usuario_profesor AND u.estado)))
$$;
CREATE FUNCTION multipro_pendientes_economicos(entidad_id INTEGER) RETURNS BIGINT LANGUAGE sql STABLE AS $$
    SELECT (SELECT count(*) FROM reservas r JOIN espacios s ON s.id=r.fk_espacio
        WHERE s.fk_entidad=entidad_id AND r.modalidad_pago='presencial' AND r.estado='pendiente_presencial'
        AND NOT EXISTS(SELECT 1 FROM reserva_movimientos_presenciales m WHERE m.fk_reserva=r.id AND m.tipo='cobro'))
      + (SELECT count(*) FROM reserva_movimientos_presenciales m WHERE m.fk_entidad=entidad_id AND m.tipo='devolucion_solicitada'
        AND NOT EXISTS(SELECT 1 FROM reserva_movimientos_presenciales d WHERE d.fk_reserva=m.fk_reserva AND d.tipo='devolucion'))
$$;
CREATE FUNCTION multipro_guardar_inscripcion() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(76730009);
    IF NEW.estado='activo' THEN
        IF NOT multipro_modulo_disponible(NEW.fk_modulo) THEN RAISE EXCEPTION 'El módulo o su actividad, espacio o entidad no están activos'; END IF;
        IF EXISTS(SELECT 1 FROM inscripcion i WHERE i.fk_modulo=NEW.fk_modulo AND i.id<>NEW.id
            AND i.estado IN ('activo','suspendido') AND (i.fk_usuario=NEW.fk_usuario OR i.fk_menor=NEW.fk_menor)) THEN
            RAISE EXCEPTION 'La persona ya tiene una inscripción activa o suspendida en el módulo';
        END IF;
        IF NOT NEW.lista_espera AND (SELECT count(*) FROM inscripcion i WHERE i.fk_modulo=NEW.fk_modulo
            AND i.id<>NEW.id AND i.estado='activo' AND NOT i.lista_espera)>=(SELECT cupo_maximo FROM modulos WHERE id=NEW.fk_modulo) THEN
            RAISE EXCEPTION 'El módulo no tiene cupos disponibles';
        END IF;
    END IF;
    IF NEW.estado<>'suspendido' THEN NEW.suspension_operacion=NULL; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER guardar_inscripcion BEFORE INSERT OR UPDATE ON inscripcion FOR EACH ROW EXECUTE FUNCTION multipro_guardar_inscripcion();
CREATE FUNCTION multipro_guardar_modulo() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(76730009);
    IF NEW.estado AND NOT EXISTS(SELECT 1 FROM entidades e JOIN actividades a ON a.fk_entidad=e.id
        WHERE e.id=NEW.fk_entidad AND a.id=NEW.fk_actividad AND e.estado AND e.cierre_administrativo_en IS NULL AND a.estado) THEN
        RAISE EXCEPTION 'Reactivá primero la entidad y la actividad';
    END IF;
    IF NEW.estado AND NEW.fk_espacio IS NOT NULL AND NOT EXISTS(SELECT 1 FROM espacios s WHERE s.id=NEW.fk_espacio AND s.estado AND s.fk_entidad=NEW.fk_entidad) THEN
        RAISE EXCEPTION 'El módulo requiere un espacio activo de la misma entidad';
    END IF;
    IF NEW.estado AND NEW.fk_usuario_profesor IS NOT NULL AND NOT EXISTS(SELECT 1 FROM usuarios WHERE id=NEW.fk_usuario_profesor AND estado) THEN RAISE EXCEPTION 'El profesor asignado está inactivo'; END IF;
    IF TG_OP='UPDATE' AND NEW.cupo_maximo<(SELECT count(*) FROM inscripcion WHERE fk_modulo=NEW.id AND estado='activo' AND NOT lista_espera) THEN
        RAISE EXCEPTION 'El cupo no puede ser inferior a las inscripciones activas';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER guardar_modulo BEFORE INSERT OR UPDATE ON modulos FOR EACH ROW EXECUTE FUNCTION multipro_guardar_modulo();
CREATE FUNCTION multipro_proteger_administrador() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(76730009);
    IF OLD.estado AND EXISTS(SELECT 1 FROM roles WHERE id=OLD.fk_rol AND lower(nombre)='administrador')
       AND (NOT NEW.estado OR NEW.fk_entidad IS DISTINCT FROM OLD.fk_entidad OR NEW.fk_rol<>OLD.fk_rol)
       AND multipro_pendientes_economicos(OLD.fk_entidad)>0
       AND NOT EXISTS(SELECT 1 FROM usuarios u JOIN roles r ON r.id=u.fk_rol WHERE u.id<>OLD.id AND u.fk_entidad=OLD.fk_entidad AND u.estado AND lower(r.nombre)='administrador') THEN
        RAISE EXCEPTION 'No se puede desactivar al último administrador mientras haya cobros o devoluciones pendientes';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER proteger_administrador BEFORE UPDATE ON usuarios FOR EACH ROW EXECUTE FUNCTION multipro_proteger_administrador();
CREATE OR REPLACE FUNCTION multipro_validar_movimiento_presencial() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r reservas%ROWTYPE; entidad INT; admin BOOLEAN; gestor BOOLEAN;
BEGIN
    SELECT * INTO STRICT r FROM reservas WHERE id=NEW.fk_reserva FOR UPDATE;
    SELECT fk_entidad INTO STRICT entidad FROM espacios WHERE id=r.fk_espacio FOR SHARE;
    IF r.modalidad_pago<>'presencial' OR entidad IS DISTINCT FROM NEW.fk_entidad OR
       r.importe_total_ars<>NEW.importe_ars OR r.moneda<>NEW.moneda THEN
        RAISE EXCEPTION 'Movimiento no corresponde a reserva presencial, entidad o importe';
    END IF;
    SELECT EXISTS(SELECT 1 FROM usuarios u JOIN roles ro ON ro.id=u.fk_rol
        WHERE u.id=NEW.fk_actor AND u.estado AND u.fk_entidad=entidad AND lower(ro.nombre)='administrador') INTO admin;
    SELECT EXISTS(SELECT 1 FROM usuarios u JOIN roles ro ON ro.id=u.fk_rol WHERE u.id=NEW.fk_actor AND u.estado AND lower(ro.nombre)='gestor') INTO gestor;
    IF NEW.tipo='cobro' AND (NOT admin OR r.estado<>'pendiente_presencial') THEN
        RAISE EXCEPTION 'Cobro requiere administrador de entidad y reserva pendiente';
    END IF;
    IF NEW.tipo IN ('devolucion_solicitada','devolucion') THEN
        IF r.estado<>'cancelado' OR NOT EXISTS(SELECT 1 FROM reserva_movimientos_presenciales
            WHERE fk_reserva=r.id AND tipo='cobro') THEN
            RAISE EXCEPTION 'Devolucion requiere cancelacion y cobro registrado';
        END IF;
        IF NEW.tipo='devolucion_solicitada' AND (NEW.fk_actor IS DISTINCT FROM r.cancelado_por_usuario OR
           NOT (r.cancelacion_origen='administrador' AND (admin OR gestor) OR
                r.cancelacion_origen='usuario' AND NEW.fk_actor=r.fk_usuario AND
                ((r.fecha_de_asistencia+r.horario) AT TIME ZONE 'America/Argentina/Buenos_Aires')>=r.cancelado_en+interval '5 hours')) THEN
            RAISE EXCEPTION 'Cancelacion sin derecho a devolucion total';
        END IF;
        IF NEW.tipo='devolucion' AND (NOT admin OR NOT EXISTS(SELECT 1 FROM reserva_movimientos_presenciales
            WHERE fk_reserva=r.id AND tipo='devolucion_solicitada')) THEN
            RAISE EXCEPTION 'Devolucion requiere administrador y solicitud previa';
        END IF;
    END IF;
    IF EXISTS(SELECT 1 FROM entidades WHERE id=entidad AND cierre_administrativo_en IS NOT NULL) THEN RAISE EXCEPTION 'La administración de la entidad está cerrada'; END IF;
    NEW.registrado_en:=clock_timestamp();
    RETURN NEW;
END $$;

CREATE FUNCTION multipro_guardar_asistencia() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(76730009);
    IF NEW.fecha>(clock_timestamp() AT TIME ZONE 'America/Argentina/Buenos_Aires')::date THEN
        RAISE EXCEPTION 'No se puede registrar asistencia en una fecha futura';
    END IF;
    IF NOT multipro_modulo_disponible(NEW.fk_modulo) OR NOT EXISTS(SELECT 1 FROM inscripcion WHERE id=NEW.fk_inscripcion AND fk_modulo=NEW.fk_modulo AND estado='activo' AND NOT lista_espera) THEN
        RAISE EXCEPTION 'No se puede registrar asistencia en una inscripción suspendida, cancelada o de otro módulo';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER guardar_asistencia BEFORE INSERT OR UPDATE ON asistencia FOR EACH ROW EXECUTE FUNCTION multipro_guardar_asistencia();
CREATE FUNCTION multipro_guardar_oferta() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(76730009);
    IF NEW.estado AND NOT EXISTS(SELECT 1 FROM entidades WHERE id=NEW.fk_entidad AND estado AND cierre_administrativo_en IS NULL) THEN
        RAISE EXCEPTION 'La oferta requiere una entidad activa';
    END IF;
    IF TG_TABLE_NAME='espacios' AND NOT NEW.estado AND EXISTS(SELECT 1 FROM modulos WHERE fk_espacio=NEW.id AND estado) THEN
        RAISE EXCEPTION 'Reubicá los módulos activos antes de desactivar el espacio';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER guardar_espacio BEFORE INSERT OR UPDATE ON espacios FOR EACH ROW EXECUTE FUNCTION multipro_guardar_oferta();
CREATE TRIGGER guardar_actividad BEFORE INSERT OR UPDATE ON actividades FOR EACH ROW EXECUTE FUNCTION multipro_guardar_oferta();
CREATE FUNCTION multipro_guardar_cierre() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(76730009);
    IF NEW.cierre_administrativo_en IS NOT NULL AND (NEW.estado OR multipro_pendientes_economicos(NEW.id)>0) THEN
        RAISE EXCEPTION 'El cierre requiere entidad inactiva sin cobros ni devoluciones pendientes';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER guardar_cierre BEFORE UPDATE ON entidades FOR EACH ROW EXECUTE FUNCTION multipro_guardar_cierre();
CREATE FUNCTION multipro_conservar_historia() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Este registro se conserva: utilizá la baja lógica'; END $$;
CREATE TRIGGER conservar_entidad BEFORE DELETE ON entidades FOR EACH ROW EXECUTE FUNCTION multipro_conservar_historia();
CREATE TRIGGER conservar_actividad BEFORE DELETE ON actividades FOR EACH ROW EXECUTE FUNCTION multipro_conservar_historia();
CREATE TRIGGER conservar_espacio BEFORE DELETE ON espacios FOR EACH ROW EXECUTE FUNCTION multipro_conservar_historia();
CREATE TRIGGER conservar_modulo BEFORE DELETE ON modulos FOR EACH ROW EXECUTE FUNCTION multipro_conservar_historia();
CREATE TRIGGER conservar_inscripcion BEFORE DELETE ON inscripcion FOR EACH ROW EXECUTE FUNCTION multipro_conservar_historia();
CREATE TRIGGER conservar_asistencia BEFORE DELETE ON asistencia FOR EACH ROW EXECUTE FUNCTION multipro_conservar_historia();
-- Revalidar la agenda completa al confirmar altas, ediciones y reactivaciones.
CREATE FUNCTION multipro_guardar_agenda() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE m modulos%ROWTYPE; modulo_id INTEGER;
BEGIN
    IF TG_TABLE_NAME='dias_modulos' THEN modulo_id:=NEW.fk_modulo; ELSE modulo_id:=NEW.id; END IF;
    SELECT * INTO m FROM modulos WHERE id=modulo_id;
    IF NOT FOUND OR NOT m.estado THEN RETURN NULL; END IF;
    IF EXISTS(SELECT 1 FROM modulos otro JOIN dias_modulos d ON d.fk_modulo=otro.id
        JOIN dias_modulos propio ON propio.fk_modulo=m.id AND propio.fk_dia=d.fk_dia
        WHERE otro.id<>m.id AND otro.estado AND otro.horario_inicio<m.horario_cierre AND otro.horario_cierre>m.horario_inicio
        AND ((otro.fk_entidad=m.fk_entidad AND otro.fk_espacio IS NOT DISTINCT FROM m.fk_espacio)
            OR (m.fk_usuario_profesor IS NOT NULL AND otro.fk_usuario_profesor=m.fk_usuario_profesor))) THEN
        RAISE EXCEPTION 'Hay superposición de espacio o profesor con otro módulo activo';
    END IF;
    IF m.fk_espacio IS NOT NULL AND EXISTS(SELECT 1 FROM reservas r JOIN dias d ON d.orden=extract(isodow FROM r.fecha_de_asistencia)
        JOIN dias_modulos dm ON dm.fk_modulo=m.id AND dm.fk_dia=d.id
        WHERE r.fk_espacio=m.fk_espacio AND r.estado IN ('reservado','pendiente_presencial','pendiente_pago')
        AND ((r.fecha_de_asistencia+r.horario) AT TIME ZONE 'America/Argentina/Buenos_Aires')>clock_timestamp()
        AND r.horario<m.horario_cierre AND r.horario+interval '1 hour'>m.horario_inicio) THEN
        RAISE EXCEPTION 'El horario del módulo se superpone con una reserva futura';
    END IF;
    RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER guardar_agenda_modulo AFTER INSERT OR UPDATE ON modulos DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION multipro_guardar_agenda();
CREATE CONSTRAINT TRIGGER guardar_agenda_dias AFTER INSERT OR UPDATE ON dias_modulos DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION multipro_guardar_agenda();
COMMIT;
