# MultiPro — Continuidad del MVP de reservas pagas

Fecha de corte: 2026-10-28
Estado: staging local operativo; migraciones 001/002/003/004/005 aplicadas; presencial web/API y Android preparado con APK local compilada; recuperación segura preparada con Brevo por HTTPS pero sin credenciales ni envío real; prueba de uso móvil pendiente; online bloqueado; producción sin cambios realizados desde este trabajo.

## Instrucción de continuidad

Este archivo es la fuente de verdad para retomar el MVP de pagos en una conversación nueva.
Antes de modificar código o base de datos, leerlo completo y verificar el estado real.
Avanzar paso a paso, explicar cada cambio antes de ejecutarlo y solicitar autorización antes de commit, push, despliegue o cambios en Railway.

## Rutas vigentes

- Repositorio activo: `C:\MultiPro-Migration\MultiPro-Pagos-MVP\PoliBA`
- Infraestructura local fuera de Git: `C:\MultiPro-Migration\MultiPro-Pagos-MVP\staging-local`
- Dump de referencia: `C:\MultiPro-Migration\MultiPro-Pagos-MVP\MultiProDB6_2026-10-17.dump`
- Punto de retorno de código y APK: `C:\MultiPro-Migration\Puntos-de-retorno\2026-10-26-pre-pagos`

## Estado de Git

- Rama local de trabajo: `codex/pagos-reservas-mvp`
- Commit base: `690d7b6f1ba9a4ad035b28303f237a5cec01fe87`
- Tag local de retorno: `pre-pagos-mvp-2026-10-26`
- No se hizo commit, push, merge ni despliegue.
- Cambios actuales: migraciones 001/002/003/004, rollbacks, diseño, permisos locales, servicios PHP, flujo presencial web/API, pruebas y este documento.
- `web/.env` está ignorado por Git y apunta exclusivamente al staging local.

## Puntos de retorno verificados

- ZIP de código: `MultiPro-codigo-690d7b6.zip`
- SHA256 ZIP: `02D03EB4A6D72689B1A1F124039496ADE4413EF562AB1FDA1AB1319583713A81`
- APK: `MultiPro-Android-1.3.0-debug.apk`
- SHA256 APK: `B292ABA03FC0ED10D4764429C07D4E5ED078AFF3904BEC640B27FD69F812FE4E`
- SHA256 dump: `0B1E3A034F60F030422B366DD1BAFEFA4C776016365F70E62DDB162AEF963BB8`
- Keystore resguardado en `privado-no-subir`; nunca subirlo a Git.

## Staging local

### Docker y PostgreSQL

- Docker Desktop operativo.
- Docker AI desactivado porque su Inference Manager impedía iniciar Docker.
- Respaldo de conexiones temporales antiguas: `C:\Users\Surface\AppData\Local\Docker\run-respaldo-20261026`.
- Compose: `C:\MultiPro-Migration\MultiPro-Pagos-MVP\staging-local\compose.yaml`
- Contenedor: `multipro-postgres-staging`
- Imagen: `postgres:18`
- PostgreSQL verificado: `18.6`
- Dirección local: `127.0.0.1:5433`
- Persistencia: `staging-local\docker-postgres`, fuera de Git.
- Credenciales: `staging-local\.env`, fuera de Git. No copiarlas a documentación ni mensajes.

Bases:

- `multipro_base_20261026`: referencia restaurada del dump. No usar para desarrollo.
- `multipro_staging_local`: base de trabajo.

Usuario de aplicación:

- `multipro_app_local`
- Tiene lectura y escritura de datos, pero no puede crear estructura.
- La contraseña se encuentra únicamente en los archivos locales ignorados.

Sanitización realizada solo en staging:

- 36 sesiones API copiadas fueron revocadas.
- 1 recuperación de contraseña pendiente fue invalidada.
- Correo saliente desactivado por ausencia de credenciales de Resend.

### PHP local

- Configuración: `staging-local\php.ini`
- Extensiones habilitadas: `pdo_pgsql`, `pgsql`, `curl`, `openssl`, `mbstring` y `fileinfo`.
- Web local: `http://127.0.0.1:8080`
- Endpoint comprobado: `/api/v1/entidades.php` devuelve HTTP 200 y 3 entidades.
- PID y logs están en `staging-local` y fuera de Git.

## Migración 001

Archivos:

- `database/migrations/20261026_001_reservas_pagos_base.sql`
- `database/migrations/rollback/20261026_001_reservas_pagos_base.rollback.sql`

Estado:

- Probada primero en una base temporal creada desde la referencia inmutable.
- Pruebas de migración y rollback correctas.
- Base temporal eliminada.
- Aplicada después a `multipro_staging_local`.
- No aplicada en Railway.

Cambios principales:

- Precio vigente por hora en ARS por espacio.
- Habilitación de cobro online y presencial por espacio.
- Importe final congelado en cada reserva.
- Duración fija de 60 minutos.
- Estados: `pendiente_pago`, `pendiente_presencial`, `reservado`, `cancelado`, `vencido`.
- Retención online de hasta 15 minutos.
- Auditoría de cancelación y marca explícita para cancelaciones históricas sin datos inventados.
- Unicidad concurrente por espacio, fecha y hora.
- Unicidad concurrente por usuario, fecha y hora en toda la plataforma.
- Índice de vencimientos y de historial del usuario.

Validaciones ejecutadas:

- 10 reservas preservadas.
- 6 cancelaciones históricas identificadas.
- 12 columnas nuevas y 4 índices nuevos presentes.
- Duplicados por espacio rechazados.
- Duplicados simultáneos del mismo usuario rechazados.
- Retenciones mayores a 15 minutos rechazadas.
- Pagos online con importe cero rechazados.
- PHP conecta como `multipro_app_local`.
- Endpoint público local continúa respondiendo HTTP 200.
- `git diff --check` correcto.

## Reglas funcionales aprobadas

- Cada entidad cobra en su propia cuenta.
- MultiPro no cobra comisión en el MVP.
- Moneda: pesos argentinos.
- Precio vigente individual por espacio y por hora.
- Turno fijo de una hora.
- No hay programación de tarifas futuras: el cambio de precio afecta operaciones nuevas.
- El precio mostrado es final.
- Sin descuentos, promociones ni precios especiales por usuario.
- Precio cero significa reserva gratuita y confirmación inmediata.
- El importe se congela al iniciar el pago.
- Retención de 15 minutos solo para pago online.
- La confirmación online debe provenir del proveedor/webhook validado, nunca del retorno del navegador.
- Solo usar medios online de acreditación inmediata.
- Cada entidad utiliza Mercado Pago o la pasarela que defina; el proveedor o la entidad emite la documentación fiscal, no MultiPro.
- La modalidad presencial es opcional cuando la entidad la habilita y la administra el administrador de esa entidad.
- Cancelación del usuario con 24 horas o más: devolución total.
- La entidad absorbe el costo operativo que el proveedor no reintegre.
- La cancelación administrativa es excepcional, exige motivo y auditoría.
- Confirmado por el usuario en el bloque presencial: cancelar administrativamente una reserva presencial ya cobrada genera siempre devolución total pendiente de realizar por la entidad, incluso con menos de 24 horas.
- Las reservas son personales y no transferibles.
- Un usuario no puede ocupar dos espacios en la misma fecha y hora en ninguna entidad de la plataforma.
- Historial unificado en el perfil; mostrar canceladas, no mostrar vencidas.
- Conservación del historial: 12 meses.
- No hay sanciones automáticas por ausencia en este MVP.

