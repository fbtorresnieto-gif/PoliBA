# Filtros por ubicación y entidad y desactivación en cascada de MultiPro

Fecha: 30 de octubre de 2026.
Base revisada: `main`, commit `de42c6d984e235d901720e10ce9d041eb610f578`.
Estado: filtros y cascadas implementados y probados en Local y en una copia de Staging local. Pendiente de aceptación manual del usuario; sin publicación productiva.

## 1 Objetivo y decisiones confirmadas

Antes de Mercado Pago se priorizan dos entregas: filtrar los contenidos públicos por una o varias entidades y completar el tratamiento de las desactivaciones, incluyendo compromisos existentes y devoluciones.

Confirmado por el usuario:

- Home, Actividades y Espacios deben permitir seleccionar una entidad o varias.
- Al desactivar una entidad o un espacio se cancelan automáticamente las reservas futuras afectadas y se generan las devoluciones que correspondan. No se permite desactivar conservando esas reservas como confirmadas.
- Se mantiene la política administrativa ya aprobada: una reserva presencial cobrada cancelada administrativamente genera devolución total pendiente, independientemente de las 24 horas. El sistema no entrega ni transfiere ese dinero.
- Al desactivar una entidad, actividad o módulo se suspenden sus inscripciones y listas de espera; se conservan para una posible reapertura. No se cancelan definitivamente por esa baja.
- Antes de la baja individual de un espacio se deben reubicar sus módulos activos en otro espacio. Mientras queden módulos activos asignados, la baja se rechaza sin cancelar reservas ni modificar estados.
- Se separan operación pública y cierre administrativo: una entidad inactiva no acepta nuevas operaciones públicas, pero su administrador conserva acceso limitado a historial y resolución de devoluciones pendientes.
- Las inscripciones suspendidas se reactivan en conjunto por módulo, conservando su situación previa de cupo o lista de espera y verificando entidad, actividad y módulo activos.
- Desactivar una actividad desactiva sus módulos. Mientras la actividad esté inactiva no pueden reactivarse. Reactivar la actividad solo habilita su reactivación posterior; no los reactiva automáticamente.
- Las devoluciones permanecen pendientes hasta registrar la entrega real de dinero mediante el acceso de cierre administrativo, incluso con entidad inactiva.
- El cierre administrativo se bloquea exclusivamente mientras existan cobros o devoluciones pendientes. Durante ese período se conserva un administrador responsable y no se permite dar de baja al último. Reservas, inscripciones u otras gestiones no son bloqueos adicionales por sí mismas. Una cancelación sin cobro deja de ser un cobro pendiente; una devolución solicitada sigue pendiente hasta su entrega real, sin saldar ni cobrar importes automáticamente para permitir el cierre.
- Mercado Pago continúa bloqueado. El nuevo trabajo no autoriza por sí mismo commit, push, publicación de APK ni cambios productivos.

El alcance inmediato de las tres pantallas nombradas es la web, también en navegador móvil. Android tiene una navegación diferente: Home con novedades, exploración por entidad y espacios de la entidad seleccionada; no dispone de un catálogo de Actividades equivalente al de la web. Una ampliación visual de la APK debe definirse por separado. Los bloqueos y estados de negocio de las desactivaciones sí deben ser coherentes en web y API, aunque no se publique otra APK.

## 2 Situación actual verificada en código

| Área | Comportamiento actual | Consecuencia |
|---|---|---|
| Home web | Lista novedades vigentes, sin filtro por entidad ni condición de entidad activa. | Una novedad puede seguir visible después de desactivar su entidad. |
| Home Android | Consume `api/v1/novedades.php`, con la misma falta de control del estado de la entidad. | La inconsistencia no se limita al navegador. |
| Actividades web | Lista actividades activas de entidades activas. No hay selección múltiple. | El estado del padre se respeta en el catálogo, pero no existe el filtro solicitado. |
| Espacios web y detalle/API | Exigen espacio y entidad activos. El listado API recibe una sola entidad obligatoria. | La selección múltiple web debe agregarse sin romper ese contrato móvil. |
| Desactivar entidad | Cambia a inactivos entidad, actividades, espacios, módulos y subcategorías dentro de una transacción. | No cancela reservas ni resuelve inscripciones, devoluciones o novedades. |
| Desactivar actividad | Inactiva actividad y módulos. | No resuelve inscripciones; no debe afectar reservas independientes del espacio. |
| Desactivar espacio | Solo cambia el estado del espacio. | Módulos y reservas existentes pueden seguir activos. |
| Desactivar módulo o subcategoría | Cambia su estado individual. | Falta definir y aplicar el efecto sobre los registros dependientes. |
| Desactivar profesor | Inactiva su usuario. | Sus módulos pueden seguir activos y sin responsable operativo. |
| Reactivar entidad o actividad | Reactiva solo el registro elegido. | No hay identificación de qué hijos quedaron inactivos por la cascada y cuáles por una decisión anterior. |
| Perfil | Conserva reservas e inscripciones históricas, pero la etiqueta de inscripción depende de su propio estado. | Puede mostrar ACTIVA una inscripción de un módulo inactivo. |
| Recordatorio | Revalida que la reserva esté confirmada y sea futura. No controla estado del espacio o entidad. | Ocultar el espacio sin cancelar la reserva no alcanza para evitar un recordatorio improcedente. |
| Cambios de estado administrativos | Varias pantallas usan enlaces GET con confirmación del navegador. | El nuevo circuito requiere POST, protección de sesión, autorización y validación en servidor. |

Estos son hechos de las fuentes revisadas, no una nueva inspección completa del catálogo productivo. `database/schema.sql` es el esquema base; las migraciones posteriores modifican pagos, recuperación y auditorías.

## 3 Entrega de filtros por entidad

### 3.1 Comportamiento funcional

RF F01. Un mismo control de selección múltiple estará disponible en Home, Actividades y Espacios. Solo ofrecerá entidades activas, con nombre completo y selección visible. Debe poder utilizarse con mouse, teclado y pantalla táctil, sin exigir Ctrl o combinaciones de teclado para seleccionar varias.

