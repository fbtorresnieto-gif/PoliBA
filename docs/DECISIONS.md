# Decisiones de MultiPro

Registrar aquí sólo decisiones confirmadas y estables. Los documentos históricos describen el momento indicado, no garantizan el estado externo actual.

## Confirmadas

1. **Nombre del producto:** usar `MultiPro` en contenido nuevo. Los identificadores históricos se cambian sólo dentro de un alcance aprobado. Referencias: [`PROMPT_CONTINUIDAD_CHAT_NUEVO_2026-10-30.md`](../PROMPT_CONTINUIDAD_CHAT_NUEVO_2026-10-30.md), historial Git.
2. **Checkout de trabajo:** este repositorio contiene la evolución vigente; `C:\MultiPro\MultiPro` queda como checkout histórico. Evidencia: el commit histórico `c15a65b` es antecesor de esta línea y los módulos posteriores viven aquí. Verificar siempre rama y estado antes de actuar.
3. **Persistencia operativa:** PostgreSQL es el motor objetivo. Las migraciones se aplican en orden, con preflight, permisos, postflight, respaldo y recuperación. Los archivos `local/` y `tests/` no se usan en Producción. Referencia: [`deploy/railway/README.md`](../deploy/railway/README.md).
4. **Evolución incremental:** preservar el monolito y contratos existentes; no se aprobó una reescritura ni un cambio de stack.
5. **Pagos:** gratuito y presencial forman parte del alcance implementado. Pago online real permanece bloqueado hasta resolver proveedor, credenciales privadas, OAuth, checkout, validación de eventos y reembolsos. Referencia: [`web/includes/pagos/README.md`](../web/includes/pagos/README.md).
6. **Desactivación:** entidad o espacio cancela reservas futuras y genera devoluciones correspondientes; inscripciones y espera se suspenden y pueden reactivarse; un espacio con módulos activos no puede darse de baja hasta reubicarlos; un módulo desactivado por su actividad sólo puede reactivarse después de ella. Referencia: [`documentacion/ESPECIFICACION_FILTROS_Y_DESACTIVACION_MULTIPRO.md`](../documentacion/ESPECIFICACION_FILTROS_Y_DESACTIVACION_MULTIPRO.md).
7. **Cierre administrativo:** se separa de la operación pública y sólo abarca cobros/devoluciones; no se cierra mientras existan pendientes. Misma referencia del punto anterior.
8. **Lista de espera:** `fecha_le` representa el último ingreso; se conserva al salir, se renueva al reingresar y ordena por fecha e ID. Referencias: [`database/migrations/20261108_012_fecha_lista_espera.sql`](../database/migrations/20261108_012_fecha_lista_espera.sql), [`tests/lista_espera/validar_local.ps1`](../tests/lista_espera/validar_local.ps1).
9. **Cambios remotos:** Producción, Railway, Cron, migraciones reales y operaciones destructivas requieren verificación y autorización independientes. Una aprobación local no los autoriza.
10. **Visibilidad de entidades:** Entidades, las novedades de Home y Espacios muestran entidades públicas y privadas; Actividades muestra únicamente las pertenecientes a entidades públicas. El registro público no permite autoasociarse a entidades privadas. El modelo vigente admite una sola entidad por usuario. El Administrador privado dispone únicamente de Espacios, Novedades, Reservas, Estadísticas y Mi perfil; sus estadísticas se limitan a reservas. No dispone de los ABM de Actividades, Módulos, Profesores, Subcategorías ni Alumnos. Referencias: [`web/includes/VisibilidadEntidades.php`](../web/includes/VisibilidadEntidades.php), [`database/migrations/20261109_013_tipo_visibilidad_entidades.sql`](../database/migrations/20261109_013_tipo_visibilidad_entidades.sql).

## Históricas; verificar antes de reutilizar

- El Cron de notificaciones fue configurado históricamente cada cinco minutos para ejecutar el procesador CLI. No asumir que continúa así sin revisar Railway. Referencias: [`MEMORIA_CONTEXTO_PAGOS_MVP.md`](../MEMORIA_CONTEXTO_PAGOS_MVP.md), [`documentacion/Notificaciones_y_Cron_MultiPro_v1.1.docx`](../documentacion/Notificaciones_y_Cron_MultiPro_v1.1.docx).
- Railway siguió `main` y ciertos pushes podían disparar despliegue automático. Confirmar configuración actual antes de integrar. Referencia: [`documentacion/PLAN_SUBIDA_PRD_2026-11-06.md`](../documentacion/PLAN_SUBIDA_PRD_2026-11-06.md).
- Android declara versión 1.3.1 (`versionCode 14`); la versión instalada o publicada requiere comprobación separada. Referencia: [`android/app/build.gradle`](../android/app/build.gradle).

## Propuestas o pendientes, no decisiones

- Integración real de Mercado Pago y política técnica de credenciales/webhooks.
- Separación definitiva entre rol migrador y rol de aplicación en todos los ambientes.
- Refactor mayor, framework nuevo o extracción del monolito.
- Cambios de frecuencia, proveedor o arquitectura de correo/Cron.

Para aceptar una propuesta, registrar fecha, alcance, motivo, alternativa descartada, consecuencias y fuente de aprobación.