## Límites y seguridad

- Producción está en Railway Hobby y no fue modificada.
- No ejecutar SQL en Railway sin dump fresco, revisión y autorización expresa.
- No guardar tokens, contraseñas, access tokens de Mercado Pago ni secretos OAuth en Git, Android, JavaScript o documentación.
- No utilizar el archivo `web/.env` anterior: su respaldo privado está en `staging-local\private`.
- Mantener los cálculos de precio y validaciones de reserva en el servidor.
- Diseñar webhooks con idempotencia, validación de importe, moneda, entidad y referencia de reserva.

## Migración 002 preparada para revisión (2026-10-26)

- Relectura completa de esta memoria y verificación real realizadas en el repositorio activo.
- Git conserva rama `codex/pagos-reservas-mvp` y commit base documentados; sin commit, push, merge ni despliegue.
- Docker local saludable, PostgreSQL 18.6; staging conserva 10 reservas gratuitas. Endpoint local HTTP 200 y 3 entidades.
- Archivos nuevos: `database/migrations/20261026_002_proveedores_pagos.sql`, su rollback en `rollback/`, diseño `20261026_002_DISENO.md` y pruebas en `tests/20261026_002_validacion.sql` y `tests/validar_002_local.ps1`.
- Tablas propuestas: conexiones por entidad con referencia UUID a credencial privada, transacciones, eventos webhook deduplicados por conexión/evento y reembolsos totales idempotentes. No se guardaron secretos ni payloads.
- Consulta oficial vigente: marketplace de Mercado Pago utiliza OAuth por vendedor. OAuth por entidad es propuesta pendiente de revisión, no una conexión implementada. El diseño enlaza fuentes oficiales y documenta límites.
- Validación temporal exitosa: copia de la referencia local, 001 y 002, 19 rechazos de datos inválidos y flujos positivos, preservación del histórico, rollback vacío, reaplicación y rechazo de rollback con configuración existente. Base temporal eliminada.
- La prueba inicial se detuvo por no contemplar `restrict_violation` en el comprobador; se corrigió y la repetición completa pasó. No fue un fallo de la restricción SQL.
- Verificación final: cero tablas de 002 en staging y ninguna base temporal de pruebas remanente. 002 NO aplicada a `multipro_staging_local`. Railway/producción no se consultó ni modificó.
- Pendiente antes de aplicación/habilitación: revisar diseño, elección OAuth, privilegios y concurrencia. Se comprobó que los privilegios predeterminados locales conceden CRUD de tablas nuevas al rol de aplicación; la 002 aún no modifica esa política. No hay servicio PHP de pagos, validación real de firmas, integración externa ni cambios web/Android.
- Las pruebas SQL no demuestran idempotencia del futuro procesador, recuperación ante caídas, reembolsos reales ni validación funcional completa de reservas gratuitas por interfaz.

## Migración 002 aplicada exclusivamente a staging local (2026-10-26)

- Autorización expresa del usuario: confirmó que el OK correspondía únicamente al entorno local y luego indicó «Ok. Adelante». No autoriza Railway, commit, push, merge ni despliegue.
- Aplicada a `multipro_staging_local`, en el contenedor local documentado. La 002 y los permisos locales se ejecutaron en una sola transacción, con comprobación previa de base destino y huella de las reservas.
- Respaldo previo privado fuera de Git: `staging-local/private/multipro_staging_pre002_20261026204229.dump`, 77946 bytes. Archivo custom de PostgreSQL, catálogo legible con `pg_restore --list`; no se realizó restauración completa de este respaldo.
- SHA256 respaldo: `D0716A59BD1453951CECA602CC253AB108FAECA52320C55E99D609E5A17BAE63`.
- Permisos exclusivos de staging: `database/migrations/local/20261026_002_permisos_staging.sql`. Revoca privilegios de PUBLIC y del rol de aplicación en las cuatro tablas nuevas y sus secuencias. Solo concede SELECT de `pago_transacciones` al rol `multipro_app_local`, requerido por triggers de reservas/espacios. El administrador local conserva el control. No cambia privilegios predeterminados ni permisos de tablas anteriores.
- Sin servicio de pagos implementado, el rol de aplicación NO puede configurar conexiones, crear/actualizar pagos, webhooks o reembolsos, ni borrar auditoría. Antes del servicio, definir permisos específicos para sus operaciones. No habilitar CRUD indiscriminado.
- Prueba temporal ampliada y completada: 19 rechazos y flujos positivos de 002; permisos efectivos; creación y cancelación de reserva gratuita con rol de aplicación; rollback vacío, reaplicación y rechazo protegido. La copia de referencia no tenía permisos de aplicación, por lo que se reprodujeron exclusivamente en la base temporal los permisos verificados en staging antes de repetir exitosamente. Base temporal eliminada.
- Verificación final de staging: cuatro tablas de 002 presentes y vacías; 10 reservas gratuitas con huella anterior/posterior idéntica (`afc0caa26b115db16e9009f39c3668a4`); lectura/actualización de reserva gratuita con rol local probada dentro de una transacción revertida; endpoint HTTP 200 y 3 entidades.
- No se cargaron cuentas ni credenciales de proveedores; no se activaron cobros. Sin cambios PHP, web o Android. No se consultó ni modificó Railway/producción. Git permanece sin commits.
- Pendientes: pruebas de concurrencia, servicio PHP, validación real de firmas, conciliación, integración externa y regresión por interfaz. La configuración de OAuth sigue pendiente de resolución antes de conectar cuentas; aplicar el esquema no implementa ni activa OAuth.

## Servicio PHP interno y migración 003 (2026-10-26)

