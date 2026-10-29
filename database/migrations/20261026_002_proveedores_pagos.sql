-- MultiPro - Migracion 002. Preparada para revision; no activa proveedores.
-- Ejecutar con psql -v ON_ERROR_STOP=1. Sin secretos ni payloads crudos.
BEGIN;
SET LOCAL lock_timeout = '5s';

CREATE TABLE pago_conexiones (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    fk_entidad INT NOT NULL REFERENCES entidades(id) ON DELETE RESTRICT,
    proveedor VARCHAR(40) NOT NULL CHECK (proveedor ~ '^[a-z][a-z0-9_]{1,39}$'),
    ambiente VARCHAR(10) NOT NULL CHECK (ambiente IN ('prueba', 'produccion')),
    modalidad_conexion VARCHAR(25) NOT NULL CHECK (modalidad_conexion IN ('oauth', 'credencial_administrada')),
    cuenta_proveedor VARCHAR(100) NOT NULL CHECK (btrim(cuenta_proveedor) <> ''),
    -- UUID opaco para resolver un registro privado fuera de la BD y del repositorio.
    referencia_credencial UUID NOT NULL,
    estado VARCHAR(15) NOT NULL DEFAULT 'inactiva' CHECK (estado IN ('inactiva', 'activa', 'revocada')),
    creado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (id, fk_entidad),
    UNIQUE (proveedor, ambiente, cuenta_proveedor)
);
CREATE UNIQUE INDEX uq_pago_conexion_activa_entidad
    ON pago_conexiones(fk_entidad, ambiente) WHERE estado = 'activa';

CREATE TABLE pago_transacciones (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    fk_reserva INT NOT NULL REFERENCES reservas(id) ON DELETE RESTRICT,
    fk_entidad INT NOT NULL,
    fk_conexion BIGINT NOT NULL,
    referencia_externa UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    clave_idempotencia UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    importe_ars NUMERIC(12,2) NOT NULL CHECK (importe_ars > 0 AND importe_ars <> 'NaN'::numeric),
    moneda CHAR(3) NOT NULL DEFAULT 'ARS' CHECK (moneda = 'ARS'),
    estado VARCHAR(20) NOT NULL DEFAULT 'creado' CHECK (estado IN
        ('creado', 'pendiente', 'aprobado', 'rechazado', 'cancelado', 'error', 'reembolsado', 'contracargo')),
    checkout_proveedor_id VARCHAR(150) CHECK (btrim(checkout_proveedor_id) <> ''),
    pago_proveedor_id VARCHAR(150) CHECK (btrim(pago_proveedor_id) <> ''),
    verificado_en TIMESTAMPTZ,
    creado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (fk_conexion, fk_entidad) REFERENCES pago_conexiones(id, fk_entidad) ON DELETE RESTRICT,
    UNIQUE (id, fk_conexion),
    UNIQUE (fk_conexion, pago_proveedor_id),
    CHECK (estado NOT IN ('aprobado','reembolsado','contracargo') OR
           (pago_proveedor_id IS NOT NULL AND verificado_en IS NOT NULL))
);
CREATE INDEX idx_pago_transacciones_reserva ON pago_transacciones(fk_reserva);
CREATE INDEX idx_pago_transacciones_entidad ON pago_transacciones(fk_entidad, creado_en);
-- Un checkout puede tener varios pagos del proveedor, incluso cobros duplicados.
-- No ocultarlos con una unicidad por reserva: conciliar y devolver excedentes.

