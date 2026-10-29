# MultiPro - paquete de despliegue controlado a Railway

Estado del paquete: **preparado localmente, no desplegado**.

## Estado de la versión candidata - 2026-10-28

Validado localmente:

- migraciones `001–007`, precheck y postcheck sobre una base temporal;
- reservas gratuitas y presenciales, cobro, cancelación y devolución total;
- recuperación de contraseña desacoplada del tiempo de Brevo y correos transaccionales;
- confirmación gratuita y recordatorios idempotentes 24 horas antes;
- concurrencia, permisos acotados y rollbacks que protegen historial;
- 211 archivos PHP sin errores de sintaxis en la validación integral original; control focal actual de 71 archivos de aplicación sin fallos, manifiesto de 51 archivos y `git diff --check` aprobados.

La inspección de Railway en modo de solo lectura se completó el 2026-10-28. Antes de publicar todavía es obligatorio generar un respaldo productivo verificable y obtener autorizaciones separadas para commit/push, cambios en Railway y despliegue. Composer CLI no estaba disponible en la PC de preparación; el build activo de Railway confirmó la instalación desde `web/composer.lock`.

## Inspección de Railway de solo lectura - 2026-10-28

- Proyecto y entorno: `MultiPro` / `production`; servicios `MultiPro` y `MultiProDB` en línea.
- Fuente web: GitHub `fbtorresnieto-gif/PoliBA`, rama `main`, directorio raíz `web`, commit `690d7b6f1ba9a4ad035b28303f237a5cec01fe87`.
- El commit desplegado coincide con el `HEAD` local, pero el MVP de este paquete permanece como cambios locales sin commit; por lo tanto, esos cambios no están desplegados.
- Build: Railpack `0.43.0`, PHP `8.5.12`, `composer install --optimize-autoloader --no-scripts --no-interaction` y extensiones `pdo`/`pdo_pgsql`.
- Inicio: no hay comando personalizado; Railpack genera `/start-container.sh` y sirve `/app` mediante FrankenPHP/Caddy por el puerto `8080`.
- PostgreSQL: versión `18.6`, base `railway`, rol efectivo y único rol no interno `postgres`. No existe separación actual entre rol migrador y rol web.
- Esquema productivo: 17 tablas heredadas; no existen `pago_conexiones`, `notificaciones_email` ni las estructuras de las migraciones `001–007`.
- Variables web observadas solo por nombre: conexión PostgreSQL, Google, SMTP heredado, remitente y `RAILPACK_PHP_EXTENSIONS`. Faltan `BREVO_API_KEY`, `MAIL_ENABLED`, `APP_URL` y `PGSSLMODE` explícito.
- No hay cron, healthcheck ni pre-deploy configurados. Reinicio `on failure`, máximo 10 intentos; una réplica en `sfo`.
- No se revelaron valores privados y no se modificaron servicios, variables, base, rama ni despliegues.

## Respaldo productivo previo - 2026-10-28

- Dump lógico completo creado con PostgreSQL 18 en formato custom, `--no-owner` y `--no-acl`, fuera del repositorio y dentro del directorio privado de staging.
- Archivo privado: `multipro_production_pre_mvp_20261028_235301.dump`, 63.038 bytes.
- SHA-256: `4FE30C887AB10254A36CFF530F64B66475153F3F436420243B6EF94D4A37AFCD`.
- `pg_restore --list` aprobó 162 entradas de catálogo.
- Restauración real aprobada en un PostgreSQL 18 temporal local: 17 tablas públicas y 10 reservas.
- El contenedor temporal fue eliminado al finalizar. El dump privado se conserva y no forma parte de Git ni del manifiesto productivo.
- La operación leyó producción mediante `pg_dump`; no modificó datos, esquema, roles, variables ni servicios de Railway.

## Preflight productivo de solo lectura - 2026-10-28

- Se ejecutó exactamente `database/deployment/20261027_preflight_production.sql` dentro de `BEGIN READ ONLY` y finalizó con `ROLLBACK`.
- Resultado: `PRECHECK MULTIPRO OK` sobre PostgreSQL `18.6`, base `railway`, rol actual `postgres`.
- Conteos de referencia: 10 reservas, 4 activas, 6 canceladas, 6 espacios y 3 solicitudes históricas de recuperación; ninguna recuperación activa.
- El esquema base y los estados son compatibles con la secuencia `001–007`; no hay aplicación parcial ni conflictos de unicidad activos detectados por el preflight.
- Esta aprobación no aplica migraciones ni autoriza publicación. Siguen pendientes la separación de roles, permisos, variables, cron, commit/push y autorización expresa de despliegue.

