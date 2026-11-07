$ErrorActionPreference='Stop'
$OutputEncoding=[Text.UTF8Encoding]::new($false)
$repo=Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$infra='C:\MultiPro-Migration\MultiPro-Pagos-MVP\staging-local'
$temporal='multipro_test_pagos_'+(Get-Date -Format 'yyyyMMddHHmmss')
function Sql([string]$base,[string]$sql) {
    $r=$sql | docker exec -i multipro-postgres-staging sh -c ('psql -X -qAt -U "$POSTGRES_USER" -d '+$base+' -v ON_ERROR_STOP=1')
    if($LASTEXITCODE -ne 0){throw 'Fallo SQL en copia temporal Georef'}
    return ($r -join "`n").Trim()
}
$creada=$false
try {
    Sql postgres "CREATE DATABASE $temporal TEMPLATE multipro_staging_filtros_cascada;" | Out-Null;$creada=$true
    if((Sql $temporal "SELECT count(*) FROM information_schema.columns WHERE table_name='localidades' AND column_name='codigo_georef'") -eq '0') {
        Sql $temporal (Get-Content -LiteralPath (Join-Path $repo 'database/migrations/20261030_010_catalogo_georef.sql') -Raw -Encoding UTF8) | Out-Null
    }
    foreach($linea in Get-Content -LiteralPath (Join-Path $infra '.env')) {
        if($linea -match '^POSTGRES_ADMIN_USER=(.*)$'){$env:MULTIPRO_TEST_USER=$Matches[1].Trim().Trim('"').Trim("'")}
        if($linea -match '^POSTGRES_ADMIN_PASSWORD=(.*)$'){$env:MULTIPRO_TEST_PASSWORD=$Matches[1].Trim().Trim('"').Trim("'")}
    }
    foreach($linea in Get-Content -LiteralPath (Join-Path $repo 'web/.env')) {if($linea -match '^PGPASSWORD=(.*)$'){$env:MULTIPRO_TEST_APP_PASSWORD=$Matches[1].Trim().Trim('"').Trim("'")}}
    $env:MULTIPRO_TEST_DB=$temporal;$env:MULTIPRO_TEST_PHP_INI=Join-Path $infra 'php.ini';$env:MAIL_ENABLED='false'
    & php -c $env:MULTIPRO_TEST_PHP_INI (Join-Path $PSScriptRoot 'georef.php')
    if($LASTEXITCODE -ne 0){throw 'Fallo comprobacion Georef; staging conservado'}
} finally {
    Remove-Item Env:MULTIPRO_TEST_USER,Env:MULTIPRO_TEST_PASSWORD,Env:MULTIPRO_TEST_APP_PASSWORD,Env:MULTIPRO_TEST_DB,Env:MULTIPRO_TEST_PHP_INI,Env:MAIL_ENABLED -ErrorAction SilentlyContinue
    if($creada){Sql postgres "DROP DATABASE $temporal;" | Out-Null}
}