CREATE TABLE pago_webhook_eventos (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    fk_conexion BIGINT NOT NULL REFERENCES pago_conexiones(id) ON DELETE RESTRICT,
    evento_proveedor_id VARCHAR(150) NOT NULL CHECK (btrim(evento_proveedor_id) <> ''),
    recurso_proveedor_id VARCHAR(150) NOT NULL CHECK (btrim(recurso_proveedor_id) <> ''),
    tipo VARCHAR(80) NOT NULL CHECK (btrim(tipo) <> ''),
    accion VARCHAR(80) CHECK (btrim(accion) <> ''),
    fk_pago BIGINT,
    -- Solo admitir eventos autenticados; rechazos se cuentan sin persistir payload.
    firma_validada_en TIMESTAMPTZ NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'recibido' CHECK (estado IN
        ('recibido', 'procesando', 'procesado', 'ignorado', 'error')),
    intentos INT NOT NULL DEFAULT 0 CHECK (intentos >= 0),
    proximo_intento_en TIMESTAMPTZ,
    bloqueo_hasta TIMESTAMPTZ,
    codigo_error VARCHAR(60) CHECK (codigo_error ~ '^[A-Z0-9_]+$'),
    recibido_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    procesado_en TIMESTAMPTZ,
    UNIQUE (fk_conexion, evento_proveedor_id),
    FOREIGN KEY (fk_pago, fk_conexion) REFERENCES pago_transacciones(id, fk_conexion) ON DELETE RESTRICT,
    CHECK (estado NOT IN ('procesado','ignorado') OR procesado_en IS NOT NULL),
    CHECK (estado <> 'procesando' OR bloqueo_hasta IS NOT NULL)
);
CREATE INDEX idx_pago_webhook_reintentos ON pago_webhook_eventos(estado, proximo_intento_en, bloqueo_hasta)
    WHERE estado IN ('recibido','error','procesando');

CREATE TABLE pago_reembolsos (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    fk_pago BIGINT NOT NULL,
    fk_conexion BIGINT NOT NULL,
    clave_idempotencia UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    reembolso_proveedor_id VARCHAR(150) CHECK (btrim(reembolso_proveedor_id) <> ''),
    importe_ars NUMERIC(12,2) NOT NULL CHECK (importe_ars > 0 AND importe_ars <> 'NaN'::numeric),
    moneda CHAR(3) NOT NULL DEFAULT 'ARS' CHECK (moneda = 'ARS'),
    motivo VARCHAR(30) NOT NULL CHECK (motivo IN
        ('cancelacion_usuario', 'cancelacion_administrativa', 'pago_tardio', 'pago_duplicado')),
    solicitado_por INT REFERENCES usuarios(id) ON DELETE RESTRICT,
    estado VARCHAR(15) NOT NULL DEFAULT 'solicitado' CHECK (estado IN
        ('solicitado', 'procesando', 'pendiente', 'aprobado', 'rechazado', 'error')),
    intentos INT NOT NULL DEFAULT 0 CHECK (intentos >= 0),
    codigo_error VARCHAR(60) CHECK (codigo_error ~ '^[A-Z0-9_]+$'),
    creado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verificado_en TIMESTAMPTZ,
    FOREIGN KEY (fk_pago, fk_conexion) REFERENCES pago_transacciones(id, fk_conexion) ON DELETE RESTRICT,
    UNIQUE (fk_pago), -- MVP: una devolucion total por cobro, reintentos sobre la misma fila.
    UNIQUE (fk_conexion, reembolso_proveedor_id),
    CHECK (estado <> 'aprobado' OR (reembolso_proveedor_id IS NOT NULL AND verificado_en IS NOT NULL))
);

CREATE FUNCTION multipro_validar_pago() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r reservas%ROWTYPE; entidad INT;
BEGIN
    IF TG_OP = 'UPDATE' AND
       ROW(NEW.fk_reserva, NEW.fk_entidad, NEW.fk_conexion, NEW.importe_ars, NEW.moneda,
           NEW.referencia_externa, NEW.clave_idempotencia)
       IS DISTINCT FROM
       ROW(OLD.fk_reserva, OLD.fk_entidad, OLD.fk_conexion, OLD.importe_ars, OLD.moneda,
           OLD.referencia_externa, OLD.clave_idempotencia) THEN
        RAISE EXCEPTION 'Identidad e importe del pago son inmutables';
    END IF;
    SELECT * INTO STRICT r FROM reservas WHERE id = NEW.fk_reserva FOR UPDATE;
    SELECT fk_entidad INTO STRICT entidad FROM espacios WHERE id = r.fk_espacio FOR SHARE;
    IF r.modalidad_pago <> 'online' OR r.importe_total_ars <> NEW.importe_ars
       OR r.moneda <> NEW.moneda OR entidad IS DISTINCT FROM NEW.fk_entidad THEN
        RAISE EXCEPTION 'Pago no corresponde a reserva, entidad, importe o moneda';
    END IF;
    NEW.actualizado_en := clock_timestamp();
    RETURN NEW;
