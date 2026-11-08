# Arquitectura de MultiPro

Estado documental: 2026-11-08. Describe el checkout local; no certifica el estado de Producción.

## Arquitectura verificada

```text
Web PHP renderizada + API REST PHP ── PDO ── PostgreSQL
                │
                ├── PHPMailer ── proveedor de correo configurado externamente
                └── CLI de notificaciones ── ejecución programada fuera del repositorio

Android Java ── Retrofit/HTTP ── API v1
```

- Backend PHP `>=8.2`, procedimental y sin framework, con PDO PostgreSQL.
- Web renderizada en servidor bajo `web/`; API JSON bajo `web/api/v1/`.
- Android Java: application ID `com.multipro.mobile`, minSdk 26, target/compileSdk 36; versión declarada 1.3.1.
- PostgreSQL es el motor de las migraciones y pruebas integradas. El archivo SQLite histórico no demuestra paridad funcional.
- Composer fija PHPMailer; Android usa AppCompat, Material, Retrofit, Gson, OkHttp, Glide y Google Identity.
- Railway aloja componentes según la documentación operativa, pero rama, commit, migraciones, variables, Cron y salud deben verificarse externamente antes de tratarlos como vigentes.

Fuentes: [`web/composer.json`](../web/composer.json), [`web/composer.lock`](../web/composer.lock), [`android/app/build.gradle`](../android/app/build.gradle), [`deploy/railway/README.md`](../deploy/railway/README.md).

## Módulos

- Identidad: sesión web, Bearer API, registro, Google, perfil, menores y roles.
- Catálogo: entidades, provincias/localidades, actividades, espacios, módulos, categorías, días y novedades.
- Operación: disponibilidad, reservas, inscripción, lista de espera, promociones y asistencia.
- Pagos: gratuito, presencial, configuración por espacio y base interna para proveedor online; el pago online real continúa bloqueado.
- Comunicaciones: recuperación de contraseña, cola general, recordatorios y procesador CLI.
- Administración: filtros, desactivación lógica, cascadas, devoluciones presenciales y cierre administrativo.

## Mapa de carpetas

- `web/`: páginas, includes, servicios, API y CLI.
- `android/`: aplicación, pruebas unitarias e instrumentadas.
- `database/schema.sql`: esquema de instalación; no reemplaza la secuencia de migraciones de un ambiente existente.
- `database/migrations/`: migraciones versionadas; `local/`, `rollback/` y `tests/` tienen destinos distintos.
- `database/deployment/`: preflight, permisos, verificación y postflight productivos.
- `tests/`: pruebas focalizadas de pagos, correo, filtros, cascadas y lista de espera.
- `deploy/railway/`: procedimiento y manifiesto del paquete; no es evidencia de despliegue actual.
- `documentacion/`: especificaciones, planes y evidencias históricas.
- `prototipo/` y `outputs/`: artefactos; no explorarlos por defecto.

## Puntos de entrada

- Web: [`web/index.php`](../web/index.php).
- API: [`web/api/v1/_bootstrap.php`](../web/api/v1/_bootstrap.php) y rutas dentro de `web/api/v1/`.
- Cron/CLI: [`web/cli/procesar_notificaciones.php`](../web/cli/procesar_notificaciones.php).
- Importación Georef: [`web/cli/importar_georef.php`](../web/cli/importar_georef.php).
- Android: `MainActivity`, `ApiClient` y `ApiService` bajo `android/app/src/main/java/com/multipro/mobile/`.
- Persistencia y ambiente: [`web/includes/db.php`](../web/includes/db.php); documentar nombres, nunca valores de variables.

## Pendientes o límites no comprobados

- Commit, esquema, permisos, variables y frecuencia real actualmente desplegados.
- Entrega efectiva del proveedor de correo; una aceptación o cola procesada no prueba recepción.
- Integración real de Mercado Pago, OAuth por entidad, checkout, webhook y reembolso online.
- Paridad total entre web, API y Android.
- Reproducibilidad de una instalación limpia completa fuera de las suites focalizadas.