RF F02. El estado inicial será «Todas las entidades». Elegir una muestra exclusivamente su información; elegir varias muestra la unión de sus contenidos. No hay restricción a la entidad asociada al usuario: el filtro organiza la consulta pública y no cambia permisos administrativos.

RF F03. Se mostrarán las entidades seleccionadas, la cantidad de resultados y una acción «Limpiar filtros». Limpiar vuelve a todas las entidades activas. La interfaz debe comunicar de forma inequívoca ese estado; desmarcar todas no significa un error ni debe dejar una selección oculta.

RF F04. En Home se filtran las novedades, que son el contenido institucional existente en esa página. El carrusel y los textos generales de MultiPro no son novedades de una entidad y conservan su función de navegación. Al elegir entidades se excluyen las novedades sin entidad, de modo que el resultado institucional sea estricto. Sin filtro pueden aparecer novedades generales vigentes.

RF F05. Una novedad asociada a una entidad inactiva no aparecerá en Home ni en el endpoint público equivalente. Las actividades y espacios seguirán exigiendo que el registro y su entidad estén activos. El selector no habilita mostrar contenido inactivo.

RF F06. Sin coincidencias se mostrará «No hay resultados para las entidades seleccionadas», con opción de limpiar. Una falla de consulta no se presentará como ausencia normal de resultados.

RF F07. La selección se expresará en la URL y se conservará al navegar entre las tres pantallas, recargar y volver con el navegador. Compartir esa URL reproducirá el filtro. No se modificará la afiliación del usuario ni se guardará una preferencia permanente en su cuenta.

RF F08. Los identificadores se validan en servidor. Se eliminan repetidos y se rechazan formatos anidados, valores inválidos o listas excesivas. Una selección explícita inválida o que dejó de estar disponible no debe ampliar silenciosamente la búsqueda a todas las entidades. Se comunica que hay que revisar o limpiar la selección.

RF F09. Los enlaces actuales de detalle y los permisos siguen funcionando. El filtro no afecta Mis reservas, historial, importes congelados, sesiones ni la administración de una entidad.

### 3.2 Pruebas de aceptación

| Caso | Resultado esperado |
|---|---|
| Sin selección | Todas las entidades activas; rótulo explícito. |
| Una entidad | Ningún resultado institucional de otra entidad. |
| Dos entidades | Unión de sus resultados, sin duplicar tarjetas. |
| Entidad sin novedades, actividades o espacios | Vacío explicado, con filtro conservado. |
| Limpiar | Se recupera el catálogo de entidades activas. |
| Pasar de Home a Actividades y Espacios | Se conserva la selección y la URL la representa. |
| Recargar o compartir URL | Se reproduce el filtro. |
| Entidad desactivada o ID manipulado | No aparecen datos inactivos ni se amplía inadvertidamente a todas. |
| Invitado, alumno y administrador | Mismo filtro público, sin ampliar privilegios privados. |
| Navegador móvil y teclado | Se pueden seleccionar y desmarcar varias entidades y aplicar el filtro. |

### 3.3 Componentes previstos

Helper de selección/validación y formulario compartido en `web/includes/`; consultas de `web/index.php`, `web/actividades.php` y `web/espacios.php`; conservación del filtro en la navegación pública de `web/includes/header.php`; coherencia de novedades en `web/api/v1/novedades.php`.

No se necesita una migración de base de datos para este filtro. No se sustituye el parámetro singular de `api/v1/espacios.php` usado por la APK. Cualquier contrato múltiple de API o selector Android se tratará como ampliación explícita.

## 4 Separación de efectos de una desactivación

No se debe resolver todo con ocultar una tarjeta o cambiar un booleano. Cada operación debe considerar cuatro efectos diferentes:

1. **Consulta pública:** qué deja de aparecer en catálogos y detalles.
2. **Operación:** qué acciones nuevas se rechazan en servidor, incluso desde una página abierta antes de la baja o una APK con datos anteriores.
3. **Compromisos:** reservas, inscripciones y listas de espera que ya existían y necesitan resolución o comunicación.
4. **Historia y dinero:** evidencia de cobro, cancelación, devolución y asistencia que permanece consultable y no se borra.

No se harán borrados físicos en cascada de registros con historia. Las relaciones `ON DELETE CASCADE` del esquema base no son el procedimiento funcional de baja y no deben utilizarse para resolver esta entrega. Tampoco se eliminarán auditorías para sortear restricciones.

## 5 Matriz de escenarios de baja

La cancelación automática de reservas, la suspensión de inscripciones y la reubicación previa de módulos para baja individual de espacio son decisiones aprobadas. Las otras filas identificadas como propuesta necesitan completar su definición antes de implementar sus efectos de negocio.

