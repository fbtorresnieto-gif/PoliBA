$ErrorActionPreference='Stop'
$OutputEncoding=[Text.UTF8Encoding]::new($false)
$repo=Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$infra='C:\MultiPro-Migration\MultiPro-Pagos-MVP\staging-local'
$origen='multipro_staging_local'
$destino='multipro_staging_filtros_cascada'
$contenedor='multipro-postgres-staging'
function Sql([string]$base,[string]$sql) {
    $salida=$sql | docker exec -i $contenedor sh -c ('psql -X -qAt -U "$POSTGRES_USER" -d '+$base+' -v ON_ERROR_STOP=1')
    if($LASTEXITCODE -ne 0){throw 'Fallo en la preparación local; no se cambió la base original.'}
    return ($salida -join "`n").Trim()
}
if((Sql postgres "SELECT count(*) FROM pg_database WHERE datname='$destino'") -ne '0'){throw 'La copia de revisión ya existe. No se reemplaza automáticamente.'}
if((Sql $origen "SELECT to_regclass('notificaciones_email') IS NOT NULL AND EXISTS(SELECT 1 FROM information_schema.columns WHERE table_name='notificaciones_email' AND column_name='requiere_reserva_confirmada')") -ne 't'){throw 'Staging original no tiene el esquema 001-007 esperado.'}
$marca=Get-Date -Format 'yyyyMMdd-HHmmss'
$respaldo=Join-Path $infra ('private\antes-filtros-cascada-'+$marca)
New-Item -ItemType Directory -Path $respaldo | Out-Null
$dumpContenedor='/tmp/multipro-antes-filtros-'+$marca+'.dump'
docker exec $contenedor sh -c ('pg_dump -U "$POSTGRES_USER" -d '+$origen+' -Fc -f '+$dumpContenedor)
if($LASTEXITCODE -ne 0){throw 'No se pudo respaldar staging.'}
$dumpLocal=Join-Path $respaldo 'staging-original.dump'
docker cp ($contenedor+':'+$dumpContenedor) $dumpLocal
if($LASTEXITCODE -ne 0 -or (Get-Item -LiteralPath $dumpLocal).Length -lt 100){throw 'No se pudo conservar el respaldo local.'}
Copy-Item -LiteralPath (Join-Path $repo 'web/.env') -Destination (Join-Path $respaldo 'web.env.antes')
# Restauración real del respaldo a una base nueva. Nunca borrar ni reemplazar la original.
Sql postgres "CREATE DATABASE $destino;" | Out-Null
docker exec $contenedor sh -c ('pg_restore -U "$POSTGRES_USER" --exit-on-error -d '+$destino+' '+$dumpContenedor)
if($LASTEXITCODE -ne 0){throw 'La restauración no terminó. Conservar la copia para diagnóstico; no activar la web.'}
$tablas=@('entidades','actividades','espacios','modulos','inscripcion','asistencia','usuarios','reservas','reserva_movimientos_presenciales','notificaciones_email')
$huellaSql=($tablas | ForEach-Object { "SELECT '$_',count(*),md5(string_agg((to_jsonb(t)-'fk_localidad'-'cierre_administrativo_en'-'desactivacion_operacion'-'suspension_operacion')::text,',' ORDER BY id)) FROM $_ t;" }) -join "`n"
$huellaOriginal=Sql $origen $huellaSql
if((Sql $destino $huellaSql) -ne $huellaOriginal){throw 'La copia no coincide con los datos históricos originales.'}
$sql='BEGIN;'+"`n"
foreach($ruta in @('database/migrations/20261030_008_ubicacion_entidades.sql','database/migrations/20261030_009_cascada.sql','database/migrations/local/20261030_009_permisos_cascada.sql')) {
    $contenido=Get-Content -LiteralPath (Join-Path $repo $ruta) -Raw -Encoding UTF8
    $sql+=[regex]::Replace($contenido,'(?m)^(BEGIN|COMMIT);\r?$','')+"`n"
}
$sql+="`nCOMMIT;"
Sql $destino $sql | Out-Null
if((Sql $destino $huellaSql) -ne $huellaOriginal){throw 'Se detectaron diferencias en datos históricos. No activar la web.'}
if((Sql $origen $huellaSql) -ne $huellaOriginal){throw 'La base original recibió escrituras concurrentes. Revisar antes de activar.'}
# La carga de demostración está identificada explícitamente y solo vive en la copia.
Sql $destino (Get-Content -LiteralPath (Join-Path $PSScriptRoot 'demo_local.sql') -Raw -Encoding UTF8) | Out-Null
$envPath=Join-Path $repo 'web/.env'
$config=Get-Content -LiteralPath $envPath -Raw -Encoding UTF8
$config=[regex]::Replace($config,'(?m)^PGDATABASE=.*$',('PGDATABASE='+$destino))
if($config -match '(?m)^MAIL_ENABLED='){$config=[regex]::Replace($config,'(?m)^MAIL_ENABLED=.*$','MAIL_ENABLED=false')}else{$config+="`nMAIL_ENABLED=false`n"}
[IO.File]::WriteAllText($envPath,$config,[Text.UTF8Encoding]::new($false))
@("Base original: $origen (sin cambios)","Copia activa: $destino",'Correo: deshabilitado','Migraciones: 008 y 009 aplicadas a la copia',"Respaldo: $respaldo",'El retorno de configuración usa web.env.antes; nunca descartar historia creada en la copia.') | Set-Content -LiteralPath (Join-Path $respaldo 'resultado.txt')
Write-Output 'STAGING_COPIA_Y_MIGRACIONES_OK historia_preservada correo_deshabilitado'
Write-Output ('Respaldo privado: '+$respaldo)