END $$;
CREATE TRIGGER validar_pago BEFORE INSERT OR UPDATE ON pago_transacciones
    FOR EACH ROW EXECUTE FUNCTION multipro_validar_pago();

CREATE FUNCTION multipro_proteger_reserva_pago() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF ROW(NEW.fk_espacio, NEW.importe_total_ars, NEW.moneda, NEW.modalidad_pago,
           NEW.fk_usuario, NEW.fecha_de_asistencia, NEW.horario)
       IS DISTINCT FROM ROW(OLD.fk_espacio, OLD.importe_total_ars, OLD.moneda, OLD.modalidad_pago,
           OLD.fk_usuario, OLD.fecha_de_asistencia, OLD.horario)
       AND EXISTS (SELECT 1 FROM pago_transacciones WHERE fk_reserva = OLD.id) THEN
        RAISE EXCEPTION 'Reserva con pago: identidad e importe inmutables';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER proteger_reserva_pago BEFORE UPDATE ON reservas
    FOR EACH ROW EXECUTE FUNCTION multipro_proteger_reserva_pago();

CREATE FUNCTION multipro_proteger_conexion_pago() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF ROW(NEW.fk_entidad,NEW.proveedor,NEW.ambiente,NEW.cuenta_proveedor)
       IS DISTINCT FROM ROW(OLD.fk_entidad,OLD.proveedor,OLD.ambiente,OLD.cuenta_proveedor) THEN
        RAISE EXCEPTION 'Identidad de conexion inmutable; crear otra conexion';
    END IF;
    NEW.actualizado_en := clock_timestamp();
    RETURN NEW;
END $$;
CREATE TRIGGER proteger_conexion_pago BEFORE UPDATE ON pago_conexiones
    FOR EACH ROW EXECUTE FUNCTION multipro_proteger_conexion_pago();

CREATE FUNCTION multipro_proteger_entidad_espacio_pago() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.fk_entidad IS DISTINCT FROM OLD.fk_entidad AND EXISTS (
        SELECT 1 FROM reservas r JOIN pago_transacciones p ON p.fk_reserva=r.id
        WHERE r.fk_espacio=OLD.id
    ) THEN RAISE EXCEPTION 'Espacio con pagos: entidad inmutable'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER proteger_entidad_espacio_pago BEFORE UPDATE ON espacios
    FOR EACH ROW EXECUTE FUNCTION multipro_proteger_entidad_espacio_pago();

CREATE FUNCTION multipro_validar_reembolso() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p pago_transacciones%ROWTYPE;
BEGIN
    IF TG_OP = 'UPDATE' AND ROW(NEW.fk_pago,NEW.fk_conexion,NEW.importe_ars,NEW.moneda,NEW.clave_idempotencia,NEW.motivo)
       IS DISTINCT FROM ROW(OLD.fk_pago,OLD.fk_conexion,OLD.importe_ars,OLD.moneda,OLD.clave_idempotencia,OLD.motivo) THEN
        RAISE EXCEPTION 'Identidad e importe del reembolso son inmutables';
    END IF;
    SELECT * INTO STRICT p FROM pago_transacciones WHERE id=NEW.fk_pago FOR UPDATE;
    IF NEW.importe_ars <> p.importe_ars OR NEW.moneda <> p.moneda OR
       p.estado NOT IN ('aprobado','reembolsado') THEN
        RAISE EXCEPTION 'Reembolso requiere cobro aprobado e importe total exacto';
    END IF;
    NEW.actualizado_en := clock_timestamp();
    RETURN NEW;
END $$;
CREATE TRIGGER validar_reembolso BEFORE INSERT OR UPDATE ON pago_reembolsos
    FOR EACH ROW EXECUTE FUNCTION multipro_validar_reembolso();

-- No otorgar permisos a roles remotos o presumir su existencia.
-- Los permisos del rol local se verifican aparte antes de habilitar el servicio.
COMMIT;