| Objeto desactivado | Nuevas operaciones y catálogo | Dependencias y compromisos | Dinero e historia |
|---|---|---|---|
| Entidad | Bloquear nuevas reservas e inscripciones; ocultar contenido institucional y novedades. | Afecta actividades, espacios, módulos y subcategorías. Cancelar automáticamente reservas futuras de sus espacios. Suspender y conservar inscripciones y espera. | Devolución total pendiente para presenciales cobradas canceladas; preservar toda la historia. No desactivar indiscriminadamente cuentas globales. |
| Espacio | Solo después de reubicar sus módulos activos, ocultar espacio y bloquear reserva web/API. | Si queda un módulo activo, bloquear la baja sin cambios. Si ya están reubicados, cancelar sus reservas futuras y mantener funcionando esos módulos en sus nuevos espacios. No inactivar la actividad completa. | Misma política administrativa de devolución; conservar cobros y cancelaciones. |
| Actividad | Ocultarla y bloquear nuevas inscripciones de su oferta. | Inhabilitar módulos dependientes y coherencia de subcategorías. Suspender y conservar inscriptos y espera. | No cancelar reservas libres del mismo espacio por desactivar una actividad. Las inscripciones no tienen un circuito de cobro equivalente al de reservas en este MVP. |
| Módulo | Ocultarlo y bloquear nuevas inscripciones, promoción hacia él y gestión operativa improcedente. | Suspender y conservar inscriptos adultos, menores y lista de espera. No promover automáticamente hacia otro módulo. | Preservar inscripción y asistencia histórica. No inventar reembolsos de aranceles que no se registran en este circuito. |
| Subcategoría | No ofrecerla para nuevas configuraciones. | Propuesta: bloquear su baja si hay módulos activos hasta reasignarlos o desactivarlos explícitamente. No debe desaparecer información histórica de edad/categoría. | Sin devolución automática propia. |
| Novedad | Ocultarla en web/API. | No afecta actividades, espacios ni reservas. | Sin efecto económico. |
| Profesor | Impedir nuevas sesiones y acciones según estado. | Propuesta: exigir reemplazo o resolución explícita de sus módulos activos. No cancelar reservas libres de los espacios donde enseñaba. | Conservar autoría de asistencia y promociones. |
| Administrador | Impedir sesiones/acciones de la cuenta inactiva. | Propuesta: impedir dejar una entidad sin responsable capaz de tramitar devoluciones pendientes. El Gestor puede reasignar responsabilidad mediante un flujo autorizado. | La obligación de devolución subsiste aunque se desactive el responsable anterior. |
| Alumno o responsable de menor | Bloquear el uso de la cuenta inactiva, conservando identidad histórica. | Pendiente definir tratamiento de sus reservas futuras, inscripciones y menores. No trasladar automáticamente a esta baja la política de cierre de entidad. | Debe quedar un canal administrativo para resolver devoluciones. Inactivar cuenta no equivale a marcar una devolución como realizada. |
| Menor | Pendiente definir baja propia: el esquema base no tiene estado de menor. | Preservar vínculo de responsabilidad e historia; revisar inscripciones antes de diseñar la baja. | No tratarlo como cuenta de login ni como titular de una reserva personal. |

## 6 Cancelación automática de reservas y devoluciones

### 6.1 Reservas alcanzadas

Regla confirmada: al desactivar una entidad se examinan sus espacios; al desactivar un espacio se examina ese espacio. Se cancelan automáticamente las reservas futuras afectadas.

Propuesta de frontera temporal: inicio del turno posterior al instante de confirmación, en hora de Buenos Aires. Un turno ya iniciado o terminado no se cancela retroactivamente por esta operación. Si también se requiere interrumpir un turno en curso, debe definirse como caso adicional y quedar señalado en la vista de impacto.

| Estado y modalidad antes de la baja | Resultado | Devolución |
|---|---|---|
| Gratuita confirmada y futura | Cancelada por causa administrativa. | No hay dinero que devolver. |
| Presencial pendiente y futura, sin cobro | Cancelada por causa administrativa. | No se crea devolución. |
| Presencial cobrada y futura | Cancelada por causa administrativa, con motivo y actor. | Solicitud de devolución total pendiente por el importe histórico cobrado, incluso a menos de 24 horas. |
| Ya cancelada con devolución pendiente | Sigue cancelada; no se duplica el movimiento ni el aviso. | Continúa la obligación existente y la capacidad de resolverla. |
| Ya cancelada con devolución realizada | Se conserva. | No vuelve a pendiente ni se duplica la devolución. |
| Vencida o histórica terminada | Se conserva. | No se inventan efectos económicos retroactivos. |
| Online pendiente o con cobro | Mercado Pago sigue bloqueado. Si se detecta una operación online inesperada, se detiene la ejecución automática para revisión. | No se marca dinero reembolsado sin conciliación real del proveedor. Diseñar este circuito en el bloque Mercado Pago. |

La devolución se apoya en evidencia de cobro, no solamente en una etiqueta de reserva. Una discordancia entre importe, movimientos y estado bloquea el caso para revisión; no se elimina el registro inconsistente.

### 6.2 Qué ve y confirma el operador

La cancelación será automática una vez confirmada la baja. La pantalla previa no ofrecerá conservar reservas confirmadas en una entidad/espacio que se desactiva. Debe mostrar:

- Objeto, entidad y alcance de la cascada.
- Cantidad de módulos/inscripciones afectados y reservas futuras por modalidad y estado.
- Cantidad de reservas cobradas y suma de devoluciones nuevas, en ARS, separadas de devoluciones ya pendientes.
- Compromisos en curso o excepciones que necesitan resolución previa.
- Motivo obligatorio y advertencia de que confirmar cancelará reservas y registrará las devoluciones pendientes.
- Responsable que continuará resolviendo el dinero y las comunicaciones.

El impacto se recalcula y valida en servidor al confirmar. Si cambió desde la vista previa, se presenta nuevamente; no se actúa sobre un conjunto distinto sin que el operador pueda revisarlo.

### 6.3 Integridad, permisos y ejecución

- Reutilizar las reglas de cancelación y los movimientos inmutables existentes; no sustituirlos por un `UPDATE` masivo sin auditoría.
- La baja de entidad la inicia un Gestor y la de espacio un Administrador de su entidad. El servicio de cancelación actual solo autoriza al Administrador asociado a la entidad: no basta llamar su método actual con el ID del Gestor. Diseñar autorización explícita y auditada, sin suplantar actores ni conceder acceso global indiscriminado.
- Bloquear creación concurrente de reservas/inscripciones y volver a verificar vigencia al confirmar. Una página que ya estaba abierta debe recibir un rechazo de negocio, no crear una nueva reserva después de la baja.
- Registrar baja, objetos afectados, cancelaciones, solicitudes de devolución y avisos de manera consistente. Para el volumen local esperado se propone una transacción acotada; si se requiere procesamiento por lotes, primero debe definirse cómo se bloquea el uso durante una baja incompleta y cómo se recupera una ejecución interrumpida.
- Reintentar una confirmación no debe duplicar cancelaciones, movimientos, importes ni correos.
- Nunca confirmar automáticamente una devolución como realizada. Esa acción acredita una entrega real posterior por la entidad y requiere actor autorizado.

## 7 Historia, visualización y notificaciones

RF B01. La baja quita del catálogo público lo que deja de estar disponible y rechaza nuevas operaciones. No borra Mis reservas, inscripciones, asistencias, movimientos ni auditorías.

