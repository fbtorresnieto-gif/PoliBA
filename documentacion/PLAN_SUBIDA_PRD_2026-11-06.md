# MultiPro — Plan de subida a Producción

Fecha de preparación: 06/11/2026
Estado: plan para revisión; Producción no modificada.

## Avance del 06/11/2026

Las cuatro condiciones técnicas obligatorias fueron implementadas y pasaron la regresión local aislada:

- devolución del usuario con umbral de 5 horas; cancelación administrativa conserva devolución cuando existe cobro;
- mínimo de 8 caracteres para nuevas contraseñas en Web, API y Mobile, sin invalidar claves existentes;
- migración `011`, auditoría básica y rechazo de asistencia futura en Web/API/BD;
- importador Georef productivo protegido, permisos mínimos y verificación `FEATURECHECK` preparados.

Evidencia: APK debug compilada; `PRUEBAS_SERVICIO_LOCAL_OK`; `PRUEBAS_NOTIFICACIONES_LOCAL_OK`; `CASCADA_WEB_API_OK`; `CASCADA_Y_UBICACION_PG_OK casos=121`; `GEOREF_OFICIAL_OK provincias=24 localidades=4028 comprobaciones=4053`; importador productivo ejecutado dos veces sobre base temporal con los mismos conteos; base temporal eliminada.

Validación física confirmada por el usuario el 06/11/2026: el calendario de Asistencia no permite seleccionar fechas futuras. Las cuatro compuertas previas al PR quedan cerradas. No se realizó commit, push ni cambio productivo.

## 1. Objetivo

Publicar de forma controlada todo el producto terminado que permanece únicamente en el árbol local, preservando las funciones ya operativas en Producción y manteniendo Mercado Pago bloqueado.

La publicación se divide en dos entregas independientes:

1. Web, base de datos y documentación versionada.
2. APK Android firmada, después de validar la API web productiva.

El cambio de conexión productiva de `postgres` a `multipro_web` queda como una tercera ventana separada. No se combina con las nuevas funciones.

## 2. Línea base confirmada

- Rama local y remota: `main`.
- `HEAD` local y `origin/main`: `de42c6d984e235d901720e10ce9d041eb610f578`.
- Producción ya contiene el MVP y las migraciones `001–007`.
- Recuperación de contraseña, Brevo, notificaciones y Cron cada cinco minutos están operativos.
- Pago online/Mercado Pago continúa bloqueado.
- Los cambios descriptos abajo están sin commit y no fueron publicados.

## 3. Alcance candidato aún no publicado

### 3.1 Web y base de datos

- Filtros múltiples de Provincia, Localidad y Entidad en Home, Actividades y Espacios.
- Administración de ubicación estructurada de entidades.
- Catálogo oficial Georef: 24 jurisdicciones y 4.028 localidades.
- Desactivación/reactivación lógica y cascadas de entidad, actividad, módulo, espacio, administradores e inscripciones.
- Suspensión y recuperación conjunta de inscripciones, conservando lista de espera.
- Reubicación obligatoria de módulos antes de desactivar un espacio.
- Cancelación de reservas futuras y generación de devoluciones correspondientes.
- Separación entre baja pública y cierre administrativo.
- Protección del último administrador mientras existan cobros o devoluciones pendientes.
- Auditoría básica de asistencia y prohibición de registrar fechas futuras.
- Ajustes de disponibilidad de oferta y navegación derivados de las cascadas.

Migraciones nuevas, en orden:

1. `20261030_008_ubicacion_entidades.sql`
2. `20261030_009_cascada.sql`
3. `20261030_010_catalogo_georef.sql`
4. `20261105_011_auditoria_asistencia.sql`

### 3.2 Android

- Contraste de campos de Inicio de sesión y Registro.
- Cursor, etiquetas, bordes e iconos visibles.
- Tema de alto contraste para calendarios de Disponibilidad, Asistencia y Registro.
- Calendario de asistencia limitado hasta la fecha actual.
- Adaptación a las reglas comunes de contraseña que se terminen antes del corte.

