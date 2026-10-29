$ErrorActionPreference='Stop'
$repo=Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$temporal='multipro_test_pagos_'+(Get-Date -Format 'yyyyMMddHHmmss')
$infra='C:\MultiPro-Migration\MultiPro-Pagos-MVP\staging-local'
function Sql([string]$base,[string]$sql){
    $sql | docker exec -i multipro-postgres-staging sh -c ('psql -X -U "$POSTGRES_USER" -d '+$base+' -v ON_ERROR_STOP=1')
    if($LASTEXITCODE -ne 0){throw 'Fallo preparacion de base temporal'}
}
function Archivo([string]$ruta){Sql $temporal (Get-Content -LiteralPath (Join-Path $repo $ruta) -Raw)}
function ArchivoRol([string]$ruta){
    Get-Content -LiteralPath (Join-Path $repo $ruta) -Raw | docker exec -i multipro-postgres-staging sh -c ('psql -X -U "$POSTGRES_USER" -d '+$temporal+' -v ON_ERROR_STOP=1 -v app_role=multipro_app_local')
    if($LASTEXITCODE -ne 0){throw 'Fallo validacion de permisos productivos'}
}
$creada=$false
try{
    Sql postgres "CREATE DATABASE $temporal TEMPLATE multipro_base_20261026;"
    $creada=$true
    Archivo 'database/deployment/20261027_preflight_production.sql'
    Archivo 'database/migrations/20261026_001_reservas_pagos_base.sql'
    Archivo 'database/migrations/20261026_002_proveedores_pagos.sql'
    Archivo 'database/migrations/20261026_003_pago_confirmador.sql'
    Archivo 'database/migrations/rollback/20261026_003_pago_confirmador.rollback.sql'
    Archivo 'database/migrations/20261026_003_pago_confirmador.sql'
    $ordenProtegido=$false
    try { Archivo 'database/migrations/rollback/20261026_002_proveedores_pagos.rollback.sql' } catch { $ordenProtegido=$true }
    if(-not $ordenProtegido){throw 'Rollback 002 no respeto dependencia de 003'}
    Archivo 'database/migrations/20261026_004_presencial.sql'
    Archivo 'database/migrations/rollback/20261026_004_presencial.rollback.sql'
    Archivo 'database/migrations/20261026_004_presencial.sql'
    Archivo 'database/migrations/20261028_005_recuperacion_contrasena_segura.sql'
    Archivo 'database/migrations/20261028_006_notificaciones_email.sql'
    Archivo 'database/migrations/20261028_007_recordatorios_reservas.sql'
    Archivo 'database/deployment/20261027_postflight_production.sql'
    $baseProtegida=$false
    try { Archivo 'database/migrations/rollback/20261026_001_reservas_pagos_base.rollback.sql' } catch { $baseProtegida=$true }
    if(-not $baseProtegida){throw 'Rollback 001 no protegio dependencia presencial'}
    # Modelo previo: mismos permisos generales que staging, luego permisos de pagos acotados.
    Sql $temporal 'GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA public TO multipro_app_local; GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO multipro_app_local; REVOKE ALL ON pago_conexiones,pago_transacciones,pago_webhook_eventos,pago_reembolsos FROM multipro_app_local; REVOKE ALL ON SEQUENCE pago_conexiones_id_seq,pago_transacciones_id_seq,pago_webhook_eventos_id_seq,pago_reembolsos_id_seq FROM multipro_app_local;'
    Sql $temporal ('BEGIN;'+[Environment]::NewLine+(Get-Content -LiteralPath (Join-Path $repo 'database/migrations/local/20261026_003_permisos_servicio.sql') -Raw)+[Environment]::NewLine+'COMMIT;')
    Sql $temporal ('BEGIN;'+[Environment]::NewLine+(Get-Content -LiteralPath (Join-Path $repo 'database/migrations/local/20261026_004_permisos_presencial.sql') -Raw)+[Environment]::NewLine+'COMMIT;')
    Sql $temporal ('BEGIN;'+[Environment]::NewLine+(Get-Content -LiteralPath (Join-Path $repo 'database/migrations/local/20261028_006_permisos_notificaciones.sql') -Raw)+[Environment]::NewLine+'COMMIT;')
    ArchivoRol 'database/deployment/20261028_permissions_production.sql'
    # Solo memoria del proceso; nunca argumentos, logs o archivos de credenciales nuevos.
    foreach($linea in Get-Content -LiteralPath (Join-Path $infra '.env')){
        if($linea -match '^POSTGRES_ADMIN_USER=(.*)$'){$env:MULTIPRO_TEST_USER=$Matches[1].Trim().Trim('"').Trim("'")}
        if($linea -match '^POSTGRES_ADMIN_PASSWORD=(.*)$'){$env:MULTIPRO_TEST_PASSWORD=$Matches[1].Trim().Trim('"').Trim("'")}
    }
    if(-not $env:MULTIPRO_TEST_USER -or -not $env:MULTIPRO_TEST_PASSWORD){throw 'Configuracion privada incompleta'}
    foreach($linea in Get-Content -LiteralPath (Join-Path $repo 'web/.env')){
        if($linea -match '^PGPASSWORD=(.*)$'){$env:MULTIPRO_TEST_APP_PASSWORD=$Matches[1].Trim().Trim('"').Trim("'")}
    }
    if(-not $env:MULTIPRO_TEST_APP_PASSWORD){throw 'Configuracion privada de aplicacion incompleta'}
    $env:MULTIPRO_TEST_DB=$temporal
    $env:MULTIPRO_TEST_PHP_INI=Join-Path $infra 'php.ini'
    $env:MAIL_ENABLED='false'
    & php -c $env:MULTIPRO_TEST_PHP_INI (Join-Path $PSScriptRoot 'servicio.php')
    if($LASTEXITCODE -ne 0){throw 'Fallo pruebas PHP; staging no modificado'}
    & php -c $env:MULTIPRO_TEST_PHP_INI (Join-Path $repo 'tests/correo/notificaciones.php')
    if($LASTEXITCODE -ne 0){throw 'Fallo pruebas de notificaciones; staging no modificado'}
    $notificacionesProtegidas=$false
    try { Archivo 'database/migrations/rollback/20261028_006_notificaciones_email.rollback.sql' } catch { $notificacionesProtegidas=$true }
    if(-not $notificacionesProtegidas){throw 'Rollback 006 no protegio historial'}
    $recordatoriosProtegidos=$false
    try { Archivo 'database/migrations/rollback/20261028_007_recordatorios_reservas.rollback.sql' } catch { $recordatoriosProtegidos=$true }
    if(-not $recordatoriosProtegidos){throw 'Rollback 007 no protegio historial'}
    $protegido=$false
    try { Archivo 'database/migrations/rollback/20261026_003_pago_confirmador.rollback.sql' } catch { $protegido=$true }
    if(-not $protegido){throw 'Rollback 003 no protegio los confirmadores'}
    $presencialProtegido=$false
    try { Archivo 'database/migrations/rollback/20261026_004_presencial.rollback.sql' } catch { $presencialProtegido=$true }
    if(-not $presencialProtegido){throw 'Rollback 004 no protegio reservas presenciales'}
    'PRUEBAS_SERVICIO_LOCAL_OK'
}finally{
    Remove-Item Env:MULTIPRO_TEST_PASSWORD,Env:MULTIPRO_TEST_APP_PASSWORD,Env:MULTIPRO_TEST_USER,Env:MULTIPRO_TEST_DB,Env:MULTIPRO_TEST_PHP_INI,Env:MAIL_ENABLED -ErrorAction SilentlyContinue
    if($creada){Sql postgres "DROP DATABASE $temporal;"}
}
