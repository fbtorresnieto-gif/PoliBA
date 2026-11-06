$ErrorActionPreference='Stop'
$OutputEncoding=[Text.UTF8Encoding]::new($false)
$repo=Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$infra='C:\MultiPro-Migration\MultiPro-Pagos-MVP\staging-local'
$marca=Get-Date -Format yyyyMMddHHmmss
$base='multipro_qa_pantallas_'+$marca
$carpeta=Join-Path $infra ('private\qa-pantallas-'+$marca)
if(Get-NetTCPConnection -LocalPort 8087 -State Listen -ErrorAction SilentlyContinue){throw 'El puerto QA ya está ocupado; no se reemplaza ningún servidor.'}
New-Item -ItemType Directory -Path $carpeta | Out-Null
New-Item -ItemType Directory -Path (Join-Path $carpeta 'sesiones') | Out-Null
$sql='/tmp/multipro-qa-schema-'+$marca+'.sql'
docker exec multipro-postgres-staging sh -c ('pg_dump -U "$POSTGRES_USER" --schema-only -d multipro_staging_filtros_cascada -f '+$sql)
if($LASTEXITCODE -ne 0){throw 'No se pudo copiar el esquema de pruebas.'}
"CREATE DATABASE $base;" | docker exec -i multipro-postgres-staging sh -c 'psql -X -q -U "$POSTGRES_USER" -d postgres -v ON_ERROR_STOP=1'
if($LASTEXITCODE -ne 0){throw 'No se pudo crear la base QA.'}
docker exec multipro-postgres-staging sh -c ('psql -X -q -U "$POSTGRES_USER" -d '+$base+' -v ON_ERROR_STOP=1 -f '+$sql)
if($LASTEXITCODE -ne 0){throw 'La restauración del esquema falló; conservar para revisar.'}
foreach($linea in Get-Content -LiteralPath (Join-Path $infra '.env')) {
    if($linea -match '^POSTGRES_ADMIN_USER=(.*)$'){$env:MULTIPRO_TEST_USER=$Matches[1].Trim().Trim('"').Trim("'")}
    if($linea -match '^POSTGRES_ADMIN_PASSWORD=(.*)$'){$env:MULTIPRO_TEST_PASSWORD=$Matches[1].Trim().Trim('"').Trim("'")}
}
$env:MULTIPRO_QA_DB=$base;$env:MULTIPRO_QA_DIR=$carpeta;$env:MAIL_ENABLED='false'
& php -c (Join-Path $infra 'php.ini') (Join-Path $PSScriptRoot 'qa_ui_seed.php')
if($LASTEXITCODE -ne 0){throw 'No se pudo preparar la fixture QA.'}
Remove-Item Env:MULTIPRO_TEST_USER,Env:MULTIPRO_TEST_PASSWORD,Env:MULTIPRO_QA_DB,Env:MULTIPRO_QA_DIR -ErrorAction SilentlyContinue
$env:PGHOST='127.0.0.1';$env:PGPORT='5433';$env:PGDATABASE=$base;$env:PGUSER='multipro_app_local';$env:PGSSLMODE='disable';$env:APP_URL='http://127.0.0.1:8087'
foreach($linea in Get-Content -LiteralPath (Join-Path $repo 'web/.env')){if($linea -match '^PGPASSWORD=(.*)$'){$env:PGPASSWORD=$Matches[1].Trim().Trim('"').Trim("'")}}
$php=(Get-Command php).Source
$proceso=Start-Process -FilePath $php -ArgumentList @('-c',(Join-Path $infra 'php.ini'),'-d',('session.name=MULTIPRO_QA_'+$marca),'-d',('session.save_path='+(Join-Path $carpeta 'sesiones')),'-S','127.0.0.1:8087','-t',(Join-Path $repo 'web')) -WindowStyle Hidden -RedirectStandardOutput (Join-Path $carpeta 'web-output.log') -RedirectStandardError (Join-Path $carpeta 'web-error.log') -PassThru
$proceso.Id | Set-Content -LiteralPath (Join-Path $carpeta 'servidor.pid')
Write-Output ('QA preparada: '+$base)
Write-Output ('Carpeta privada: '+$carpeta)
Write-Output 'URL local aislada: http://127.0.0.1:8087/ (correo deshabilitado)'