- El usuario indicó «Adelante» tras el cierre de 002 y el anuncio del servicio PHP como próximo paso. Trabajo exclusivamente local; no autoriza Railway, commit, push, merge ni despliegue.
- Implementación: `web/includes/pagos/ServicioPagos.php`, contrato `ProveedorPagos.php` y documentación `README.md` en ese directorio. Solo ambiente `prueba`; no hay adaptador Mercado Pago, endpoints de pagos ni llamadas externas. No crea checkout ni conecta cuentas. El proveedor simulado está únicamente en pruebas.
- Operaciones: iniciar intento sobre reserva online ya creada, propietario y retención verificados, referencia/clave estables, rechazo de segundo intento incierto; recepción de eventos autenticados por adaptador y deduplicación; procesamiento con arrendamiento recuperable, consulta fuera de transacción y validación de cuenta/ambiente/referencia/importe/moneda/acreditación inmediata; bloqueo por reserva y control de estados fuera de orden; vencimientos; cancelación del titular auditada con devolución a 24 horas o más; cobro tardío/duplicado genera solicitud de devolución total; ejecución de devolución con exclusión por conexión y clave estable ante timeout.
- No confundir contrato de autenticación del adaptador con firma real probada: las pruebas usan un doble. El retorno de navegador no confirma nada.
- Migración 003: `database/migrations/20261026_003_pago_confirmador.sql`, aplicada solo a staging. Agrega `confirmo_reserva`, unicidad de confirmador por reserva y protección de esa marca histórica. Necesaria para que notificaciones repetidas tras un cobro duplicado o una cancelación no devuelvan el cobro correcto por error. La 002 aplicada no fue reescrita.
- Rollback 003 probado vacío y protegido ante confirmadores. Rollback 002 ahora rechaza si 003 sigue aplicada; orden inverso obligatorio. No ejecutar rollbacks sin revisar uso del servicio y respaldo.
- Respaldo previo privado fuera de Git: `staging-local/private/multipro_staging_pre003_20261026205317.dump`, 107881 bytes. Catálogo legible; no se hizo restauración completa de este respaldo. SHA256: `8B629D01D63E49ABBDFA66288506B9DD7457DCECF2FB90A643F6A8C547683A1A`.
- Política local aplicada junto con 003 en una transacción: `database/migrations/local/20261026_003_permisos_servicio.sql`. El rol de aplicación tiene SELECT de conexiones, SELECT/INSERT/UPDATE de transacciones/eventos/reembolsos y USAGE de sus secuencias. No puede configurar conexiones ni borrar/truncar auditoría. Son permisos compartidos del servidor, no aislamiento por entidad; futuros controladores deben autenticar y autorizar cada operación.
- API gratuita: `web/api/v1/reservas/cancelar.php` completa auditoría de cancelación y se restringe a gratuitas; `crear.php` rechaza espacios con precio y vuelve a verificar precio cero bajo bloqueo al insertar. No se habilitaron rutas de reservas pagas.
- Pruebas: `tests/pagos/validar_local.ps1` y `servicio.php`. Copia temporal desde referencia, migraciones y permisos, PHP con rol real de aplicación, idempotencia, titular inválido, firma simulada inválida sin persistencia, evento conflictivo, datos de correlación incorrectos, estados fuera de orden, cobro duplicado, timeout y misma clave de devolución, vencimiento idempotente, cobro tardío, recuperación de arrendamiento, cancelación anticipada y a menos de 24 horas, confirmador inmutable y conservación del histórico.
- Concurrencia real con dos procesos PHP/conexiones PostgreSQL: mismo intento devuelve la misma fila y mismo evento se procesa una vez. No demuestra todas las carreras posibles ni comportamiento de Mercado Pago.
- Regresión HTTP en servidor temporal `127.0.0.1:8082` y base temporal: crear y cancelar gratuita con sesión sintética de Alumno, auditoría del titular, rechazo de espacio con precio. Configuración y credenciales existentes se leen solo para memoria de procesos, nunca se guardan en Git, argumentos de procesos o documentación. Servidor temporal cerrado y base eliminada.
- Verificación final de staging: 10 reservas gratuitas con la misma huella `afc0caa26b115db16e9009f39c3668a4`, cuatro tablas de pagos vacías, columna de 003 presente, permisos efectivos verificados y endpoint HTTP 200 con 3 entidades. Sin bases temporales remanentes. Sin cambios en datos de staging; sí cambios de estructura/permisos descritos.
- Pendientes específicos: adaptador Mercado Pago/OAuth, creación de reserva paga y checkout dinámico, endpoints autenticados, cancelación administrativa con motivo y política de devolución, pago presencial, ejecución programada de vencimientos/reintentos, conciliación completa de devoluciones/contracargos y retención. La interfaz administrativa web anterior aún no completa la auditoría exigida por 001 y debe adaptarse antes de su regresión; este hito solo verificó la API gratuita. No declarar funcionalmente probado todo web/Android.
- El procesador de reembolsos usa advisory lock de sesión de dos INTEGER; actualmente sus IDs deben caber en INTEGER aunque la tabla use BIGINT. Resolver antes de ampliar ese rango. Usar PDO no persistente y conservar el contrato de conciliación del adaptador.

## Configuración visible de cobro por espacio (2026-10-26)

- Autorización del usuario: «ok. Avanza» sobre el paso de agregar precio por hora ARS ($0 gratuito), opción de pago presencial y online bloqueado; trabajo únicamente local, sin habilitar aún reservas pagas.
- `web/abm/espacios.php`: campos en Agregar/Editar, columna de precio por hora, indicación de presencial configurado, avisos de que las reservas con precio no están disponibles. Precio y configuración se guardan en las columnas de 001 existentes, sin nueva migración ni cambios de permisos.
- `web/includes/pagos/ConfiguracionEspacio.php`: validación decimal en servidor, coma o punto, hasta dos decimales y límite NUMERIC(12,2); sin floats ni separadores de miles. Precio cero normaliza presencial a false. Online solicitado manualmente se rechaza; el control visual está deshabilitado. Formato monetario argentino de presentación sin cambiar el valor almacenado.
- `web/includes/pagos/campos_espacio.php`: parcial compartido de los campos. Control presencial deshabilitado con precio cero/inválido, explicación de configuración pendiente y online bloqueado. Las opciones no ejecutan cobros. Modales con desplazamiento; errores mantienen valores y reabren el formulario cuando corresponde.
- Formularios Agregar/Editar protegidos por CSRF de sesión. Actualización limitada a la entidad administrada y comprobación de fila modificada. No se ampliaron permisos de administración de conexiones ni se activaron proveedores.
- `web/espacios.php` y `web/espacio.php`: precio/gratuito visibles. La página de un espacio con precio no ofrece el formulario de reserva; rechaza también POST manual. Al insertar una gratuita revalida precio cero bajo bloqueo, conservando el importe histórico de reservas existentes. La API ya mantenía su bloqueo equivalente.
- Pruebas HTTP temporales ampliadas en `tests/pagos/servicio.php`, ejecutadas con `tests/pagos/validar_local.ps1`: login sintético de Administrador/Alumno, alta gratis, edición con coma decimal y presencial, persistencia al recargar, formato visible, online deshabilitado y POST manipulado rechazado, precios inválidos sin escrituras, CSRF inválido, otra entidad protegida, Alumno sin acceso a administración, aviso público pago y POST pago bloqueado, vuelta a precio cero con reserva web gratuita, cambio posterior de tarifa sin alterar su importe histórico. También pasó toda la suite anterior de servicio/API/concurrencia y rollbacks.
- El servidor temporal de pruebas inicialmente se bloqueó por su salida acumulada; se corrigió usando un archivo de log temporal que se elimina al cerrar. Un intento con lectura de pipes no bloqueante tampoco funcionó en Windows. Se cerró únicamente el servidor temporal 8082 identificado; el servidor principal 8080 permaneció operativo. La repetición completa pasó. No confundir esos problemas del comprobador con un fallo del guardado web.
- Verificación final: staging mantiene 6 espacios con precio cero y sin medios configurados, y 10 reservas con huella `afc0caa26b115db16e9009f39c3668a4` idéntica. Ninguna base temporal remanente. Listado público HTTP 200, API entidades HTTP 200 con 3 entidades. Las pruebas no dejaron precios ni usuarios sintéticos en staging.
- Para probar manualmente: `http://127.0.0.1:8080/abm/espacios.php`, iniciar sesión como Administrador de entidad, Agregar o Editar espacio, configurar precio y opción presencial, guardar y volver a abrir. Precio positivo queda como configuración; no permite aún reservar ni pagar ese espacio. Precio cero mantiene la reserva gratuita.
- No se conectó Mercado Pago, no se habilitaron cobros, no se modificó Android y no hubo commit/push/merge/despliegue ni acceso a Railway.