RF B02. El perfil debe distinguir la situación de la inscripción de la disponibilidad del módulo. Las inscripciones afectadas se muestran SUSPENDIDAS por baja de entidad, actividad o módulo; la lista de espera suspendida conserva ese carácter y su posición histórica. No se muestran simplemente ACTIVAS ni CANCELADAS por esa causa. Se conserva el registro del adulto o menor, cupo/espera anterior, fecha y motivo de la suspensión.

RF B03. Regla aprobada: la administración mantiene acceso limitado a historial y resolución de cobros y devoluciones pendientes de entidades o espacios inactivos. No se puede exigir reactivar la oferta pública para resolverlos. La inactividad de la entidad no debe cerrar por sí misma ese acceso de su administrador. El cierre administrativo y la baja del último administrador responsable se bloquean mientras existan cobros o devoluciones pendientes, y únicamente por esos pendientes. Resolver un cobro no habilita cobrar una reserva ya cancelada: debe respetarse su estado y la política de cancelación. La conservación del historial continúa después del cierre.

RF B04. Una cancelación automática encola el aviso de cancelación y, si hay devolución, el aviso de devolución pendiente. El Cron de cinco minutos y los despachos existentes realizan los envíos. Una falla de Brevo no revierte la baja ya confirmada. Los recordatorios no enviados se omiten por reserva cancelada; no se retira un correo que ya haya sido aceptado por el proveedor.

RF B05. Para inscripciones/módulos debe definirse un aviso específico o un texto adecuado; el aviso actual de promoción solo describe un cambio de módulo y no debe reutilizarse como si informara una baja. El destinatario del menor es su responsable.

RF B06. La consulta por enlace compartido de una reserva cancelada no debe continuar mostrando una confirmación válida. La API y la web deben reflejar el mismo estado; la APK debe refrescarlo y manejar que un detalle de catálogo ya no esté disponible.

## 8 Reactivación

Regla aprobada para actividades: desactivar una actividad desactiva sus módulos y suspende sus inscripciones y listas de espera. Un módulo no puede reactivarse mientras su actividad siga inactiva. Reactivar la actividad habilita, pero no ejecuta automáticamente, la reactivación de sus módulos.

La reactivación de inscripciones se realiza en conjunto por módulo, no individualmente por alumno. Se incluyen las inscripciones suspendidas por la baja, conservando su situación anterior de cupo o espera; no se restauran inscripciones previamente canceladas por otra causa. Se verifican entidad, actividad y módulo activos y se conserva la trazabilidad de la operación.

Una reserva cancelada no vuelve a confirmada y una devolución pendiente no desaparece al reabrir un espacio. La reactivación de otros padres no debe tratarse como autorización para restaurar indiscriminadamente todos los registros.

La cascada debe distinguir estado propio de un registro y bloqueo heredado de sus dependencias. Hoy un booleano no permite saber si un módulo estaba inactivo antes de cerrar la entidad. El diseño técnico debe preservar esa diferencia mediante estado efectivo o auditoría de causas, sin reactivar un registro dado de baja por otro motivo.

Antes de reactivar oferta se validan entidad, actividad, espacio, profesor y configuración necesaria. Si cambios de capacidad o de condiciones impiden respetar la situación previa de las inscripciones, la reactivación conjunta no debe resolverlo reasignando cupos silenciosamente: se requiere resolver el conflicto. La recuperación de una baja con efectos económicos no se resuelve mediante rollback ciego de SQL.

## 9 Decisiones abiertas para completar el diseño

1. **Cierre administrativo ya definido:** solo cobros y devoluciones pendientes bloquean el cierre y la baja del último administrador responsable. No volver a preguntar qué clases de pendientes se incluyen. El procedimiento de sustitución de responsable debe preservar siempre al menos uno mientras haya esos pendientes; no implica permitir el cierre con deuda.
2. **Cierre total de entidad frente a baja individual de espacio:** el cierre total suspende su oferta; la exigencia de reubicar módulos fue respondida para la baja de un espacio. El diseño debe distinguir ambas operaciones y mostrar claramente su alcance, sin exigir mover oferta hacia una entidad diferente por defecto.
3. **Turnos en curso y bajas de usuarios:** completar reglas antes de extender la cancelación automática aprobada para entidad/espacio a esos casos.
4. **Android:** confirmar si se busca también un nuevo selector múltiple nativo de novedades/espacios. No existe una pantalla nativa de Actividades equivalente a la web; no se debe prometer una pantalla que la APK no tiene.

Los cinco puntos revisados uno por uno quedaron confirmados, incluida la reactivación conjunta por módulo y la dependencia actividad-módulo. No volver a presentarlos como abiertos. Los puntos restantes delimitan casos adicionales, no revocan lo aprobado.

## 10 Pruebas de aceptación de desactivaciones