## Separación de rol migrador y rol web preparada - 2026-10-29

- `postgres` permanece como rol administrativo/migrador durante la ventana; la aplicación debe pasar a un login independiente, recomendado `multipro_web`.
- `20261029_create_web_role_production.sql` recibe el nombre por `app_role` y la contraseña exclusivamente desde `MULTIPRO_APP_DB_PASSWORD`; no incorpora ni imprime secretos.
- El rol web queda con `LOGIN`, sin superusuario, creación de bases/roles, replicación, bypass de RLS, herencia ni capacidad `CREATE` sobre la base o el esquema.
- `20261029_permissions_legacy_production.sql` concede CRUD solo sobre las 17 tablas heredadas y `USAGE` sobre sus secuencias, preservando la aplicación actual sin conceder DDL.
- `20261028_permissions_production.sql` restringe las tablas nuevas: configuración de proveedores solo lectura; transacciones/webhooks/reembolsos/notificaciones sin borrado; auditorías presenciales y promociones sin actualización ni borrado.
- `20261029_verify_web_role_production.sql` verifica atributos, membresías, conexión, esquema, tablas y secuencias dentro de una transacción de solo lectura.
- Prueba integral aprobada sobre una restauración del dump productivo: preflight, `001–007`, creación del rol, ambos bloques de permisos, `ROLECHECK MULTIPRO OK`, `POSTCHECK MULTIPRO OK` y login real del rol web con acceso a las 10 reservas. Pago online permaneció en cero y el contenedor temporal fue eliminado.

Pendientes conocidos que no se deben presentar como implementados:

- Mercado Pago: OAuth por entidad, checkout, HMAC, webhooks y conciliación reales;
- pago online, que debe permanecer bloqueado en base y UI;
- identidad de correo con dominio propio, DKIM y DMARC;
- cron de Railway para `web/cli/procesar_notificaciones.php`;
- APK Android productiva y validación final en dispositivo físico.

Este procedimiento publica el MVP de reservas gratuitas, pagos presenciales, recuperación segura de contraseña y notificaciones transaccionales por Brevo. No habilita pagos online, no conecta cuentas de proveedores, no activa correo sin variables privadas y no publica la variante Android `local`.

## Alcance de la entrega

- Precio final por hora en ARS y configuración presencial por espacio.
- Reservas gratuitas compatibles con el comportamiento anterior.
- Reservas con pago presencial pendiente, registro administrativo del cobro y auditoría.
- Cancelaciones y devoluciones presenciales según las reglas aprobadas.
- API para que un cliente móvil consulte precios, reserve y cancele.
- Estructuras de datos para una integración online futura, todavía inactivas.
- Recuperación encolada antes de consultar la cuenta; token de un solo uso almacenado exclusivamente como SHA-256.
- Transporte transaccional por la API HTTPS de Brevo, inactivo sin configuración privada.
- Cola idempotente y auditable para promociones, reservas presenciales, cobros, cancelaciones y devoluciones.
- Confirmación por email de reservas gratuitas y recordatorio único 24 horas antes para toda reserva confirmada.

Quedan fuera de esta entrega:

- OAuth, checkout, HMAC y webhooks de un proveedor real.
- Activación de `cobro_online_habilitado`.
- Credenciales o conexiones de proveedores.
- Remitente Gmail como identidad productiva definitiva; solo puede utilizarse para pruebas controladas.
- APK Android productiva. La APK `MultiPro Local` usa `127.0.0.1` y no debe publicarse.
- Los SQL de `database/migrations/local`, que son exclusivos del staging local.

## Condiciones obligatorias antes de autorizar

1. Identificar en Railway el servicio web, el servicio PostgreSQL, la rama y el commit que realmente están desplegados. No asumir que coinciden con este checkout.
2. Confirmar que el directorio raíz y el comando de inicio actuales siguen sirviendo `web/`, y que PHP dispone de `pdo_pgsql`.
3. Confirmar por separado el rol migrador y el rol que utiliza la aplicación web; no asumir sus nombres.
4. Ejecutar `database/deployment/20261027_preflight_production.sql` en modo de solo lectura. Debe finalizar con `PRECHECK MULTIPRO OK`.
5. Crear un respaldo lógico completo de PostgreSQL en una ubicación privada y comprobar con `pg_restore --list` que el archivo es legible. No guardar el respaldo en Git.
6. Registrar, fuera del repositorio, la hora, el identificador del respaldo, el commit candidato y los conteos informados por el precheck.
7. Ejecutar nuevamente la regresión local y verificar el manifiesto del paquete.
8. Obtener autorizaciones separadas para: commit/push, cambios en Railway y despliegue productivo.