## Flujo web presencial y migración 004 (2026-10-26)

- Alcance autorizado: «Continua proximo bloque» tras anunciar el flujo de reservas con precio y pago presencial. El usuario respondió expresamente «Sí, devolución total pendiente» para cancelación administrativa de una presencial cobrada. No autoriza remoto, commit/push/merge ni despliegue.
- Al iniciar este bloque el staging tenía 11 reservas, no 10 como en el cierre anterior. Se preservaron todas, sin asumir origen de la adicional. Huella al inicio y final: `cbb70b5f63622bcf449f1321e738083a`. Los 6 espacios seguían gratuitos al inicio; las pruebas no cambiaron su configuración.
- `web/includes/pagos/ServicioReservas.php`: servicio para crear gratuitas/presenciales, registrar cobro, cancelar por titular o administrador y registrar devolución realizada. Actor obtenido de sesión y autorización real contra usuarios/roles/entidad en servidor. Transacciones y bloqueos por reserva; validación de fecha/hora completa, apertura por día, horario íntegro de una hora y módulos. Precio del formulario se compara contra precio actual bloqueado; el importe utilizado se toma del servidor y queda congelado. Reserva gratuita se confirma; presencial queda `pendiente_presencial`, ocupa turno y no tiene retención online de 15 minutos.
- `web/espacio.php`: muestra importe final y modalidad presencial explícita, online deshabilitado, CSRF para reservar y mensaje de pago pendiente. Rechaza importe desactualizado/manipulado, modalidad gratuita para espacio con precio y online manual. Un espacio con precio sin presencial habilitado no permite reservar. Envío repetido del mismo turno por su titular retorna la reserva existente si coincide; unicidad concurrente de 001 sigue protegiendo ocupación por espacio y por usuario.
- `web/perfil.php`: muestra importe congelado, pendiente/cobrado, devolución pendiente/realizada, cancelaciones y oculta vencidas. Cancelar usa POST+CSRF y auditoría; no modifica por GET. Muestra regla de devolución de 24 horas al titular de una presencial cobrada. La cancelación de gratuitas también usa este servicio; no crea movimientos de dinero.
- `web/abm/reservas.php`: administrador de la entidad puede registrar cobro presencial, cancelar con motivo obligatorio de hasta 500 caracteres y registrar devolución ya realizada. Formularios POST+CSRF, importe tomado de reserva, estados legibles y motivo visible. Cobro cambia la reserva a `reservado` solo después de registrar auditoría. Repetir cobro/cancelación/devolución no duplica movimientos. Ninguna acción transfiere dinero ni reemplaza entrega efectiva por la entidad.
- Cancelación presencial: sin cobro no solicita devolución; cobrada por titular con 24 horas o más genera devolución total pendiente; titular con menos de 24 horas cancela sin devolución; cancelación administrativa cobrada genera devolución total pendiente a cualquier plazo, por decisión expresa. La solicitud libera el turno al cancelar, pero no se marca dinero devuelto hasta que el administrador lo registra. Se usa `America/Argentina/Buenos_Aires`.
- `database/migrations/20261026_004_presencial.sql`: tabla `reserva_movimientos_presenciales` con eventos únicos por reserva/tipo (`cobro`, `devolucion_solicitada`, `devolucion`), entidad, importe total ARS, actor y fecha del servidor. No usa tablas online ni credenciales de proveedor. Triggers validan correlación, monto, actor/entidad y elegibilidad; movimientos inmutables. Identidad/importe de reserva presencial congelados y entidad del espacio protegida. Permisos locales: solo SELECT/INSERT y USAGE de secuencia para aplicación, sin UPDATE/DELETE/TRUNCATE.
- 004 y permisos aplicados juntos únicamente en `multipro_staging_local`, tras prueba temporal. Respaldo previo privado: `staging-local/private/multipro_staging_pre004_20261026212244.dump`, 110974 bytes; catálogo legible, sin restauración completa de este respaldo. SHA256: `3AFC85265BEB785B08FB9C50F4FDE543113D0E5621D265E8DCDC3CDD4A59C928`.
- Rollback 004 probado vacío y bloqueado con reservas/movimientos presenciales. Rollback 001 ahora exige revertir 004 antes de quitar columnas usadas por presencial. No forzar rollbacks con datos; no eliminar auditoría para hacerlos pasar.
- `api/v1/disponibilidad.php` y la comprobación previa de `api/v1/reservas/crear.php` consideran ocupados también `pendiente_presencial` y `pendiente_pago`. La API de creación sigue limitada a gratuitas; no se habilitó creación presencial por API/Android en este bloque. La cancelación API sigue gratuita; la nueva cancelación presencial está en web.
- Pruebas temporales: suite anterior más `tests/pagos/presencial.php`; reserva web pendiente, precio congelado al cambiar tarifa, modalidad/importe manipulados, disponibilidad API ocupada, cobro real concurrente con dos procesos y una fila de auditoría, propietario/actor/entidad incorrectos, CSRF, monto incorrecto rechazado incluso por BD, motivo obligatorio, cancelación administrativa y del titular con reglas de plazo, registro de devolución idempotente, imposibilidad de cobrar cancelada, no devolución sin cobro, auditoría e importe inmutables y rollbacks protegidos. Toda la suite pasó.
- Verificación final: 11 reservas intactas, movimientos presenciales de staging en cero, permisos SELECT/INSERT verificados, sin bases temporales remanentes. Web y API entidades HTTP 200; 3 entidades. Revisión sintáctica de 205 PHP web sin errores y prueba PHP adicional correcta. No hubo pruebas con dinero real ni cambios Railway/Android.
- Para prueba manual: Administrador configura precio y presencial en Espacios; Alumno reserva fecha/turno eligiendo presencial; en Perfil ve importe y pendiente; Administrador abre Reservas con esa fecha y registra cobro. Para devolver: cancelar (administrador con motivo o titular elegible), verificar pendiente y luego registrar devolución realizada en administración. Estas acciones sí crean registros locales de prueba del usuario: no borrarlos automáticamente al continuar.

## API presencial para preparar Android (2026-10-26)

