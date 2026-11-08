$ErrorActionPreference = 'Stop'
$repo = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$contenedor = 'multipro-postgres-staging'
$base = 'multipro_test_lista_espera_' + (Get-Date -Format 'yyyyMMddHHmmss')
$dumpLocal = 'C:\MultiPro-Migration\MultiPro-Pagos-MVP\staging-local\private\prd-window-20261107-170813\production-fresh.dump'
$dumpContenedor = '/tmp/multipro-lista-espera-test.dump'
$creada = $false

function EjecutarSql([string]$database, [string]$sql) {
    $sql | docker exec -i $contenedor sh -c ('psql -X -q -U "$POSTGRES_USER" -d ' + $database + ' -v ON_ERROR_STOP=1')
    if ($LASTEXITCODE -ne 0) { throw 'Falló una validación SQL de lista de espera.' }
}

try {
    docker cp $dumpLocal ($contenedor + ':' + $dumpContenedor)
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo copiar el respaldo a la base local.' }

    EjecutarSql 'postgres' ('CREATE DATABASE ' + $base + ';')
    $creada = $true
    docker exec $contenedor sh -c ('pg_restore -U "$POSTGRES_USER" --no-owner --exit-on-error -d ' + $base + ' ' + $dumpContenedor)
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo restaurar la base temporal.' }

    # Simula un registro histórico defectuoso para comprobar el backfill.
    EjecutarSql $base @'
UPDATE inscripcion
SET lista_espera = TRUE, fecha_le = NULL
WHERE id = (SELECT id FROM inscripcion ORDER BY id LIMIT 1);
'@

    EjecutarSql $base (Get-Content -LiteralPath (Join-Path $repo 'database/migrations/20261108_012_fecha_lista_espera.sql') -Raw -Encoding UTF8)

    EjecutarSql $base @'
DO $$
DECLARE
    v_id INT;
    v_fecha_inicial TIMESTAMP;
    v_fecha_reingreso TIMESTAMP;
    v_nueva_id INT;
    v_primero INT;
BEGIN
    SELECT id, fecha_le INTO v_id, v_fecha_inicial
    FROM inscripcion
    WHERE lista_espera IS TRUE
    ORDER BY id
    LIMIT 1;

    IF v_id IS NULL OR v_fecha_inicial IS NULL THEN
        RAISE EXCEPTION 'El backfill no completó fecha_le';
    END IF;

    UPDATE inscripcion SET lista_espera = FALSE WHERE id = v_id;
    IF (SELECT fecha_le FROM inscripcion WHERE id = v_id) IS DISTINCT FROM v_fecha_inicial THEN
        RAISE EXCEPTION 'La promoción no conservó la historia de fecha_le';
    END IF;

    PERFORM pg_sleep(0.01);
    UPDATE inscripcion SET lista_espera = TRUE WHERE id = v_id;
    SELECT fecha_le INTO v_fecha_reingreso FROM inscripcion WHERE id = v_id;
    IF v_fecha_reingreso <= v_fecha_inicial THEN
        RAISE EXCEPTION 'El reingreso no recibió una fecha nueva';
    END IF;

    INSERT INTO inscripcion (fk_modulo, fk_usuario, fk_menor, lista_espera, estado)
    SELECT fk_modulo, fk_usuario, fk_menor, TRUE, 'activo'
    FROM inscripcion
    WHERE id = v_id
    RETURNING id INTO v_nueva_id;

    IF (SELECT fecha_le FROM inscripcion WHERE id = v_nueva_id) IS NULL THEN
        RAISE EXCEPTION 'El alta directa en espera quedó sin fecha_le';
    END IF;

    UPDATE inscripcion SET fecha_le = TIMESTAMP '2026-01-02 10:00:00' WHERE id = v_id;
    UPDATE inscripcion SET fecha_le = TIMESTAMP '2026-01-01 10:00:00' WHERE id = v_nueva_id;
    SELECT id INTO v_primero
    FROM inscripcion
    WHERE id IN (v_id, v_nueva_id)
    ORDER BY COALESCE(fecha_le, fecha), id
    LIMIT 1;
    IF v_primero <> v_nueva_id THEN
        RAISE EXCEPTION 'La lista no quedó ordenada por fecha_le';
    END IF;

    UPDATE inscripcion SET fecha_le = TIMESTAMP '2026-01-01 10:00:00' WHERE id = v_id;
    SELECT id INTO v_primero
    FROM inscripcion
    WHERE id IN (v_id, v_nueva_id)
    ORDER BY COALESCE(fecha_le, fecha), id
    LIMIT 1;
    IF v_primero <> LEAST(v_id, v_nueva_id) THEN
        RAISE EXCEPTION 'El desempate de lista no respetó el orden estable por id';
    END IF;

    IF EXISTS (
        SELECT 1 FROM inscripcion
        WHERE lista_espera IS TRUE AND fecha_le IS NULL
    ) THEN
        RAISE EXCEPTION 'Quedaron inscripciones en espera sin fecha_le';
    END IF;
END;
$$;
'@

    Write-Output 'LISTA_ESPERA_LOCAL_OK backfill_alta_promocion_reingreso_orden'
}
finally {
    if ($creada -and $base -match '^multipro_test_lista_espera_\d{14}$') {
        EjecutarSql 'postgres' ('DROP DATABASE ' + $base + ';')
    }
}
