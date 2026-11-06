$ErrorActionPreference='Stop'
$OutputEncoding=[Text.UTF8Encoding]::new($false)
$repo=Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$infra='C:\MultiPro-Migration\MultiPro-Pagos-MVP\staging-local'
$base='multipro_staging_filtros_cascada'
$contenedor='multipro-postgres-staging'
function Sql([string]$db,[string]$sql) {
    $r=$sql | docker exec -i $contenedor sh -c ('psql -X -qAt -U "$POSTGRES_USER" -d '+$db+' -v ON_ERROR_STOP=1')
    if($LASTEXITCODE -ne 0){throw 'Fallo SQL local. Conservar respaldo y revisar; no se accedio a produccion.'}
    return ($r -join "`n").Trim()
}
$config=@{}
foreach($linea in Get-Content -LiteralPath (Join-Path $repo 'web/.env') -Encoding UTF8) {
    if($linea -match '^(PGHOST|PGPORT|PGDATABASE|PGPASSWORD)=(.*)$'){$config[$Matches[1]]=$Matches[2].Trim().Trim('"').Trim("'")}
}
if($config['PGHOST'] -ne '127.0.0.1' -or $config['PGPORT'] -ne '5433' -or $config['PGDATABASE'] -ne $base){throw 'La configuracion no corresponde a la copia local autorizada.'}
$marca=Get-Date -Format 'yyyyMMdd-HHmmss'
$respaldo=Join-Path $infra ('private\antes-georef-'+$marca)
New-Item -ItemType Directory -Path $respaldo | Out-Null
$archivoContenedor='/tmp/multipro-georef-'+$marca+'.dump'
docker exec $contenedor sh -c ('pg_dump -U "$POSTGRES_USER" -Fc -d '+$base+' -f '+$archivoContenedor)
if($LASTEXITCODE -ne 0){throw 'No se pudo respaldar la copia local.'}
docker cp ($contenedor+':'+$archivoContenedor) (Join-Path $respaldo 'staging-antes-georef.dump')
if($LASTEXITCODE -ne 0){throw 'No se pudo guardar el respaldo privado.'}
$huellaSql="SELECT count(*),md5(string_agg((to_jsonb(e))::text,',' ORDER BY id)) FROM entidades e; SELECT count(*),md5(string_agg((to_jsonb(r))::text,',' ORDER BY id)) FROM reservas r; SELECT count(*),md5(string_agg((to_jsonb(i))::text,',' ORDER BY id)) FROM inscripcion i;"
$antes=Sql $base $huellaSql
$temporal='multipro_test_georef_respaldo_'+(Get-Date -Format 'yyyyMMddHHmmss')
$creada=$false
try {
    Sql postgres "CREATE DATABASE $temporal;" | Out-Null;$creada=$true
    docker exec $contenedor sh -c ('pg_restore -U "$POSTGRES_USER" --exit-on-error -d '+$temporal+' '+$archivoContenedor)
    if($LASTEXITCODE -ne 0){throw 'No se pudo restaurar el respaldo de comprobacion.'}
    if((Sql $temporal $huellaSql) -ne $antes){throw 'El respaldo restaurado no coincide.'}
} finally {if($creada){Sql postgres "DROP DATABASE $temporal;" | Out-Null}}
if((Sql $base "SELECT count(*) FROM information_schema.columns WHERE table_name='localidades' AND column_name='codigo_georef'") -eq '0') {
    Sql $base (Get-Content -LiteralPath (Join-Path $repo 'database/migrations/20261030_010_catalogo_georef.sql') -Raw -Encoding UTF8) | Out-Null
}
$previos=@{};foreach($nombre in @('PGHOST','PGPORT','PGDATABASE','PGUSER','PGPASSWORD','PGSSLMODE','MAIL_ENABLED')){$previos[$nombre]=[Environment]::GetEnvironmentVariable($nombre,'Process')}
try {
    $env:PGHOST='127.0.0.1';$env:PGPORT='5433';$env:PGDATABASE=$base;$env:PGUSER='multipro_app_local';$env:PGPASSWORD=$config['PGPASSWORD'];$env:PGSSLMODE='disable';$env:MAIL_ENABLED='false'
    & php -c (Join-Path $infra 'php.ini') (Join-Path $repo 'web/cli/importar_georef.php')
    if($LASTEXITCODE -ne 0){throw 'No se completo la importacion del catalogo.'}
} finally {foreach($nombre in $previos.Keys){[Environment]::SetEnvironmentVariable($nombre,$previos[$nombre],'Process')}}
if((Sql $base $huellaSql) -ne $antes){throw 'Los datos de entidades, reservas o inscripciones variaron; revisar antes de continuar.'}
@('Georef oficial cargado solo en la copia de staging local.','Entidades, reservas e inscripciones conservadas.','Respaldo restaurado y comprobado.','No se hicieron cambios productivos.') | Set-Content -LiteralPath (Join-Path $respaldo 'resultado.txt')
Write-Output 'GEOREF_STAGING_LOCAL_OK historia_preservada'
Write-Output ('Respaldo privado: '+$respaldo)