### 3.3 Documentación versionable

- Especificación funcional de filtros y desactivaciones.
- Informe de validación local y evidencias QA seleccionadas.
- Documento vigente de Notificaciones y Cron `v1.1`.
- Excel finales de requisitos no funcionales Web, Mobile y unificados.
- Actualización de la memoria operativa y del prompt de continuidad.

No se deben versionar dependencias `node_modules`, archivos `*.inspect.ndjson`, previsualizaciones auxiliares, configuraciones privadas, dumps, APK debug ni datos DEMO.

## 4. Trabajo obligatorio antes de formar el release

### Compuerta A — Reglas aprobadas implementadas y probadas localmente

1. Cambiar la elegibilidad de devolución del usuario de 24 a 5 horas en Web, API, servicio de pagos, servicio presencial, pruebas y textos visibles.
2. Mantener independiente el recordatorio de reserva, que continúa programado 24 horas antes.
3. Unificar en ocho caracteres el registro y cambio de contraseña en Web y Mobile.
4. No invalidar contraseñas existentes; la nueva longitud rige cuando se crea o cambia una contraseña.
5. Actualizar documentación y pruebas que todavía describen 24 horas o seis caracteres.

Criterio de cierre: búsquedas de código y documentación no encuentran reglas antiguas aplicables, salvo el recordatorio legítimo de 24 horas y cortes históricos claramente identificados.

### Compuerta B — Asistencia 011 implementada y probada localmente

1. Aplicar `011` en una copia local aislada.
2. Probar alta de asistencia de hoy.
3. Probar corrección de una asistencia pasada.
4. Rechazar fecha futura en Web, API y selector Android.
5. Verificar `registrado_por`, `creado_en` y `actualizado_en`.
6. Probar rollback vacío y documentar que, con datos de auditoría, no debe perderse historia sin evaluación.

Criterio de cierre: pruebas PostgreSQL y HTTP aprobadas, APK debug compilada y prueba física del flujo de asistencia confirmada.

### Compuerta C — Georef productivo preparado y ensayado localmente

El importador actual rechaza correctamente cualquier entorno que no sea local. No debe quitarse esa protección para usarlo directamente en Producción.

Preparar un procedimiento productivo separado que:

- valide huellas SHA-256 del catálogo versionado;
- compruebe que existen exactamente 24 jurisdicciones y 4.028 localidades oficiales esperadas;
- sea idempotente;
- preserve localidades manuales, IDs y vínculos existentes;
- no reasigne automáticamente entidades históricas;
- ejecute con rol migrador y registre conteos antes/después;
- pueda ensayarse contra una restauración reciente de Producción.

Criterio de cierre: importación y reimportación aprobadas sobre clon productivo, sin duplicados ni cambios en entidades, reservas o inscripciones.

### Compuerta D — Permisos y verificación preparados y ensayados localmente

Crear y probar un SQL productivo adicional para:

- `provincias`, `localidades` y sus secuencias;
- `operaciones_estado` y su secuencia;
- nuevas columnas de `entidades`, `actividades`, `espacios`, `modulos`, `inscripcion` y `asistencia`;
- funciones y triggers de `009`;
- privilegios mínimos necesarios para `multipro_web`, sin conceder DDL ni borrado de auditoría.

Extender los controles productivos para verificar:

- ausencia de aplicación parcial de `008–011`;
- compatibilidad de datos actuales;
- conteos y huellas de entidades, reservas, inscripciones y asistencias;
- pago online aún bloqueado;
- cero pérdida de historial;
- disponibilidad de todas las funciones y triggers esperados.

Criterio de cierre: `PRECHECK`, permisos, login real del rol web y `POSTCHECK` aprobados sobre una restauración reciente de Producción.

## 5. Ensayo integral de release

