# Prompt de continuidad de MultiPro

Quiero continuar el desarrollo de la aplicación web y móvil de MultiPro.

Repositorio activo:
`C:\MultiPro-Migration\MultiPro-Pagos-MVP\PoliBA`

Antes de hacer cualquier cambio:

1. Leé completamente `C:\MultiPro-Migration\MultiPro-Pagos-MVP\PoliBA\MEMORIA_CONTEXTO_PAGOS_MVP.md`.
2. Verificá Git y Staging local en solo lectura. Para este bloque no es necesario acceder a Railway, PostgreSQL productivo ni Brevo/Cron. No muestres valores de variables ni secretos.
3. Contrastá la memoria con el estado actual. Si algo difiere, informalo antes de modificarlo; no reconstruyas decisiones ya documentadas.

## Forma de trabajo obligatoria

- Avanzá paso a paso y explicá alcance, entorno, riesgo y rollback antes de cada cambio material.
- No hagas commit, push, merge, publicación de APK, despliegue ni cambios en Railway o producción sin mi autorización expresa para esa etapa.
- No guardes ni muestres contraseñas, tokens, claves de Brevo, credenciales PostgreSQL, secretos OAuth o material de firma en Git, Android, JavaScript, logs o documentación.
- Usá siempre el nombre de producto MultiPro. Conservá identificadores técnicos o históricos existentes cuando renombrarlos pueda romper compatibilidad.
- Preservá reservas gratuitas, reservas presenciales, recuperación de contraseña, correos y recordatorios.
- Mercado Pago y el pago online deben seguir bloqueados hasta completar OAuth por entidad, checkout dinámico, validación real de webhooks, conciliación, reembolsos y pruebas externas.
- No borres ni reemplaces cambios locales del usuario. No uses operaciones destructivas de Git.

## Frecuencia vigente del Cron

El 2026-10-30 el usuario autorizó y se aplicó en Railway el cambio del Cron productivo de notificaciones a cada cinco minutos (`*/5 * * * *`). Atiende la bandeja general y la recuperación de contraseña; no hubo cambios de código, credenciales o SQL. Consultar el documento funcional vigente `documentacion/Notificaciones_y_Cron_MultiPro_v1.1.docx` y verificar la última ejecución al retomar. La tarea local de Windows conserva 15 minutos.

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
5. La selección del próximo PR ya está en preparación sobre `codex/prd-filtros-cascadas-asistencia`; pedir autorización antes de commit o push.

## Estado actual: filtros y cascadas en Local/Staging

El usuario autorizó completar el trabajo únicamente en Local y Staging y preparar el plan productivo después de probar y aceptar. Filtros múltiples de provincia, localidad y entidad ya implementados en Home, Actividades y Espacios. Cascadas, suspensión/reactivación por módulo, devoluciones pendientes y cierre administrativo implementados. No volver a presentar las reglas como abiertas ni la cascada como no implementada.

Leer la especificación existente, especialmente su sección 14, y `documentacion/VALIDACION_FILTROS_Y_CASCADA_LOCAL.md`. Resultado vigente: 81 consultas aisladas y 117 comprobaciones PostgreSQL/HTTP de cascada/ubicación; regresión de reservas, API y correo aprobada. Revisión visual desktop y web móvil aprobada; aceptación manual del usuario pendiente. APK sin cambios y sin nueva validación física.

Base activa local: `multipro_staging_filtros_cascada`, copia separada restaurada desde `multipro_staging_local` en Docker local 127.0.0.1:5433. Original conservado sin cambios. Migraciones 008/009 aplicadas solo a la copia. Web en `http://127.0.0.1:8080/`, correo deshabilitado. Datos ficticios DEMO de La Plata, Mar del Plata y Córdoba para revisar filtros; no se dedujeron ubicaciones de entidades históricas. La carga de ubicación se hace como Gestor desde Entidades.

Únicamente cobros y devoluciones bloquean cierre y baja del último administrador. No restaurar reservas canceladas ni marcar dinero entregado al generar solicitudes. Reactivar actividad/entidad no reactiva hijos; inscripciones se recuperan juntas por módulo con validación de cupos, edad, disponibilidad y conservación de espera. Espacio con módulos activos rechaza su baja hasta reubicarlos.

Próximo paso: acompañar la aceptación manual en la copia local y corregir hallazgos. Si el servidor se detuvo, iniciarlo con `staging-local/php.ini`, `MAIL_ENABLED=false` y raíz `web`, manteniendo solo 127.0.0.1. Evitar ejecutar el preparador otra vez: protege una copia existente y no la reemplaza. No descartar su historia ni sobrescribir la base original. Consultar respaldo privado indicado en el informe.