- El usuario pidió «proximo bloque» después del cierre presencial web. Se continuó la API local, sin conectar proveedores, modificar cuentas ni cambiar Android. No autoriza Railway, commit/push/merge ni despliegue.
- Estado inicial de staging: 12 reservas y 1 movimiento presencial (más registros que en el cierre anterior); preservados. Huella de reservas antes/después idéntica: `69d6c34105e28f353f08e45bbea4530f`. No se restablecieron contraseñas de administradores ni se crearon usuarios de prueba en staging.
- API de espacios/detalle/disponibilidad devuelve precio decimal como texto, ARS, duración 60, presencial habilitado, online siempre false y modalidades disponibles. Nuevos helpers `_cobro_espacio.php` y `reservas/_datos.php` con acceso directo 404; serialización por lista explícita de campos, sin credenciales ni tokens de compartir.
- Crear/cancelar reutilizan `ServicioReservas` de web. Presencial exige elección explícita y `precio_visto` en texto decimal con dos cifras; el importe real viene del servidor. Online y precios manipulados/desactualizados rechazados. Clientes anteriores con solo espacio/fecha/hora mantienen gratuita a cero; no se convierten silenciosamente a presencial.
- Detalle/listado propios agregan importe congelado, modalidad, moneda, cobro presencial y devolución pendiente/realizada. Canceladas se conservan, vencidas no se exponen. Cancelación propia gratuita/presencial auditada e idempotente, con devolución según reglas vigentes. Cobro y registro de devolución permanecen exclusivamente en administración web.
- Identificadores de actor/titular, estados o importe final enviados extra por cliente no reemplazan al usuario autenticado ni al precio del servidor. ID inválido, tipo numérico incorrecto de precio y modalidad online se rechazan; reservas ajenas no se consultan ni cancelan.
- Contrato detallado y errores documentados en `web/includes/pagos/README.md`, sección API presencial. Cambios de campos aditivos respecto a clientes anteriores; creación mantiene respuesta 201 y cancelación 200. Las validaciones de negocio del servicio responden 409 y formatos inválidos 422.
- Prueba nueva `tests/pagos/api_presencial.php`, ejecutada dentro de la suite temporal existente: precios públicos, modalidades, autenticación, validaciones, creación presencial/reintento, titular manipulado, reservas ajenas, cobro registrado visible, precio congelado tras nueva tarifa, cancelación con devolución pendiente e idempotencia, devolución realizada, historial sin vencidas/con canceladas, campos internos ausentes y formato gratuito anterior. Toda la suite web/API/servicio/concurrencia/rollback pasó y se eliminó la base temporal.
- Verificación final de staging: 12 reservas con huella idéntica, 1 movimiento presencial, sin bases temporales remanentes; API entidades HTTP 200 con 3 entidades. Sin migraciones nuevas ni cambios de permisos/datos de staging. PHP revisado sin errores. Android/APK no se modificó ni compiló; falta probar el cliente móvil contra esta API.

## Android presencial y APK local preparada (2026-10-27)

- Autorización: «ok. adelante» tras proponer adaptar Android con precios, modalidad presencial, estados, cancelaciones y devoluciones, probando únicamente contra staging local y conservando producción. Proyecto confirmado: `android/` dentro de este repositorio. No se trabajó sobre `C:\MultiPro\MultiPro`.
- Se leyó completa esta memoria y se verificaron fuentes/SDK/configuración reales. No se modificaron migraciones, permisos, PHP ni datos de staging en este bloque.
- Variante nueva `local` en `android/app/build.gradle`: aplicación `com.multipro.mobile.local`, nombre MultiPro Local, versión `1.3.0-local`, API fija `http://127.0.0.1:8080/api/v1/`. Debug/release mantienen la URL de producción y el identificador original. Sesión/Keystore de la variante local separados por applicationId. Google Sign In oculto y client ID vacío solo en local; no se cambiaron credenciales ni se introdujeron secretos.
- Red local: configuración XML exclusiva de `src/local` permite HTTP solo a 127.0.0.1; resto HTTP bloqueado, manifest principal HTTPS sin cambios. ApiClient local rechaza otros hosts/puertos y no sigue redirecciones. Requiere `adb reverse tcp:8080 tcp:8080` desde teléfono/emulador conectado a esta PC; no funciona como APK remota autónoma ni requiere publicar PHP por Wi-Fi.
- Modelos `PaymentOptions`, `ArsMoney`, Space/Availability y Reservation: contrato decimal en texto y BigDecimal para presentar, sin float/double. Se conserva precio visto y fecha/modalidad de la respuesta que alimentó la confirmación. Contrato faltante/inválido no se asume gratuito; online no se ofrece aunque un dato externo lo sugiera. Se mantiene constructor anterior de solicitud gratuita compatible.
- Pantallas: precio en listado/detalle de espacio y disponibilidad, resumen final explícito con modalidad, pendiente presencial diferenciada de cancelada, importe congelado en Mis reservas/detalle, cobro y devolución pendiente/realizada, cancelación de pendientes presenciales o reservadas con aviso de regla 24 horas si estaban cobradas. Cancelación online no se habilita. No hay funciones de cobro administrativo dentro del móvil del alumno.
- Compilación verificada: `:app:assembleLocal`, `:app:assembleDebug` y APK del comprobador instrumentado. Ocho pruebas `PaymentsContractTest` pasaron (0 fallos/errores): contrato gratuito/presencial, falta de precio, montos grandes sin redondeo, serialización textual, campos heredados Gson, estados/devolución/cancelación y separación del destino local.
- Configuración de pruebas: AGP 9.4.1 genera por defecto solo pruebas del tipo seleccionado. Se agregó propiedad optativa `-PmultiproTestBuildType=local`; el valor habitual sigue debug. En PowerShell los argumentos con puntos como `-Pandroid.testInstrumentationRunnerArguments.class=...` requieren comillas para no fragmentarse. El componente oficial UTP faltaba en caché: se obtuvo mediante Gradle online autorizado; no se cambiaron versiones de dependencias.
- Pruebas de pantalla `PresentialScreensTest` y lectura real `LocalApiSmokeTest` preparadas. La primera ejecución en emulador API 36 falló 3/3 por falta de foco; se identificó una ventana ANR de `com.android.systemui`, no una excepción de la app. Se ajustó el comprobador (mantener pantalla/mostrar actividad de prueba) y se intentó arranque en frío sin borrar datos. El reintento no ejecutó pruebas por sistema aún arrancando; Gradle imprimió BUILD SUCCESSFUL pese al fallo de instalación. NO tomar ese resultado como aprobación de UI. La prueba Android→PHP de lectura no llegó a ejecutarse; HTTP de PHP desde PC sí respondió 200 con 3 entidades.
- El usuario eligió expresamente «Dejá la APK lista; la pruebo después» al ofrecer prueba en teléfono USB. Por eso se cerró el emulador iniciado por este trabajo y el daemon Gradle, sin instalar nada en un teléfono. Prueba funcional manual móvil y verificación en dispositivo físico siguen PENDIENTES. No afirmar que la UI ni el recorrido autenticado Android→PHP fueron validados.
- APK de entrega estable fuera de Git: `C:\MultiPro-Migration\MultiPro-Pagos-MVP\staging-local\android-apk\MultiPro-Local-1.3.0-presencial.apk`, 10317556 bytes. SHA256 `12CCBED3091B0F64B5A793198126D8AD1E72A98FC2D4FC7734254BBF9CCB2276`. Copia idéntica del generado `android/app/build/outputs/apk/local/app-local.apk`; no se entregó una APK con URL de producción para este MVP.
- Instrucciones reproducibles de compilación, instalación y puente USB en `android/README.md`. Requiere usuario Alumno del staging con contraseña conocida por el usuario; no se restablecieron contraseñas ni se usaron cuentas reales para pruebas de pantalla. Los fixtures usan sesión sintética solo en variante local y servidor simulado en el dispositivo, sin tocar la BD del usuario.
- Staging antes/después: 14 reservas con huella idéntica `54dfba82def164b6e83ed9c5efa0d998`, 6 movimientos presenciales. Se preservaron todos los registros locales del usuario. Emulador cerrado, sin dispositivos conectados al cierre. Sin commit, push, merge, despliegue ni consultas/cambios Railway.