1. Obtener un dump productivo fresco, privado, en formato custom, sin owner ni ACL.
2. Validar el catálogo con `pg_restore --list`.
3. Restaurarlo en PostgreSQL 18 local aislado.
4. Ejecutar preflight actualizado.
5. Aplicar `008`, `009`, `010` y `011` en ese orden.
6. Importar el catálogo oficial mediante el procedimiento productivo preparado.
7. Aplicar permisos productivos actualizados.
8. Ejecutar postflight.
9. Levantar la versión candidata Web contra esa base.
10. Ejecutar regresión automatizada completa y recorrido funcional crítico.
11. Ensayar rollback antes de crear historia nueva.
12. Conservar log, conteos, duración observada y SHA-256 del dump, sin secretos.

No se avanza si alguna prueba resulta fallida o no ejecutada.

## 6. Formación del PR

1. Crear una rama `codex/` desde el `main` actualizado.
2. Separar y revisar los archivos funcionales, migraciones, pruebas y documentación.
3. Excluir datos locales, dependencias generadas, secretos, dumps y artefactos auxiliares.
4. Ejecutar `git diff --check`, sintaxis PHP, pruebas de filtros, cascadas, pagos, correo, Georef y asistencia.
5. Compilar Android debug para regresión, pero no incluir el APK en el PR.
6. Crear commits trazables y abrir PR contra `main`.
7. Revisar especialmente seguridad, permisos SQL, compatibilidad de migraciones y reglas de devolución.
8. Aprobar y fusionar únicamente después del ensayo integral.

El merge puede disparar Railway. Antes de fusionar debe definirse si el despliegue automático se pausa o si la base se migra durante una ventana coordinada. No fusionar dejando la aplicación nueva contra un esquema antiguo.

## 7. Ventana Web/BD en Producción

Cada paso es una compuerta.

### 7.1 Preparación

- Confirmar proyecto, entorno, servicios, dominio, rama y commit actualmente desplegados.
- Confirmar salud de Web, PostgreSQL, Brevo y Cron.
- Revisar colas pendientes o con error.
- Elegir una ventana de baja actividad y comunicar indisponibilidad si corresponde.
- Evitar escrituras durante respaldo y migración.

### 7.2 Respaldo y preflight

- Generar dump lógico fresco y privado.
- Validar el dump y registrar SHA-256.
- Ejecutar preflight de solo lectura.
- Comparar conteos con el ensayo.

### 7.3 Migración

- Mantener temporalmente el rol migrador actual.
- Aplicar `008–011` en orden y detener ante el primer error.
- Importar catálogo Georef con el procedimiento aprobado.
- Aplicar permisos productivos actualizados.
- Ejecutar postflight y comparar huellas/conteos.

### 7.4 Publicación Web

- Desplegar el commit exacto del PR fusionado.
- No cambiar simultáneamente credenciales de base, dominio, Cron ni configuración de Mercado Pago.
- Confirmar arranque, healthcheck, logs PHP y conexión PostgreSQL.

### 7.5 Smoke test productivo

Con cuentas y datos de prueba autorizados:

1. Home, Actividades y Espacios responden y filtran por provincia, localidad y entidad.
2. Gestor puede asignar ubicación oficial sin alterar entidades históricas.
3. Baja/reactivación de una actividad de prueba conserva módulo e inscripciones según las reglas aprobadas.
4. Baja de espacio con módulo activo queda bloqueada; después de reubicarlo puede continuar.
5. Reserva gratuita y presencial siguen funcionando.
6. Cancelación del usuario libera el turno; devolución solo si restan cinco horas o más.
7. Cancelación administrativa genera devolución cuando corresponde.
8. Cierre administrativo queda bloqueado solo por cobros/devoluciones pendientes.
9. Profesor puede registrar hoy y corregir pasado; una fecha futura es rechazada.
10. Recuperación de contraseña, notificaciones y Cron continúan operativos sin duplicados.
11. Pago online permanece oculto y bloqueado.