| Caso | Evidencia esperada |
|---|---|
| Baja de entidad con reservas gratuitas y presenciales | Nuevas operaciones bloqueadas; futuras canceladas; cobradas con devolución total pendiente. |
| Baja de espacio | Solo se afectan sus reservas y dependencias; no reservas de otros espacios de la entidad. |
| Baja individual de espacio con módulos activos | Se bloquea sin cancelar reservas ni suspender inscripciones; se exige reubicación previa. |
| Baja de espacio después de reubicar módulos | Los módulos siguen activos en destino y conservan sus inscriptos; se cancelan las reservas libres futuras del espacio dado de baja. |
| Baja de entidad, actividad o módulo con inscriptos/espera | Se conservan suspendidos, con su situación previa y motivo, sin promover cupos ni borrar asistencia. |
| Baja de actividad | Se resuelve su oferta; las reservas libres del espacio no se cancelan por esa causa. |
| Reserva cobrada a menos de 24 horas | Se genera devolución administrativa total pendiente. |
| Reserva sin cobro | Se cancela sin crear obligación ficticia de devolución. |
| Repetir baja o confirmar dos veces | No duplica avisos ni movimientos. |
| Devolución previa pendiente o realizada | Se conserva su estado y su auditoría. |
| Devolución posterior a la baja | Puede registrarse sin reabrir catálogo ni reactivar entidad/espacio. |
| Crear mientras se confirma la baja | Resultado consistente y sin reserva nueva que escape a la cascada. |
| Operador de otra entidad o sesión inválida | Rechazo sin modificar estados ni emitir avisos. |
| Baja por GET o sin protección de sesión | Rechazo; la confirmación válida usa POST. |
| Historial, asistencia y reservas anteriores | Persisten con su evidencia original. |
| Reactivación | No restaura reservas canceladas ni elimina deuda; respeta hijos inactivos por otra causa. |
| Reactivar módulo con actividad inactiva | Se rechaza sin modificar módulos ni inscripciones. |
| Reactivar actividad | Sus módulos siguen desactivados hasta una acción explícita de reactivación. |
| Reactivar inscripciones suspendidas | Se hace conjuntamente por módulo y conserva cupo/espera anterior; rechaza dependencias inactivas o conflictos sin resolver. |
| Entidad inactiva con devolución pendiente | Administrador accede a historial y registra la entrega mediante cierre administrativo sin reabrir la oferta pública. |
| Cierre con cobro o devolución pendiente | Se bloquean el cierre y la baja del último administrador responsable. |
| Cobros y devoluciones resueltos, con inscripciones suspendidas u otros registros | Esos otros registros no bloquean por sí mismos el cierre; el historial se conserva. |
| Falla de correo | La operación permanece coherente y el aviso queda reintentable. |
| Rollback/interrupción durante ejecución | No quedan objetos desactivados con cancelaciones económicas parcialmente aplicadas. |
| Pago online inesperado o inconsistencia monetaria | Excepción visible y controlada; no se inventa reembolso. |

## 11 Orden de entrega y límites de publicación

Plan de implementación actualizado con las decisiones confirmadas punto por punto. Cada etapa termina con evidencia revisable. No se fijan fechas sin recuperar staging y comprobar el alcance técnico de la migración.

### Etapa 0 Preparar el entorno de validación

Revisar Git y preservar los cambios locales; recuperar disponibilidad de Docker/PHP de staging sin restaurar encima de datos del usuario. Crear una copia temporal de la base para las pruebas y respaldos privados antes de cambios estructurales. Bloquear correo real en esa copia y usar cobros/devoluciones simulados.

Cierre: conexión local comprobada, destinos de prueba identificados y datos originales preservados. No comenzar pruebas de cascada en producción. Recuperación: descartar únicamente el entorno temporal de prueba, conservando staging y respaldos.

### Etapa 1 Completar y validar filtros

El código ya está preparado localmente. Probar sobre PostgreSQL las tres pantallas completas: una/varias/todas las entidades, limpieza, URL compartida, navegación, vacíos, entradas inválidas e inactividad. Revisar la interfaz a ancho móvil y con teclado. Ejecutar regresión de navegación autenticada y de reservas gratuitas/presenciales. Verificar novedades web/API sin cambiar el contrato singular de espacios usado por Android.

Cierre: el filtro devuelve exclusivamente entidades válidas seleccionadas, conserva selección y no altera reservas ni permisos. No requiere migración. Puede publicarse como entrega independiente, con autorización separada. Recuperación: revertir el conjunto de código de filtro mediante un cambio versionado; no hay datos económicos que revertir.

### Etapa 2 Diseñar estados y auditoría de cascadas

Definir el mínimo cambio de esquema para distinguir operación pública de cierre administrativo, conservar causa de baja/suspensión, estado previo de inscripción y pertenencia a cupo/espera, y registrar actor, motivo, fecha y objetos afectados. Separar el estado propio de cada objeto del bloqueo heredado para no reactivar hijos que ya estaban desactivados por otra causa.

Diseñar el servicio central de cambios de estado y sus permisos: Gestor para entidad, Administrador para su oferta y acceso limitado de cierre. La autorización actual de cancelaciones administrativas no admite directamente al Gestor: resolverlo explícitamente sin suplantar actores. Preparar migración incremental, comprobaciones previas/posteriores y rollback protegido frente a historia o movimientos existentes.

Cierre: diseño trazable a reglas aprobadas y migración/rollback probados en copia temporal. No ampliar en esta etapa la baja de usuarios, menores o profesores con reglas no aprobadas. Recuperación: rollback de esquema solo cuando sus condiciones de seguridad lo permitan; nunca borrar auditoría o deuda para forzarlo.

### Etapa 3 Actividades módulos e inscripciones

Implementar primero la baja de módulo y luego la de actividad usando el mismo servicio: motivo obligatorio, POST protegido, autorización y suspensión de inscripciones/espera. Impedir nuevas inscripciones, promociones y operaciones incompatibles con oferta inactiva desde web y API. Mostrar la suspensión en los perfiles de adulto y responsable del menor y preparar los avisos correspondientes.

Reactivar actividad no reactiva sus módulos. La reactivación de módulo exige actividad y entidad activas y dependencias válidas. Incorporar la acción conjunta por módulo para recuperar las inscripciones suspendidas por esa causa, preservando su situación anterior. No recuperar canceladas ni redistribuir cupos silenciosamente; si hay un conflicto de capacidad o condiciones, mostrarlo y detener la reactivación conjunta hasta resolverlo.

Cierre: baja y reapertura completas, con historia conservada, sin promociones automáticas de espera ni activaciones de hijos por efecto secundario. Probar reintentos y repetición de acciones. Recuperación: operaciones compensatorias auditadas cuando ya exista historia; no reescritura de registros anteriores.

### Etapa 4 Espacios reservas y devoluciones

Antes de ejecutar una baja individual de espacio, comprobar módulos activos asignados. Si existen, bloquear toda la operación y exigir reubicación; no cancelar reservas parcialmente. Validar que la reubicación respete entidad, disponibilidad y solapamientos del espacio/profesor.

Cuando el bloqueo esté resuelto, mostrar impacto y confirmar baja con motivo. Cancelar automáticamente reservas futuras gratuitas y presenciales mediante las reglas de negocio existentes. Crear una única devolución total pendiente por cada presencial efectivamente cobrada, incluso a menos de 24 horas. Preservar movimientos previos, omitir recordatorios de reservas canceladas y encolar avisos sin depender de la entrega inmediata de Brevo.