## Paquete de despliegue Railway preparado (2026-10-27)

- Autorización: preparar el paquete para que lo realizado pueda quedar de forma segura en producción. Esta autorización se interpretó como preparación local; no autoriza commit, push, cambios de variables, acceso modificatorio, migraciones ni despliegue en Railway.
- `deploy/railway/README.md`: runbook con alcance gratuito/presencial, exclusiones, variables solo por nombre, compuertas, respaldo obligatorio, orden `001` a `004`, smoke tests, reversión y criterio de cierre. Declara expresamente que la APK local, los permisos `database/migrations/local` y los pagos online quedan fuera.
- `database/deployment/20261027_preflight_production.sql`: control de solo lectura para PostgreSQL 13+, esquema base, ausencia de aplicación parcial, estados migrables y conflictos de unicidad por espacio/usuario. Informa conteos no personales para comparar antes/después.
- `database/deployment/20261027_postflight_production.sql`: verifica tablas, columna de 003, índices y triggers críticos; exige cero conexiones de proveedor y que ningún espacio tenga online habilitado. Informa conteos posteriores. Ambos controles usan transacción de solo lectura.
- `deploy/railway/release-files.txt`, `release-sha256.txt` y `verify-package.ps1`: inventario productivo y 33 huellas SHA-256. El verificador pasó; los 30 archivos productivos modificados quedaron cubiertos y el escaneo de patrones de secretos de alta confianza dio cero coincidencias.
- Simulación de publicación en base temporal creada desde `multipro_base_20261026`: precheck aprobado, migraciones `001`/`002`/`003`/`004` aplicadas en orden, postcheck aprobado y segundo precheck rechazado correctamente por esquema ya migrado. La base temporal se eliminó.
- Regresión completa repetida: web/API/servicio/concurrencia/rollback aprobados (`PRUEBAS_SERVICIO_LOCAL_OK`), 207 PHP sin errores sintácticos y `git diff --check` aprobado. Staging final intacto: PostgreSQL 18.6 saludable, 14 reservas, 6 movimientos presenciales, 2 espacios con precio, cero online, cero conexiones y API entidades HTTP 200; ninguna base temporal remanente.
- No existe Dockerfile, Procfile ni configuración Railway versionada en este checkout; la construcción/directorio raíz/comando de inicio son configuración externa que debe verificarse en Railway antes de decidir cambios. Producción no fue inspeccionada ni modificada. HEAD continúa `690d7b6f1ba9a4ad035b28303f237a5cec01fe87` y todo el trabajo sigue sin commit.

## Próximo paso exacto

Las seis notificaciones transaccionales están implementadas y verificadas en staging local. El próximo paso funcional es una prueba manual controlada de cada recorrido desde la web local y la verificación de su entrega/estado en Brevo, sin borrar la auditoría generada. Para Railway, la próxima compuerta sigue siendo inspección de solo lectura del servicio, base, rama/commit, rol migrador, rol web, build/start y variables; requiere autorizaciones separadas para commit/push, cambios y despliegue. Android permanece diferido y pago online bloqueado.

## Recuperación segura y transporte Brevo local (2026-10-28)

- El usuario confirmó que dispone de cuenta Brevo, sin dominio propio, y mostró un remitente individual `MultiPro` con dirección Gmail verificada. Se aprobó avanzar. El remitente sirve para pruebas controladas; no se considera configuración productiva final por ausencia de dominio, DKIM y DMARC propios.
- Railway Hobby bloquea SMTP saliente; se eligió la API HTTPS oficial de Brevo `POST /v3/smtp/email`. `web/includes/mailer.php` reemplaza Resend por Brevo usando `BREVO_API_KEY`, `MAIL_FROM` y `MAIL_FROM_NAME`, sin valores incorporados. HTTPS exclusivo, sin redirecciones, timeouts y logs sin cuerpo externo ni destinatario. Sin clave o remitente válido devuelve fallo y no llama al proveedor.
- `database/migrations/20261028_005_recuperacion_contrasena_segura.sql`: depura 3 solicitudes legacy ya usadas/vencidas que guardaban tokens en claro; nueva tabla efectiva relaciona usuario, guarda solo SHA-256, vencimiento, aceptación de envío y uso. Índices garantizan hash único y una solicitud activa por usuario. No modificó usuarios, contraseñas, reservas ni pagos. Rollback protegido solo admite tabla vacía.
- `web/includes/RecuperacionContrasena.php`: respuesta externa genérica, email normalizado, bloqueo por usuario, máximo 5 solicitudes/hora, invalida enlace anterior, token aleatorio de 256 bits, activa solo después de aceptación del proveedor y lo invalida si el envío falla. El cambio de contraseña y consumo del token son atómicos; revoca sesiones móviles `api_sesiones`. Las sesiones PHP web no tienen registro central para revocación global.
- `olvide_contrasena.php` y `resetear_contrasena.php`: CSRF de sesión, URL desde `APP_URL`/`RAILWAY_PUBLIC_DOMAIN` y fallback exclusivo local, sin confiar en `Host`; token de uso único y mensajes que no revelan cuentas. No se agregó todavía recuperación dentro de Android.
- Se detectó que `web/abm/profesor_promocion.php` afirma que envió correo, pero no llama a ningún mailer. No se corrigió todavía porque el alcance de notificaciones debe definirse.
- Prueba temporal `tests/correo/validar_local.ps1`: migración, token hash, reemplazo, fallo de envío, cuenta inexistente, un solo uso, cambio de clave, revocación móvil, escape HTML, límite horario y rollback protegido/vacío. La primera ejecución detectó que una restricción impedía invalidar envíos fallidos; se corrigió y la repetición pasó (`PRUEBAS_RECUPERACION_BREVO_LOCAL_OK`, `PRUEBAS_CORREO_LOCAL_OK`). Base temporal eliminada.
- Respaldo privado previo a 005: `staging-local/private/multipro_staging_pre005_20261028195532.dump`, 122966 bytes, catálogo custom legible; SHA256 `144ABB02790E8D6BE3879C4E6EDF3C45C6B8B2CB97E911FAD6155F85C6771584`.
- 005 aplicada solo a `multipro_staging_local`: 12 usuarios y 14 reservas preservados; 3 recuperaciones legacy inactivas depuradas; tabla nueva vacía; rol de aplicación conserva SELECT/INSERT/UPDATE; online cero. HTTP local de solicitud desconocida/token inválido aprobado sin crear filas. 208 PHP sin errores.
- Paquete de despliegue actualizado: pre/postcheck y orden `001–005`, variables Brevo solo por nombre, 39 archivos con huellas verificadas. Simulación completa `001–005` en base temporal aprobada. Sin commit, push, Railway, credenciales Brevo ni correos reales.
- Prueba real Brevo completada posteriormente por autorización del usuario. Primer intento no llegó a Brevo por `cURL 60`: el PHP local no tenía bundle CA. Se configuraron `curl.cainfo` y `openssl.cafile` únicamente en `staging-local/php.ini`, fuera de Git, apuntando al bundle CA instalado con Git. La conexión anónima pasó de HTTP 0 a HTTP 401, confirmando TLS sin usar credenciales.
- El primer envío con TLS fue rechazado por Brevo porque `web/.env` tenía dos definiciones de `MAIL_FROM`/`MAIL_FROM_NAME` y el cargador conservaba la primera (`local@multipro.invalid`). Se eliminaron duplicados en el archivo local ignorado, manteniendo el remitente Gmail verificado y una única API key. El token del envío rechazado quedó inutilizable.
- Reintento único aceptado: Brevo registró `requests` y luego `delivered`. El usuario confirmó recepción, apertura del enlace y cambio de contraseña exitoso. Verificación final: cero enlaces activos, solicitudes fallidas/consumidas no reutilizables, cero sesiones API activas y 14 reservas intactas. No se mostró ni documentó la API key; no hubo cambios Railway ni commit/push.