### 7.6 Observación inicial

- Revisar logs, errores HTTP, colas, tiempos del Cron y consultas fallidas.
- Confirmar ausencia de movimientos duplicados y de nuevos pendientes económicos inesperados.
- Registrar commit, migraciones, respaldo y resultados del smoke test.

## 8. Release Android posterior

Solo comenzar cuando Web/BD lleve una observación inicial satisfactoria.

1. Actualizar `versionCode` de 14 a 15 y proponer `versionName` 1.3.2.
2. Compilar release con la misma clave privada ya utilizada; no exponerla ni versionarla.
3. Confirmar que la URL corresponde a Producción y que no se publica la variante `.local`.
4. Calcular SHA-256 del APK firmado.
5. Instalar como actualización sobre 1.3.1 en un teléfono real.
6. Probar login normal y Google, registro con ocho caracteres, calendarios, disponibilidad, reservas y asistencia sin fechas futuras.
7. Publicar el enlace de distribución solo después de la validación física.
8. Conservar el APK anterior como retorno.

## 9. Cambio a `multipro_web` en ventana separada

Después de estabilizar la entrega funcional:

1. Verificar permisos efectivos con el esquema `001–011`.
2. Preparar actualización atómica de `PGUSER` y `PGPASSWORD`.
3. Tener listos los valores anteriores y el procedimiento de rollback.
4. Cambiar ambas variables juntas.
5. Reiniciar, probar login, lecturas y escrituras controladas.
6. Revertir ambas variables si falla cualquier operación.

No combinar este cambio con migraciones, despliegue funcional o release Android.

## 10. Estrategia de reversión

### Antes de aceptar escrituras nuevas

- Detener el despliegue.
- Volver al commit Web anterior.
- Ejecutar rollbacks, si continúan siendo seguros, en orden `011`, `010`, `009`, `008`.
- Verificar esquema y aplicación anterior.

### Después de aceptar escrituras nuevas

Los rollbacks de cascada y ubicación protegen historia y pueden rechazar la eliminación de datos. No forzarlos ni borrar registros para hacerlos pasar.

Opciones, en este orden:

1. corregir hacia adelante;
2. volver el código Web si sigue siendo compatible con el esquema ampliado;
3. restaurar el respaldo en una base nueva y reconciliar escrituras posteriores antes de un cambio de conexión.

Nunca restaurar destructivamente sobre la única base productiva.

## 11. Fuera de alcance

- Mercado Pago, OAuth, checkout, webhooks y reembolsos online.
- Activación de pago online.
- Cambio de dominio o identidad de correo.
- Eliminación de la base o servicio anterior.
- Cambios funcionales adicionales no incluidos en este plan.
- Archivos de desarrollo, dependencias generadas y datos DEMO.

## 12. Autorizaciones separadas

Se solicitarán en este orden:

1. Implementar los cierres pendientes y completar pruebas locales.
2. Crear rama, commits y PR.
3. Fusionar el PR y permitir el despliegue Web.
4. Ejecutar backup y migraciones `008–011` en Producción.
5. Publicar la APK Android firmada.
6. En otra ventana, cambiar la conexión a `multipro_web`.

## 13. Criterio de cierre

La entrega se considera productiva únicamente cuando:

- commit y PR exactos están registrados;
- dump fresco fue validado;
- preflight y postflight aprobaron;
- `008–011` e importación Georef fueron aplicadas una vez;
- permisos mínimos fueron verificados;
- smoke test Web/BD aprobó;
- recuperación, notificaciones y Cron siguen operativos;
- pago online continúa bloqueado;
- logs iniciales no presentan errores críticos;
- APK firmada fue validada en teléfono y publicada con SHA-256;
- documentación y memoria operativa reflejan el estado final.

Hasta completar estas condiciones, el estado correcto es: candidato preparado o parcialmente publicado, no entrega cerrada.