Cierre: solo se afectan reservas del espacio elegido; un reintento no duplica movimientos ni avisos; una reserva creada concurrentemente no escapa a la baja. No ejecutar una devolución bancaria ni marcar dinero como entregado. Recuperación: no reconfirmar automáticamente reservas canceladas ni eliminar obligaciones de devolución al reactivar el espacio.

### Etapa 5 Entidad y cierre administrativo

Implementar la baja pública de entidad reutilizando suspensión y cancelaciones anteriores. Distinguir ese cierre total de la baja individual de un espacio: el cierre total inactiva la oferta de la entidad y suspende sus módulos/inscripciones. Mantener acceso limitado de su administrador a historial y resolución económica, sin nuevas operaciones públicas.

Implementar la comprobación de cierre administrativo y baja del último administrador: solo cobros y devoluciones pendientes los bloquean. Recalcular los pendientes dentro de la operación para evitar cierres simultáneos incorrectos. Una reserva cancelada sin cobro no se convierte en cobro pendiente; una devolución solicitada no desaparece por reactivar o cerrar oferta. Otras gestiones no añaden bloqueos. Conservar consulta histórica después del cierre y definir la sustitución de responsable manteniendo cobertura de pendientes.

Cierre: una entidad puede estar inactiva públicamente con gestión económica aún abierta; se resuelven pendientes sin reabrir oferta; el cierre se habilita únicamente al quedar sin esos pendientes. La prueba debe cubrir intentos de baja del último administrador y concurrencia con nuevos movimientos económicos. Recuperación: conservar administrador/responsable, auditoría y deuda; no borrar relaciones ni movimientos.

### Etapa 6 Regresión integral

Ejecutar la matriz de aceptación de este documento sobre PostgreSQL: filtros; entidad/actividad/módulo/espacio; cupo y espera; reactivaciones; reservas gratuitas y presenciales; cancelación y devolución real registrada; cierre administrativo; permisos, sesiones y acceso directo por web/API; repetición de acciones y concurrencia; fallos de correo y Cron. Probar la conservación de historia y el rechazo de borrados físicos. Mantener pago online bloqueado y detener casos online inesperados para revisión.

Cierre: informe de resultados con casos aprobados, evidencia y límites. Las pruebas aisladas del filtro no sustituyen esta regresión. Comprobar compatibilidad de la APK existente con los estados expuestos por API; cualquier ajuste/publicación móvil se informa y autoriza como entrega separada.

### Etapa 7 Publicación controlada

Presentar archivos, migraciones, resultados y procedimiento de recuperación. Obtener autorizaciones separadas para commit/push y producción; recordar que un push a main puede activar Railway. Para cascadas: respaldo productivo nuevo verificado, aplicación controlada de migraciones y permisos, despliegue y comprobaciones posteriores. No ejecutar bajas masivas como prueba productiva ni generar correos duplicados.

Cierre: versión y esquema realmente desplegados identificados, web/API/Cron operativos y comportamiento comprobado sin pérdida de historia. Si falla una migración antes de confirmar, detener/revertir la transacción; si ya existen cancelaciones o devoluciones, conservar los hechos y aplicar correcciones compatibles. La reversión de código no deshace dinero ni reactiva reservas.

Los cambios locales previos en memoria, prompt y documentos de notificaciones se preservan y no se mezclan sin revisión. En este relevamiento no se cambiaron estados de producción, no se cancelaron reservas y no se generaron devoluciones.

## 12 Fuentes revisadas

- `web/index.php`, `web/actividades.php`, `web/actividad.php`, `web/espacios.php`, `web/espacio.php`, `web/includes/header.php`.
- `web/abm/entidades.php`, `actividades.php`, `espacios.php`, `modulos.php`, `subcategorias.php`, `profesores.php`, `administradores.php`, `alumno_inscripcion.php`.
- `web/perfil.php`, `web/ver_reserva.php`, `web/includes/auth.php` y `web/includes/novedades.php`.
- `web/api/v1/novedades.php`, `espacios.php`, `espacio.php`, `disponibilidad.php`, reservas y profesor.
- `web/includes/pagos/ServicioReservas.php`, `web/includes/NotificacionesEmail.php`, `web/includes/ServicioPromociones.php`.
- `database/schema.sql` y migraciones de reservas, pagos y notificaciones existentes.
- `android/app/src/main/java/com/multipro/mobile/ui/HomeActivity.java`.

## 13 Corte histórico inicial del filtro (anterior a la ampliación por ubicación)

- Implementados `web/includes/FiltroEntidades.php` y `filtro_entidades_formulario.php`, usados por Home, Actividades y Espacios. Selección por checkboxes, aplicación por GET, limpieza, conteo y manejo de selecciones no disponibles.
- La navegación entre los tres catálogos y el enlace del carrusel a Espacios conservan las entidades seleccionadas. La navegación de otras páginas conserva su comportamiento anterior.
- Novedades web/API excluyen entidades inactivas, conservando novedades generales cuando no hay filtro. La API de espacios mantiene su contrato singular para Android.
- Prueba `tests/filtros/validar.php`: 42 consultas obtenidas de los archivos reales sobre SQLite en memoria; controles de aislamiento por entidad, catálogo inactivo, opciones vacías/incorrectas/manipuladas, formato de URL, formulario accesible y escape de nombres. Prueba adicional de la consulta API de novedades aprobada.
- Revisión de sintaxis de 73 PHP de aplicación sin errores; `git diff --check` correcto.
- Vista previa local del parcial real, con datos ficticios: selección múltiple, persistencia al pasar a Actividades, selección simple, sin resultados y limpieza comprobadas en Chrome. Servidor de prueba temporal cerrado al terminar. No es una prueba de las páginas completas contra PostgreSQL ni de una APK.
- Pendientes de cierre: restaurar disponibilidad de staging local y ejecutar integración/regresión web/API sobre PostgreSQL, incluyendo navegación autenticada, gratuita y presencial, y comprobación visual con ancho móvil. No se iniciaron servicios de staging ni se cambió producción para este bloque.
- La cascada aún no fue implementada. Durante este avance el usuario aprobó suspender/conservar inscripciones y espera, y exigir reubicación previa de módulos activos antes de la baja individual de un espacio. Se incorporaron como reglas, no como implementación ya terminada.


