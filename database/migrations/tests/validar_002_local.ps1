# MultiPro: ejecutar exclusivamente contra el contenedor local documentado.
$ErrorActionPreference = 'Stop'
$repo = Split-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) -Parent
$temporal = 'multipro_test_002_' + (Get-Date -Format 'yyyyMMddHHmmss')
if ($temporal -notmatch '^multipro_test_002_[0-9]{14}$') { throw 'Nombre temporal invalido' }
function Invoke-LocalSql([string]$database, [string]$sql) {
    $sql | docker exec -i multipro-postgres-staging sh -c ('psql -X -U "$POSTGRES_USER" -d ' + $database + ' -v ON_ERROR_STOP=1')
    if ($LASTEXITCODE -ne 0) { throw "Fallo SQL local en $database" }
}
function Invoke-Archivo([string]$relative) {
    Invoke-LocalSql $temporal (Get-Content -LiteralPath (Join-Path $repo $relative) -Raw)
}
$creada = $false
try {
    Invoke-LocalSql postgres "CREATE DATABASE $temporal TEMPLATE multipro_base_20261026;"
    $creada = $true
    Invoke-Archivo 'database/migrations/20261026_001_reservas_pagos_base.sql'
    # La referencia precede al rol local: reproducir permisos verificados en staging.
    # Solo en esta base temporal; nunca sobre la referencia ni sobre staging.
    Invoke-LocalSql $temporal "GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA public TO multipro_app_local; GRANT USAGE,SELECT,UPDATE ON ALL SEQUENCES IN SCHEMA public TO multipro_app_local; ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT,INSERT,UPDATE,DELETE ON TABLES TO multipro_app_local; ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE,SELECT,UPDATE ON SEQUENCES TO multipro_app_local;"
    Invoke-Archivo 'database/migrations/20261026_002_proveedores_pagos.sql'
    Invoke-Archivo 'database/migrations/tests/20261026_002_validacion.sql'
    Invoke-LocalSql $temporal ('BEGIN;' + [Environment]::NewLine + (Get-Content -LiteralPath (Join-Path $repo 'database/migrations/local/20261026_002_permisos_staging.sql') -Raw) + [Environment]::NewLine + 'COMMIT;')
    Invoke-Archivo 'database/migrations/tests/20261026_002_permisos_local.sql'
    Invoke-Archivo 'database/migrations/rollback/20261026_002_proveedores_pagos.rollback.sql'
    Invoke-LocalSql $temporal "SELECT count(*) AS tablas_pago_tras_rollback FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'pago_%'; SELECT count(*) AS reservas_tras_rollback FROM reservas;"
    Invoke-Archivo 'database/migrations/20261026_002_proveedores_pagos.sql'
    # La presencia de una configuracion debe impedir el rollback, incluso inactiva.
    Invoke-LocalSql $temporal "INSERT INTO pago_conexiones(fk_entidad,proveedor,ambiente,modalidad_conexion,cuenta_proveedor,referencia_credencial) SELECT min(id),'mercadopago','prueba','oauth','rollback_sintetico',gen_random_uuid() FROM entidades;"
    $rechazo = $false
    try { Invoke-Archivo 'database/migrations/rollback/20261026_002_proveedores_pagos.rollback.sql' } catch { $rechazo = $true }
    if (-not $rechazo) { throw 'Rollback con datos no fue rechazado' }
    Invoke-LocalSql $temporal "DO `$$ BEGIN IF (SELECT count(*) FROM pago_conexiones)<>1 THEN RAISE EXCEPTION 'Rollback perdio datos'; END IF; END `$$;"
    'VALIDACION_002_OK: migracion, pruebas, rollback vacio, reaplicacion y rollback protegido.'
} finally {
    if ($creada) { Invoke-LocalSql postgres "DROP DATABASE $temporal;" }
}