## Variables de Railway

La aplicación utiliza únicamente nombres de variables; sus valores deben permanecer en Railway:

- `PGHOST`
- `PGPORT`
- `PGDATABASE` o `POSTGRES_DB`
- `PGUSER`
- `PGPASSWORD`
- `PGSSLMODE` (normalmente `require`)
- `APP_URL` opcional; si no está, la API utiliza `RAILWAY_PUBLIC_DOMAIN`.
- `BREVO_API_KEY` para correo transaccional, únicamente cuando se autorice activarlo.
- `MAIL_FROM` remitente verificado en Brevo.
- `MAIL_FROM_NAME` nombre visible, recomendado `MultiPro`.
- `MAIL_ENABLED=true` para habilitar envíos; mantenerlo ausente o en `false` hasta autorizar el correo productivo.

No copiar valores en comandos, documentación, JavaScript, Android ni archivos versionados. Las futuras credenciales del proveedor tampoco forman parte de este despliegue.

## Orden de publicación propuesto

Cada etapa es una compuerta: si falla, no continuar.

### 1. Congelar la versión candidata

- Revisar `git status`, `git diff --check` y el manifiesto.
- Crear el commit solo después de autorización expresa.
- No mezclar otros cambios con esta entrega.

### 2. Ventana y respaldo

- Elegir una ventana breve de baja actividad: la migración `001` bloquea `espacios` y `reservas` mientras modifica el esquema.
- Evitar escrituras durante respaldo y migración.
- Generar el respaldo lógico completo con las herramientas de PostgreSQL compatibles con la versión del servidor.
- Comprobar el catálogo del respaldo antes de continuar.

### 3. Precheck productivo

Ejecutar con `psql`, usando las variables privadas del entorno y deteniendo ante el primer error:

```text
psql -X -v ON_ERROR_STOP=1 -f database/deployment/20261027_preflight_production.sql
```

El precheck rechaza esquema parcial, versiones incompatibles, estados no migrables y reservas simultáneas del mismo usuario que impedirían crear los nuevos índices.

### 4. Migraciones de base

Aplicar, exactamente en este orden y con detención ante errores:

```text
database/migrations/20261026_001_reservas_pagos_base.sql
database/migrations/20261026_002_proveedores_pagos.sql
database/migrations/20261026_003_pago_confirmador.sql
database/migrations/20261026_004_presencial.sql
database/migrations/20261028_005_recuperacion_contrasena_segura.sql
database/migrations/20261028_006_notificaciones_email.sql
database/migrations/20261028_007_recordatorios_reservas.sql
```

No ejecutar los archivos de `database/migrations/local` ni los de `database/migrations/tests` en Railway. `005` invalida y depura solicitudes de recuperación anteriores porque contenían tokens en texto claro, y crea una cola que registra la solicitud web antes de consultar usuarios o contactar a Brevo; el precheck debe confirmar y registrar cuántas estaban activas. `006` crea la auditoría inmutable de promociones y la cola idempotente de correo; comienza sin historial. `007` vincula recordatorios con reservas para revalidar estado y horario antes de enviarlos.

La base se migra antes que la aplicación porque los campos nuevos tienen valores predeterminados compatibles con las reservas gratuitas existentes. Si una migración falla, su transacción se revierte; detener la publicación y diagnosticar antes de reintentar.

Después de confirmar el nombre exacto del rol web productivo, generar una contraseña privada y exponerla solo como `MULTIPRO_APP_DB_PASSWORD` en la sesión de ejecución. No incluirla en el comando, historial, documentación ni Git. Ejecutar:

```text
psql -X -v ON_ERROR_STOP=1 -v app_role=ROL_WEB_CONFIRMADO -f database/deployment/20261029_create_web_role_production.sql
psql -X -v ON_ERROR_STOP=1 -v app_role=ROL_WEB_CONFIRMADO -f database/deployment/20261029_permissions_legacy_production.sql
psql -X -v ON_ERROR_STOP=1 -v app_role=ROL_WEB_CONFIRMADO -f database/deployment/20261028_permissions_production.sql
psql -X -v ON_ERROR_STOP=1 -v app_role=ROL_WEB_CONFIRMADO -f database/deployment/20261029_verify_web_role_production.sql
```