No hacer commit/push/merge, Railway, migraciones productivas ni publicación de APK sin autorización separada. Mantener Mercado Pago bloqueado. Preparar el plan productivo únicamente después de la validación del usuario. El cambio de conexión productiva a `multipro_web` queda diferido.


## Último pedido: presentación compacta de filtros

El usuario indicó «Espera» y pidió Filtros con tres desplegables Provincia, Localidad, Entidad. Ya está implementado y revisado en web desktop/móvil, manteniendo selección múltiple. Ver sección 15 de la especificación. Recibir revisión del usuario antes de continuar otros cambios.

Georef: datos oficiales descargados (24 jurisdicciones/4.028 localidades); migración 010/importación por lotes preparadas, NO aplicadas a la copia activa. La primera versión pasó pruebas temporales; la optimización por lotes posterior no fue validada porque se rechazó su ejecución elevada. No confundir esos estados ni afirmar que el catálogo está cargado. Se preserva compatibilidad con 009. No reanudar importación automáticamente mientras el usuario revisa la pantalla.


## Corte posterior: precarga oficial completada

El usuario autorizó retomar la precarga. Migración 010 e importación por lotes ya validadas y aplicadas SOLO a `multipro_staging_filtros_cascada`. Resultado: 24 jurisdicciones y 4.028 localidades oficiales; 3 manuales conservadas sin reasignar las entidades vinculadas. Pruebas específicas 4.053 aprobadas, 81 consultas aisladas del filtro aprobadas y tres pantallas públicas HTTP 200. Este corte sustituye los estados pendientes anteriores; no repetir autorización ni volver a aplicar 010 como si faltara.

Fuente y trazabilidad: `database/catalogos/georef/README.md`, `manifest.json`. Respaldo restaurado/comprobado: `staging-local/private/antes-georef-20261030-185555`. La aplicación usa datos locales; el Gestor selecciona provincia/localidad y conserva excepción manual si no encuentra la ubicación. Los filtros públicos muestran solo ubicaciones vinculadas a entidades activas. Mantener los tres desplegables ya aprobados. Próximo paso: aceptación manual en la copia local. Productivo, APK y Cron sin cambios.


## Último corte: recorrido funcional QA completado

Leer el apartado final del informe `documentacion/VALIDACION_FILTROS_Y_CASCADA_LOCAL.md`. Ya se ejecutaron los cuatro circuitos en una base QA aislada (8087), conservando Staging habitual (8080). Cierre administrativo confirmado, cero pendientes, historia accesible, sin movimientos duplicados y sin correos reales. No volver a pedir al usuario que registre la devolución ficticia: ya se completó mediante formulario HTTP autenticado/CSRF y se verificó en UI. El aviso nativo confirm() del navegador no pudo automatizarse; mantener ese límite explícito.

Corregidos mensaje de último administrador y etiqueta de reactivación; regresión completa posterior aprobada: cascadas 117, Georef 4.053, reservas/API/concurrencia y correo. Base QA conservada `multipro_qa_pantallas_20261030190259`, fixtures/logs privados fuera de Git, capturas en `documentacion/evidencias/qa-pantallas/`. El siguiente bloque es plan de migración productiva para revisar, sin ejecutar publicación. No hacer commit/push/merge, Railway, SQL productivo ni APK sin autorización separada. Mercado Pago bloqueado.

## Corrección Android posterior: contraste de formularios (03/11/2026)

Se corrigieron localmente los colores heredados de los campos de Inicio de sesión y Registro: texto y cursor oscuros, etiqueta y borde visibles, fondo blanco e iconos oscuros. Incluye todos los campos y el selector de entidad. La compilación `:app:assembleDebug` fue exitosa y el usuario confirmó el 03/11/2026 que la corrección se ve bien en el teléfono. La validación visual queda cerrada y no debe volver a figurar como pendiente. No se publicó otra APK; cualquier release sigue requiriendo una decisión separada.

Corrección posterior del mismo día: el selector nativo de fechas también heredaba texto demasiado claro sobre fondo blanco. Se creó un tema explícito de alto contraste y se aplicó a los calendarios de Disponibilidad, Asistencia y Registro. `:app:assembleDebug` fue exitosa y el usuario confirmó el 03/11/2026 que el calendario quedó corregido en el teléfono. Las validaciones visuales de campos y calendarios están cerradas; no volver a tratarlas como pendientes ni afirmar que existe una release publicada.
