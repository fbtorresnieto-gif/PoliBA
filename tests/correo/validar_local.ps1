$ErrorActionPreference = 'Stop'
$repo = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$infra = 'C:\MultiPro-Migration\MultiPro-Pagos-MVP\staging-local'
$temporal = 'multipro_test_correo_' + (Get-Date -Format 'yyyyMMddHHmmss')
$creada = $false

function Sql([string]$base, [string]$sql) {
    $sql | docker exec -i multipro-postgres-staging sh -c ('psql -X -U "$POSTGRES_USER" -d ' + $base + ' -v ON_ERROR_STOP=1')
    if ($LASTEXITCODE -ne 0) { throw 'Fallo SQL de prueba' }
}

function Archivo([string]$ruta) {
    Sql $temporal (Get-Content -LiteralPath (Join-Path $repo $ruta) -Raw)
}

try {
    Sql postgres "CREATE DATABASE $temporal TEMPLATE multipro_base_20261026;"
    $creada = $true
    Archivo 'database/migrations/20261028_005_recuperacion_contrasena_segura.sql'

    foreach ($linea in Get-Content -LiteralPath (Join-Path $infra '.env')) {
        if ($linea -match '^POSTGRES_ADMIN_USER=(.*)$') { $env:MULTIPRO_TEST_USER = $Matches[1].Trim().Trim('"').Trim("'") }
        if ($linea -match '^POSTGRES_ADMIN_PASSWORD=(.*)$') { $env:MULTIPRO_TEST_PASSWORD = $Matches[1].Trim().Trim('"').Trim("'") }
    }
    if (-not $env:MULTIPRO_TEST_USER -or -not $env:MULTIPRO_TEST_PASSWORD) { throw 'Configuracion privada incompleta' }
    $env:MULTIPRO_TEST_DB = $temporal
    & php -c (Join-Path $infra 'php.ini') (Join-Path $PSScriptRoot 'recuperacion.php')
    if ($LASTEXITCODE -ne 0) { throw 'Fallaron pruebas PHP de recuperacion' }

    $protegido = $false
    try { Archivo 'database/migrations/rollback/20261028_005_recuperacion_contrasena_segura.rollback.sql' } catch { $protegido = $true }
    if (-not $protegido) { throw 'Rollback 005 no protegio solicitudes nuevas' }

    Sql $temporal 'DELETE FROM password_resets;'
    Archivo 'database/migrations/rollback/20261028_005_recuperacion_contrasena_segura.rollback.sql'
    Archivo 'database/migrations/20261028_005_recuperacion_contrasena_segura.sql'
    'PRUEBAS_CORREO_LOCAL_OK'
} finally {
    Remove-Item Env:MULTIPRO_TEST_USER,Env:MULTIPRO_TEST_PASSWORD,Env:MULTIPRO_TEST_DB -ErrorAction SilentlyContinue
    if ($creada) { Sql postgres "DROP DATABASE $temporal;" }
}