## 14 Implementación vigente en Local y Staging (30/10/2026)

La ampliación aprobada incorpora selección múltiple de provincias, localidades y entidades en Home, Actividades y Espacios. Dentro de un nivel se aceptan varias opciones; entre niveles se exige coincidencia. Las opciones de localidad se ajustan a provincias y las entidades a la ubicación **al aplicar** los filtros. Los tres parámetros se conservan al navegar y al limpiar se eliminan todos.

La migración 008 agrega `provincias`, `localidades` y `entidades.fk_localidad`. El Gestor carga provincia/localidad juntas desde el alta o edición de entidad. Se reutilizan nombres por provincia sin distinguir mayúsculas y se normalizan espacios; no se resuelven automáticamente alias, errores ortográficos ni nombres históricos. No se deduce ubicación de la dirección libre ni de coordenadas. Entidades sin ubicación siguen visibles sin filtro geográfico y mediante selección directa por entidad. Las localidades homónimas de provincias diferentes son opciones distintas, etiquetadas con su provincia. La ausencia de datos geográficos históricos requiere carga administrativa para que esas entidades aparezcan en filtros geográficos.

La migración 009 agrega cierre administrativo separado, causa de desactivación/suspensión, auditoría inmutable con actor/motivo y situación anterior de los objetos afectados, y validaciones de oferta, inscripciones, asistencia y responsable económico. `ServicioEstados` concentra las acciones. Las pantallas de entidad, actividad, espacio, módulo y administrador usan POST y protección de formulario, con vista previa y confirmación; los enlaces GET antiguos ya no cambian estados. La confirmación vuelve a comparar el impacto bajo el mismo cerrojo transaccional usado por reservas; si cambió, exige revisar de nuevo.

- Baja de espacio: rechaza si tiene módulos activos, sin efectos parciales; después de reubicarlos, cancela reservas futuras del espacio y conserva el módulo operativo en su destino.
- Baja de entidad: desactiva actividades, espacios y módulos, suspende inscripciones/espera y cancela reservas futuras. Una reserva online inesperada detiene la baja para revisión. No suplanta al Gestor por un administrador al auditar solicitudes de devolución. Si van a generarse devoluciones y no existe administrador activo, exige asignarlo antes.
- Baja de actividad/módulo: suspende las inscripciones activas y espera; conserva las previamente canceladas. Una actividad reabierta no activa módulos; la reactivación del módulo requiere dependencias activas y agenda sin superposición.
- Inscripciones: reactivación conjunta y explícita por módulo; conserva cupo/espera y fecha originales. Conflictos de capacidad, duplicados, cuenta/tutor inactivo o edad incompatible detienen todo el conjunto. No se restauran reservas canceladas.
- Economía: presenciales cobradas generan devolución total pendiente aunque falten menos de 24 horas; sin cobro no se crea devolución ni deuda nueva. Solo el administrador registra la entrega real. Los cobros antiguos pendientes se conservan y pueden resolverse con entidad inactiva.
- Cierre administrativo y último administrador: solo bloqueados por cobros/devoluciones pendientes. Tras el cierre se conserva consulta histórica. La reactivación de entidad por Gestor vuelve a habilitar gestión, sin restaurar hijos automáticamente.
- API de profesor y páginas públicas verifican disponibilidad efectiva; la base rechaza cambios de asistencia/inscripciones en módulos no disponibles. Se conserva historia de asistencia y movimientos.

Entorno listo para revisión: `http://127.0.0.1:8080/`, base **multipro_staging_filtros_cascada**, en PostgreSQL Docker local 127.0.0.1:5433. Es una restauración separada de `multipro_staging_local`; el original no fue migrado ni modificado por este bloque. La copia conserva los datos originales y agrega tres entidades ficticias `DEMO · La Plata`, `DEMO · Mar del Plata` y `DEMO · Córdoba`, con actividades y espacios gratuitos. Los datos históricos de ubicación no fueron completados artificialmente.

Correo local deshabilitado (`MAIL_ENABLED=false`), incluido el servidor iniciado; no se alteró el Cron productivo. Respaldo restaurado y comparado mediante huellas de diez tablas antes de migrar; nueva comparación confirmó la conservación de historia tras 008/009. Respaldo privado y configuración anterior: `C:\MultiPro-Migration\MultiPro-Pagos-MVP\staging-local\private\antes-filtros-cascada-20261030-175628`. Cambiar de base para volver a la configuración anterior no debe usarse para descartar obligaciones o historia que se creen en la copia durante pruebas.

Validación vigente: **81 consultas aisladas** del filtro; **117 comprobaciones PostgreSQL/HTTP de ubicación/cascada**, incluyendo procesos concurrentes; regresión completa previa de reservas gratuitas, presenciales, pagos simulados internos, API y correo aprobada; aplicación y rollback de migraciones vacías y rechazo de rollback con historia aprobados. Prueba visual real en Chrome de provincia/localidad/entidad, persistencia entre las tres pantallas y ancho móvil 390, con ancho de contenido 375 sin desborde. Evidencias en `documentacion/evidencias/`. No se modificó ni publicó APK; la prueba móvil corresponde a web responsive, no a teléfono físico.

Informe y recorrido de aceptación: `documentacion/VALIDACION_FILTROS_Y_CASCADA_LOCAL.md`. La revisión manual del usuario sigue pendiente. El plan de migración productiva se elaborará después de esa aceptación, conforme a su instrucción; no se ejecutaron commit, push, merge, despliegue ni cambios de infraestructura productiva.


## 15 Presentación vigente de filtros y precarga pendiente

Por pedido posterior del usuario, el catálogo público presenta un apartado **Filtros** seguido de tres campos desplegables: **Provincia**, **Localidad** y **Entidad**. Reemplaza las listas de casillas desplegadas de la sección anterior. Cada campo resume su selección, admite múltiples opciones, búsqueda interna, cierre con Listo/Escape y cierre al abrir otro campo. Aplicar y Limpiar conservan el comportamiento previo. En escritorio los tres campos quedan al lado del título; en móvil se apilan sin desborde horizontal.

