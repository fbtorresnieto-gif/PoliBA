# Runbook de despliegues y migraciones de MultiPro

Procedimiento breve para cambios productivos. Complementa [`deploy/railway/README.md`](../deploy/railway/README.md) y [`TESTING.md`](TESTING.md); no reemplaza los preflight, postflight ni reglas específicas de cada entrega.

## 1. Prerrequisitos y autorizaciones

- Declarar ambiente, alcance, complejidad técnica y riesgo operativo. Toda migración productiva es riesgo alto como mínimo.
- Obtener autorización explícita para cada operación productiva; commit/push, despliegue, migración, cambio de variables y restauración no se presuponen entre sí.
- Identificar responsable, ventana, criterio de éxito, condición de detención y recuperación prevista.
- Confirmar herramientas PostgreSQL compatibles y espacio privado para el respaldo. No registrar secretos ni incluir dumps en Git.
- Leer la migración, su rollback y las verificaciones aplicables. No ejecutar archivos de `database/migrations/local` ni `database/migrations/tests` en Producción.

## 2. Repositorio, rama y commit

Registrar antes de actuar:

- ruta del checkout vigente, rama y estado de Git;
- commit candidato y commit efectivamente publicado en la rama desplegable;
- rama, commit, directorio raíz y servicio que el proveedor muestra como desplegados.

Un `HEAD` local, un merge o una referencia remota no demuestran por sí solos qué commit está activo. Detenerse ante cambios sin relacionar, rama incorrecta o diferencias no explicadas.

## 3. Respaldo previo

- Crear un dump lógico completo en formato custom, sin propietarios ni ACL, mediante herramientas compatibles con el servidor.
- Guardarlo fuera del repositorio, en una ubicación privada, con nombre que identifique ambiente y momento.
- Registrar tamaño, SHA-256 y cantidad de entradas de `pg_restore --list`.
- No continuar si el dump está vacío, no puede listarse o la huella no queda registrada.

Railway sin backups administrados aumenta el riesgo: el dump manual es un punto de recuperación, no prueba que una restauración productiva funcionará.

## 4. Restauración temporal

Antes de migrar, restaurar el dump en una base PostgreSQL temporal y aislada. El patrón comprobado por las pruebas locales es:

```text
pg_restore -U "$POSTGRES_USER" --no-owner --exit-on-error -d <base_temporal> <respaldo.dump>
```

Verificar esquema, tablas y conteos relevantes; aplicar allí la migración y sus controles focalizados cuando corresponda. Eliminar la base temporal sólo después de registrar los resultados. Esta validación prueba legibilidad y compatibilidad local del dump; **no** acredita una restauración sobre Producción.

## 5. Transferencia verificada del SQL

Preferir un canal de transferencia de archivos confiable. Si la consola altera cargas o textos largos, el despliegue de la migración 012 comprobó esta alternativa:

1. Comprimir localmente el SQL con GZip y codificarlo en Base64.
2. Dividir el Base64 en fragmentos de 300 caracteres.
3. Crear un archivo vacío en el destino y anexar cada fragmento, sin saltos:

```text
: > /tmp/migracion.b64
printf '%s' '<fragmento-1>' >> /tmp/migracion.b64
printf '%s' '<fragmento-2>' >> /tmp/migracion.b64
```

4. Repetir hasta completar todos los fragmentos y comprobar la longitud esperada.
5. Reconstruir el SQL:

```text
base64 -d /tmp/migracion.b64 | gzip -d > /tmp/migracion.sql
```

No reutilizar fragmentos entre migraciones ni pegar valores privados. Un error de Base64, GZip, longitud o huella obliga a descartar el archivo reconstruido; no ejecutar `psql`.

## 6. SHA-256 obligatorio

- Calcular localmente la huella SHA-256 del SQL versionado.
- Calcularla nuevamente sobre el SQL reconstruido en el destino.
- Comparar los 64 dígitos completos, sin aceptar coincidencias parciales.
- Ejecutar solamente si ambas huellas coinciden. Conservar ambas huellas como evidencia.

## 7. Ejecución transaccional

Confirmar que la migración controla su transacción (`BEGIN`/`COMMIT`) y, cuando corresponda, límites de bloqueo. Ejecutar con configuración limpia y detención ante el primer error:

```text
psql -X -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -f /tmp/migracion.sql
```

No continuar si no se observa `COMMIT`. Un error exige detenerse, conservar la salida y comprobar el estado real antes de reintentar. Nunca repetir a ciegas una migración parcialmente aplicada.

## 8. Validaciones posteriores

- Ejecutar el postflight específico y consultas independientes sobre datos, restricciones, índices, funciones, triggers y permisos afectados.
- Comparar conteos críticos con el preflight y confirmar invariantes de negocio.
- Verificar una carga nueva de la aplicación y el flujo funcional mínimo autorizado.
- Separar claramente: esquema verificado, aplicación disponible y aceptación funcional. Ninguno prueba automáticamente los otros dos.
- Registrar lo no probado; no crear ni borrar datos productivos sólo para cerrar una evidencia.

## 9. Fallos y recuperación

- Antes de `COMMIT`: detenerse y verificar que la transacción se revirtió.
- Después de `COMMIT`: evaluar compatibilidad de la versión anterior y el rollback versionado. Los rollbacks que preservan historia son preferibles a eliminar datos.
- No ejecutar un rollback si sus precondiciones no se verificaron o si descartaría escrituras posteriores.
- Una restauración debe tratarse como operación destructiva independiente, con nueva autorización y ensayo aislado. Restaurar sobre la única base productiva **no está probado** por el despliegue de la migración 012.
- Tras dos fallos de la misma estrategia, registrar evidencia y cambiar la hipótesis. Los fallos de interfaz o transferencia también cuentan.

## 10. Evidencias a conservar

- alcance y autorizaciones;
- ruta, rama, estado Git y commits candidato, publicado y desplegado;
- ambiente, servicios y ventana;
- identificador privado del dump, tamaño, SHA-256 y resultado de `pg_restore --list`;
- resultado de restauración temporal y conteos de referencia;
- SHA-256 local y remoto del SQL;
- salida completa de preflight, migración, postflight y consultas focalizadas;
- salud y prueba funcional observadas, con sus límites;
- fallos, reintentos, cambios de estrategia y decisión de cierre;
- ubicación del respaldo y plan de recuperación, sin secretos ni datos personales.

El despliegue se cierra sólo cuando el commit activo, el respaldo verificable, la migración confirmada, los controles posteriores y los pendientes quedan registrados.