## Notificaciones transaccionales y migración 006 (2026-10-28)

- El usuario indicó «Hace todas» para implementar seis eventos: promoción/cambio de módulo, reserva presencial creada, cobro presencial registrado, cancelación, devolución pendiente y devolución realizada. Trabajo limitado a staging local; no autoriza Railway, commit, push, merge ni despliegue.
- `database/migrations/20261028_006_notificaciones_email.sql`: agrega auditoría inmutable `inscripcion_promociones` y bandeja `notificaciones_email` con clave de evento única, estados, arrendamiento recuperable, hasta 20 intentos y datos JSONB acotados. Rollback rechaza pérdida si existe historial.
- `web/includes/NotificacionesEmail.php`: encola dentro de la misma transacción de negocio y despacha después del commit con `FOR UPDATE SKIP LOCKED`. Destinatario se deriva del usuario en servidor. Los fallos de Brevo no revierten la reserva/promoción y quedan reintentables; al agotar intentos pasan a omitida. Plantillas escapan datos dinámicos.
- `web/includes/mailer.php` exige `MAIL_ENABLED=true` y acepta una `IdempotencyKey` estable para Brevo. El interruptor fue agregado solo al `web/.env` local ignorado; la clave y el remitente no se mostraron ni versionaron.
- `web/includes/ServicioPromociones.php` reemplaza la promoción insegura anterior: verifica profesor del módulo actual, destino activo de misma actividad/entidad y edad, bloquea la inscripción, registra auditoría y encola correo. Ya no confía en nombre/email ocultos del formulario. `profesor_promocion.php` usa CSRF y comunica que la notificación quedó registrada.
- `ServicioReservas.php` encola idempotentemente creación presencial, cobro, cancelación y devoluciones pendiente/realizada. Los controladores web/API despachan solo después del commit. Las reservas gratuitas no generan estas notificaciones ni cambiaron su flujo.
- Respaldo previo privado: `staging-local/private/multipro_staging_pre006_20261028202214.dump`, 125062 bytes, SHA256 `681A40ED11EBE8F13C843FCFC6EFF9344141F00543CB4BC5A7AE23A1A94995B7`; formato custom y catálogo legible. No se hizo restauración completa.
- 006 y permisos acotados aplicados solo a `multipro_staging_local`. Estado inmediato: 14 reservas, 12 usuarios y 5 inscripciones preservados; tablas nuevas vacías. `multipro_app_local` tiene SELECT/INSERT de auditoría, SELECT/INSERT/UPDATE de bandeja y uso de secuencias; no DELETE ni modificación de promociones.
- Regresión completa en base temporal aprobada: precheck, `001–006`, postcheck, web/API gratuitas, presencial, concurrencia, seis notificaciones, escape, reintentos, idempotencia, promoción segura y rollbacks protegidos. El correo real estuvo desactivado y la base temporal se eliminó.
- Paquete Railway actualizado, no desplegado: SQL parametrizado de permisos productivos exige confirmar el rol web real; pre/postcheck cubren 006; manifiesto de 45 archivos y huellas aprobado. Producción/Railway no se inspeccionaron ni modificaron.

## Confirmaciones gratuitas y recordatorios 24 horas - migración 007 (2026-10-28)

- El usuario solicitó que las reservas gratuitas también generen email y un reminder un día antes. Se definió recordatorio para toda reserva confirmada (`reservado`): gratuita o presencial ya cobrada. Pendientes presenciales, canceladas y turnos pasados se omiten al despachar.
- `007` agrega relación opcional de notificación con reserva, marca de revalidación y tipos `reserva_gratuita_creada`/`recordatorio_turno`. Confirmación y programación se insertan atómicamente con la reserva; clave por reserva evita duplicados.
- `web/cli/procesar_notificaciones.php` solo admite CLI, incorpora idempotentemente reservas futuras confirmadas y despacha vencidas. Tarea de Windows `MultiPro Local - Notificaciones` activa cada 15 minutos; primera ejecución resultado 0. Railway necesita un cron equivalente por autorización separada.
- La regresión temporal detectó y corrigió un booleano PDO enviado como texto vacío. Luego aprobó flujo gratuito, no envío anticipado, envío confirmado, omisión de cancelada y toda la regresión previa. Correos reales desactivados en pruebas.
- Respaldo pre-007 privado y legible: `staging-local/private/multipro_staging_pre007_20261028204944.dump`, 137257 bytes, SHA256 `56B72564DC82E853FD9C107BE6B8C0BAF292102440DF9ED65ECECCF9C30062B4`.
- 007 aplicada solo a staging local. Se programaron dos recordatorios existentes: reserva gratuita 16 para 2026-10-29 15:00 ART y presencial confirmada 17 para 2026-11-01 18:00 ART. No se modificó Railway ni se hizo commit/push/merge.

## Versión candidata productiva consolidada (2026-10-28)