Comprobado en aplicación local real: apertura y cierre, búsqueda por localidad, selección de dos provincias, combinación de los tres campos, un resultado esperado, persistencia al navegar entre Actividades/Espacios/Home y adaptación móvil. Las 81 consultas aisladas del filtro siguen aprobadas y se verifican tres desplegables inicialmente cerrados. Evidencia vigente: `documentacion/evidencias/filtros-desplegables-escritorio.png` y `filtros-desplegables-movil.png`.

Separadamente se investigó y descargó el catálogo oficial Georef desde Argentina.gob.ar: 24 jurisdicciones y 4.028 localidades. Archivos y manifiesto con hashes en `database/catalogos/georef/`. La primera implementación de importación aprobó 4.053 comprobaciones en una base temporal y la regresión integral de cascadas (117) siguió aprobada. Después se preparó una optimización por lotes de `CatalogoGeoref`, cuya nueva validación PostgreSQL no se autorizó. La migración 010, la precarga y el importador por lotes **no están aplicados ni validados en la copia activa de Staging**. El usuario indicó después «Espera» y priorizó esta reformulación visual. No reanudar automáticamente la importación mientras revisa la pantalla.

Se conserva compatibilidad con el esquema 009: los tres listados públicos fueron comprobados por HTTP con respuesta 200, sin error de consulta ni avisos PHP antes del ajuste visual; ahora además se revisaron visualmente en el navegador. La carga oficial sigue pendiente y la pantalla local utiliza las ubicaciones ya existentes. No se modificó Productivo ni se hizo commit/push en esta reformulación.


## Precarga Georef aplicada en Local/Staging (30/10/2026, corte vigente)

El usuario autorizó retomar con «adelante con la precarga». Este corte reemplaza el estado pendiente anterior. La importación optimizada por lotes fue validada en base temporal: `GEOREF_OFICIAL_OK provincias=24 localidades=4028 comprobaciones=4053`, incluyendo integridad/UTF-8, preservación de IDs y vínculos, reimportación sin duplicados, rechazo de archivos alterados y selección/formulario HTTP real del Gestor.

Se aplicó migración 010 y catálogo a `multipro_staging_filtros_cascada`, exclusivamente local. Comprobación SQL posterior: 24 jurisdicciones oficiales, 4.028 localidades oficiales, 3 localidades manuales conservadas, 3 entidades con sus vínculos conservados y cero localidades sin provincia. El importador no reasignó entidades ni inventó códigos para coincidencias ambiguas. Fuente y archivo de descarga: `database/catalogos/georef/README.md` y `manifest.json`.

Respaldo privado previo: `C:\MultiPro-Migration\MultiPro-Pagos-MVP\staging-local\private\antes-georef-20261030-185555`. Se restauró en una base temporal y se compararon huellas antes de importar. Huellas de entidades, reservas e inscripciones iguales después de la aplicación. La base temporal de comprobación se eliminó; base local original y Productivo conservados.

El formulario Gestor ofrece Provincia/CABA y localidad dependiente, con búsqueda por nombre/departamento y una excepción manual explícita si falta una ubicación. La aplicación usa la copia local, sin llamadas externas por formulario. Los filtros públicos continúan mostrando únicamente ubicaciones de entidades activas disponibles, no todas las localidades del país sin oferta. Los tres desplegables de Filtros aprobados por el usuario se conservan.

Comprobaciones posteriores: 81 consultas aisladas de filtros aprobadas; sintaxis de CatalogoGeoref, ABM Entidades y JS de ubicaciones correcta; Home, Actividades y Espacios respondieron HTTP 200 sin error de listado. Sin commit/push, cambios productivos, modificaciones de Cron ni publicación de APK. Correo local sigue deshabilitado. Próximo paso: aceptación manual del Gestor en la copia local y revisión de cascadas; preparar plan productivo después de esa aceptación.


## Validación funcional final en QA aislado (30/10/2026)

El recorrido delegado por el usuario se completó en `multipro_qa_pantallas_20261030190259`, servidor 8087 con sesiones separadas, solo catálogo oficial y datos ficticios; sin correos ni dinero real. La copia habitual 8080 continúa en `multipro_staging_filtros_cascada`, sin cambios de datos causados por QA.

Interfaz verificada: ubicación oficial y filtros, suspensión/reapertura ordenada, recuperación conjunta conservando cupo/espera, bloqueo de baja de espacio con módulo, reubicación, cancelación de dos reservas, devolución pendiente y bloqueo de cierre/último responsable. La devolución ficticia se registró con formulario HTTP autenticado/CSRF por imposibilidad de automatizar el confirm() nativo; estado revisado en pantalla. No afirmar que se automatizó ese aviso. El cobro existente se verificó antes de continuar y no se repitió. Cierre final exitoso desde UI con cero pendientes; historial accesible después, escritura rechazada HTTP 403.

Lectura final QA: reserva 1 cancelada con un cobro, una solicitud y una devolución; reserva 2 cancelada sin movimientos; entidad inactiva con cierre registrado y cero pendientes; módulo inactivo vinculado al destino; inscripciones suspendidas conservando cupo/espera; cero correos enviados. Se conservó la base de QA para revisar evidencia.

Corregidos dos mensajes de interfaz/servicio: bloqueo explícito de último administrador por pendientes, manteniendo protección transaccional/DB; y Reactivar / cerrar solo para entidad. Regresión completa posterior aprobada (117 comprobaciones cascada/ubicación, 4.053 Georef, reservas gratuitas/presenciales/API/concurrencia y correo). Evidencias 01 a 11 en `documentacion/evidencias/qa-pantallas/`; informe canónico actualizado `documentacion/VALIDACION_FILTROS_Y_CASCADA_LOCAL.md`. Log/fixtures privados en `staging-local/private/qa-pantallas-20261030190259` fuera de Git. Próximo bloque: preparar plan de migración productiva para revisión; no se autorizaron ni ejecutaron commit/push/merge, despliegue, Railway, Cron ni APK. Mercado Pago sigue bloqueado.
