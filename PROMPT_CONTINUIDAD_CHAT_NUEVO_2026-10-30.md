# Prompt de continuidad de MultiPro

Quiero continuar el desarrollo de la aplicación web y móvil de MultiPro.

Repositorio activo:
`C:\MultiPro-Migration\MultiPro-Pagos-MVP\PoliBA`

Antes de hacer cualquier cambio:

1. Leé completamente `C:\MultiPro-Migration\MultiPro-Pagos-MVP\PoliBA\MEMORIA_CONTEXTO_PAGOS_MVP.md`.
2. Verificá el estado real de Git, los servicios de Railway, PostgreSQL productivo, staging local y Brevo/Cron. Hacé esta inspección inicialmente en solo lectura y no muestres valores de variables ni secretos.
3. Contrastá la memoria con el estado actual. Si algo difiere, informalo antes de modificarlo; no reconstruyas decisiones ya documentadas.

## Forma de trabajo obligatoria

- Avanzá paso a paso y explicá alcance, entorno, riesgo y rollback antes de cada cambio material.
- No hagas commit, push, merge, publicación de APK, despliegue ni cambios en Railway o producción sin mi autorización expresa para esa etapa.
- No guardes ni muestres contraseñas, tokens, claves de Brevo, credenciales PostgreSQL, secretos OAuth o material de firma en Git, Android, JavaScript, logs o documentación.
- Usá siempre el nombre de producto MultiPro. Conservá identificadores técnicos o históricos existentes cuando renombrarlos pueda romper compatibilidad.
- Preservá reservas gratuitas, reservas presenciales, recuperación de contraseña, correos y recordatorios.
- Mercado Pago y el pago online deben seguir bloqueados hasta completar OAuth por entidad, checkout dinámico, validación real de webhooks, conciliación, reembolsos y pruebas externas.
- No borres ni reemplaces cambios locales del usuario. No uses operaciones destructivas de Git.

## Estado funcional verificado

- El MVP web fue fusionado a `main` mediante el PR 2 y Railway desplegó el commit de integración `f3ba33ca263cc98b7cc2ad70ac2f417a09f94d0a`.
- PostgreSQL productivo tiene aplicadas las migraciones 001 a 007 y pasó `ROLECHECK MULTIPRO OK` y `POSTCHECK MULTIPRO OK`.
- Funcionan reservas gratuitas y el circuito de reserva con pago presencial. El dinero presencial se gestiona fuera de MultiPro, pero el sistema registra cobro, cancelación, devolución pendiente y devolución realizada con auditoría.
- Funcionan recuperación segura de contraseña y notificaciones por Brevo HTTPS. El usuario confirmó recepción de emails, uso del enlace y cambio de contraseña.
- El procesador programado de notificaciones y recordatorios fue habilitado; confirmar en solo lectura frecuencia, última ejecución y colas con error antes de tocarlo.
- La confirmación de una reserva gratuita llegó correctamente al correo del usuario en la última prueba.
- El esquema de pagos online, transacciones, webhooks idempotentes y reembolsos está preparado, pero no hay conexiones activas de proveedor ni checkout productivo.
- Existe documentación de tesis con DER en `diagramas/Modelo_de_Datos_y_DER_MultiPro_para_Tesis.docx`.

## Infraestructura y advertencias

- Producción: proyecto/entorno Railway `MultiPro` / `production`; web desde `fbtorresnieto-gif/PoliBA`, rama `main`, root `web`; URL productiva `https://multipro-production.up.railway.app`.
- La aplicación productiva continúa temporalmente conectada a PostgreSQL como `postgres`. El rol restringido `multipro_web` existe y fue verificado, pero el primer cambio falló porque usuario y contraseña no se actualizaron atómicamente. No reintentar sin plan de cambio conjunto, prueba y rollback.
- Las credenciales administradas de PostgreSQL fueron rotadas después de una exposición accidental en una captura. No reutilizar valores anteriores ni copiarlos en el chat.
- Staging local y sus respaldos privados están fuera de Git en `C:\MultiPro-Migration\MultiPro-Pagos-MVP\staging-local`.

## Consolidación Git autorizada el 2026-10-30

El usuario autorizó integrar los pendientes, actualizar `main` y retirar la rama anterior. Al retomar, verificar el resultado real con `git status`, `git log` y referencias remotas; la sección siguiente es el corte previo a esa consolidación. El DOCX, generador e imágenes del DER se incluyen en la consolidación; `diagramas/render_modelo_tesis/` queda preservado localmente e ignorado por Git. No se autorizaron cambios de credenciales o base de datos ni habilitación de pagos online.

## Estado Git previo a la consolidación del 2026-10-30

- Rama: `codex/pagos-reservas-mvp`.
- HEAD: `c79db675f86ac33d84668dd28ae79b95b3c02416`.
- La rama sigue alineada con `origin/codex/pagos-reservas-mvp`, pero el `main` local está desactualizado respecto del remoto.
- Hay cambios locales sin commit en `MEMORIA_CONTEXTO_PAGOS_MVP.md`, `android/README.md`, `android/app/build.gradle` y archivos del DER. Revisarlos y preservarlos.
- No decidir automáticamente qué artefactos generados del DER deben versionarse.

## Estado Android cerrado

- Proyecto nativo Java/XML en `android/`, package `com.multipro.mobile`, mínimo API 26.
- La versión 1.3.1, `versionCode 14`, fue firmada mediante variables privadas `MULTIPRO_RELEASE_*`; la clave y contraseñas deben permanecer fuera de Git.
- APK local existente: `android/app/build/outputs/apk/release/app-release.apk`.
- Distribución por enlace público para quien lo tenga, sin Google Play.
- El usuario confirmó que la aplicación móvil funcionó correctamente en el teléfono, incluida la autenticación. Los incidentes anteriores de instalación y Google Sign-In están cerrados.
- No volver a abrir este bloque salvo que aparezca una regresión o se solicite una nueva versión.
- Mantener la misma clave de firma y aumentar siempre `versionCode`. Calcular y publicar SHA-256 del APK final.

## Pendientes priorizados

1. Diseñar el cambio seguro de conexión de la web desde `postgres` a `multipro_web`, con actualización atómica de credenciales y rollback; ejecutar solo con autorización productiva separada.
2. Hacer regresión productiva controlada de todos los tipos de email y del recordatorio de 24 horas, sin generar spam ni duplicados.
3. Integrar Mercado Pago como bloque independiente: OAuth por entidad, almacenamiento seguro de credenciales, checkout, webhook firmado, idempotencia, conciliación, vencimientos, devoluciones y pruebas sandbox/punta a punta.
4. Mejorar identidad de correo con dominio propio cuando exista; actualmente se usa un remitente verificado de Brevo sin dominio propio.
5. Revisar qué cambios locales y documentación deben entrar en un próximo PR; pedir autorización antes de commit o push.

## Próximo bloque recomendado

Empezá por una inspección general de solo lectura y presentame dos alternativas de próximo bloque:

- cambio seguro de la conexión productiva desde `postgres` hacia `multipro_web`; o
- integración de Mercado Pago, manteniéndola bloqueada hasta completar el circuito y las pruebas.

Para ambas alternativas indicá alcance, riesgos, dependencias, archivos o servicios que cambiarían, pruebas y rollback. Recomendá cuál conviene abordar primero.

No implementes ni publiques todavía hasta que yo autorice ese bloque.