- Solicitud del usuario: dejar listo lo desarrollado para una futura publicación a PRD, reconociendo pendientes como Mercado Pago. Alcance interpretado como preparación local; no autoriza commit, push, merge, inspección modificatoria ni despliegue en Railway.
- Auditoría del manifiesto: 48 archivos productivos cubiertos y huellas aprobadas; cero coincidencias de secretos de alta confianza. El diseño histórico `20261026_002_DISENO.md`, pruebas, permisos locales y 30 archivos Android modificados quedan deliberadamente fuera del paquete web.
- Regresión final aprobada con código 0 y base temporal eliminada: pre/postcheck, `001–007`, web/API gratuita, presencial, pagos simulados internos, concurrencia, recuperación, promociones, emails, recordatorios y rollbacks protegidos. 211 PHP sin errores, manifiesto aprobado y `git diff --check` aprobado. Composer CLI no estaba disponible localmente; debe verificarse en el build real.
- Durante esta validación Docker Desktop falló por un socket `dockerInference` corrupto en `AppData\Local\Docker\run`. No se usó factory reset. Se renombró la carpeta temporal completa a `run.stale.20261028210336`, Docker la recreó, motor 28.4.0 y PostgreSQL volvieron saludables; staging preservó datos. Ese respaldo temporal puede eliminarse manualmente más adelante, no forma parte del repositorio.
- Estado staging tras recuperación: PostgreSQL saludable, 17 reservas y 6 notificaciones existentes, pago online cero. Los conteos reflejan pruebas manuales del usuario; no se restauraron ni borraron registros.
- Inspección Railway de solo lectura completada el 2026-10-28: proyecto/entorno `MultiPro`/`production`; servicios web y PostgreSQL en línea. Web desde GitHub `fbtorresnieto-gif/PoliBA`, `main`, root `web`, commit `690d7b6f1ba9a4ad035b28303f237a5cec01fe87`; coincide con `HEAD`, pero el MVP sigue sin commit y por eso no está desplegado. Railpack 0.43.0 ejecuta Composer, instala `pdo_pgsql` y arranca `/start-container.sh` con FrankenPHP/Caddy en 8080.
- PostgreSQL productivo confirmado por `SELECT`: 18.6, base `railway`, rol efectivo y único rol no interno `postgres`; no hay separación migrador/web. El catálogo visible conserva 17 tablas heredadas y no contiene tablas de pagos ni `notificaciones_email`; las migraciones `001–007` no están aplicadas.
- Variables revisadas exclusivamente por nombre, sin revelar valores. Existen las de PostgreSQL, Google, SMTP heredado, remitente y extensiones; faltan `BREVO_API_KEY`, `MAIL_ENABLED`, `APP_URL` y `PGSSLMODE` explícito. No hay cron, healthcheck ni pre-deploy. No se modificó Railway.
- Antes de PRD: crear y verificar dump productivo, definir roles separados y permisos parametrizados, autorizar commit/push, configurar variables privadas y cron con autorización, migrar `001–007` y ejecutar smoke test. Mercado Pago, identidad de dominio propio y APK productiva permanecen pendientes explícitos.
- Respaldo productivo de solo lectura completado el 2026-10-28: `multipro_production_pre_mvp_20261028_235301.dump`, almacenado fuera de Git en `staging-local/private`, 63.038 bytes, SHA-256 `4FE30C887AB10254A36CFF530F64B66475153F3F436420243B6EF94D4A37AFCD`. Catálogo custom legible con 162 entradas.
- Restauración real verificada en PostgreSQL 18 temporal local: 17 tablas públicas y 10 reservas. El contenedor aislado fue eliminado al finalizar; se conserva solamente el dump privado. Railway no fue modificado.
- Antes de PRD quedan: ejecutar el preflight productivo de solo lectura, definir roles separados y permisos parametrizados, autorizar commit/push, configurar variables privadas y cron con autorización, migrar `001–007` y ejecutar smoke test. Mercado Pago, identidad de dominio propio y APK productiva permanecen pendientes explícitos.
- Preflight productivo de solo lectura aprobado el 2026-10-28: `PRECHECK MULTIPRO OK`, PostgreSQL 18.6, base `railway`, rol actual `postgres`. Conteos de referencia: 10 reservas (4 activas, 6 canceladas), 6 espacios, 3 recuperaciones históricas y cero activas. El script se ejecutó dentro de `BEGIN READ ONLY` y terminó con `ROLLBACK`; no modificó producción.
- El preflight confirmó esquema legacy completo, ausencia de migración parcial `001–007`, estados compatibles y ausencia de duplicados activos por espacio/usuario en el mismo turno.
- Antes de PRD quedan: definir roles separados y permisos parametrizados, autorizar commit/push, configurar variables privadas y cron con autorización, migrar `001–007` y ejecutar smoke test. Mercado Pago, identidad de dominio propio y APK productiva permanecen pendientes explícitos.
- Separación de roles preparada y probada localmente el 2026-10-29. `postgres` se conserva como migrador; se agregaron SQL parametrizados para crear/endurecer un login web independiente, conceder CRUD explícito sobre las 17 tablas heredadas, aplicar permisos mínimos sobre el MVP y verificar atributos/privilegios en solo lectura. La contraseña se recibe solo mediante `MULTIPRO_APP_DB_PASSWORD`, nunca se incorpora al repositorio.
- Primera prueba detectó que PostgreSQL 18 no reconoce el metacomando `\\error`; los cuatro scripts productivos parametrizados fueron corregidos para abortar con `\\echo` y `\\quit 3`. La base era temporal y se eliminó sin afectar Railway.
- Repetición integral aprobada desde el dump productivo: preflight, `001–007`, rol `multipro_web_test`, permisos legacy/MVP, `ROLECHECK MULTIPRO OK`, `POSTCHECK MULTIPRO OK` y conexión real del login web con lectura de las 10 reservas. Cero pagos online, cero conexiones de proveedor y contenedor temporal eliminado.
- Antes de PRD quedan: autorizar commit/push, crear el rol web real y configurar sus variables privadas, cron y correo con autorización, migrar `001–007`, cambiar la aplicación al rol web y ejecutar smoke test. Mercado Pago, identidad de dominio propio y APK productiva permanecen pendientes explícitos.

### Alcance original de 002 (referencia)

Diseñar y preparar la migración 002, sin aplicarla inicialmente, para incorporar:

1. configuración de proveedor por entidad sin exponer secretos;
2. intentos/transacciones de pago por reserva;
3. eventos de webhook idempotentes;
4. reembolsos y sus estados;
5. correlación entre entidad, reserva, pago y referencia del proveedor;
6. auditoría suficiente sin almacenar datos personales o payloads sensibles innecesarios.

Antes de definir la forma de almacenar la conexión de cada entidad con Mercado Pago, validar en documentación oficial vigente si el MVP usará OAuth por entidad, credenciales administradas de forma segura o una modalidad compatible con webhooks y montos dinámicos. Un enlace de pago estático no debe asumirse suficiente para correlacionar y confirmar reservas.

Después de validar la migración 002 en una base temporal, aplicarla a `multipro_staging_local`, agregar el servicio PHP de pagos y recién entonces adaptar web y Android. Mantener compatibilidad con las reservas gratuitas durante toda la implementación.