La verificación debe finalizar con `ROLECHECK MULTIPRO OK`. No reutilizar automáticamente `multipro_app_local`: ese nombre pertenece solo a staging local. No cambiar `PGUSER`/`PGPASSWORD` de Railway hasta que migraciones, permisos y verificaciones hayan aprobado; ese cambio requiere autorización expresa.

### 5. Verificación de esquema

Ejecutar:

```text
psql -X -v ON_ERROR_STOP=1 -f database/deployment/20261027_postflight_production.sql
```

Debe finalizar con `POSTCHECK MULTIPRO OK`. Comparar los conteos de reservas y espacios con el precheck. El control también exige que online continúe bloqueado y que no existan conexiones de proveedor.

### 6. Publicación web

- Desplegar el commit candidato en el servicio web existente, sin cambiar variables ni dominios en la misma operación.
- Confirmar que Composer instala desde `web/composer.lock` y que el servicio inicia con PHP `>=8.2` y `pdo_pgsql`.
- No publicar `android/app/build/outputs`, la APK local, `.env`, respaldos ni archivos privados.
- Configurar, con autorización separada, un cron que ejecute `php web/cli/procesar_notificaciones.php` cada 15 minutos. Sin ese cron los recordatorios y las solicitudes de recuperación quedan en cola y no se despachan automáticamente.

### 7. Smoke test productivo

Realizar primero lecturas y luego un único recorrido controlado con cuentas de prueba autorizadas:

1. Página pública y `GET /api/v1/entidades.php`: HTTP 200.
2. Inicio de sesión web.
3. Reserva gratuita, consulta y cancelación.
4. Configurar temporalmente un espacio de prueba con precio y pago presencial.
5. Reserva presencial, registro administrativo del cobro, cancelación y devolución pendiente/realizada.
6. Confirmar que pago online no aparece habilitado.
7. Sin activar Brevo todavía: comprobar que recuperación responde de forma genérica, deja la solicitud en cola y no genera un token utilizable mientras el transporte no está configurado.
8. Cuando el correo sea autorizado: enviar una única recuperación controlada, abrir el enlace, cambiar la contraseña, comprobar uso único y revisar entrega en Brevo.
9. Con una cuenta de prueba, recorrer cada evento transaccional y verificar una sola fila por evento en `notificaciones_email`; no borrar auditoría para repetirlo.

Documentar los identificadores de los registros de prueba. No borrar auditoría ni reservas para “limpiar”; usar los flujos normales de cancelación y dejar trazabilidad.

## Reversión y recuperación

La reversión preferida antes de aceptar escrituras nuevas es:

1. detener la publicación web si todavía no comenzó;
2. ejecutar rollbacks únicamente en orden `007`, `006`, `005`, `004`, `003`, `002`, `001`;
3. confirmar el esquema anterior;
4. reanudar la versión web previa.

Los rollbacks están diseñados para **rechazar pérdida de historial**. Si ya existen reservas presenciales, movimientos, conexiones o pagos, no se deben forzar ni borrar datos para hacerlos pasar. En ese caso:

- volver la aplicación a la versión anterior solo si sigue siendo compatible;
- mantener el esquema ampliado, que es aditivo para el flujo gratuito;
- o restaurar el respaldo en una base nueva y hacer un cambio controlado de conexión después de analizar las escrituras posteriores.

Nunca restaurar destructivamente sobre la única base productiva sin validar antes el respaldo en una base aislada.

## Criterio de cierre

La entrega se considera desplegada solo cuando consten:

- commit y artefacto exactos;
- respaldo verificado;
- precheck y postcheck aprobados;
- migraciones `001–007` aplicadas una vez y en orden;
- aplicación saludable;
- regresión gratuita aprobada;
- recorrido presencial aprobado;
- recuperación segura aprobada y, si se activó Brevo, entrega controlada verificada;
- notificaciones transaccionales encoladas una sola vez y entregadas o reintentables sin alterar la operación principal;
- online bloqueado;
- observación inicial de logs sin errores de base o PHP.

Hasta entonces el estado correcto es **paquete preparado, producción sin modificar**.
