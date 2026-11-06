# Validación local de filtros por ubicación y entidad

Desde la raíz del repositorio, con PHP y la extensión PDO SQLite disponibles:

```powershell
php -d extension=pdo_sqlite tests/filtros/validar.php
```

Si el PHP de Windows no tiene configurado `extension_dir`, indicar la carpeta `ext` de su instalación únicamente en ese proceso:

```powershell
$phpBinary = (Get-Command php).Source
$phpExt = Join-Path (Split-Path -Parent $phpBinary) 'ext'
& $phpBinary -d "extension_dir=$phpExt" -d extension=pdo_sqlite tests/filtros/validar.php
```

El comprobador utiliza SQLite en memoria y datos ficticios. No lee `.env`, no conecta a PostgreSQL, no usa cuentas reales ni envía correos. Ejecuta las consultas de los tres listados y de novedades API extraídas de sus archivos, junto con la condición del helper real. Verifica selección, aislamiento, entidades inactivas, URL, validación de entradas y el formulario real.

Salida esperada: `FILTROS_OK consultas_aisladas=81 API_y_formulario_OK`.

Esta prueba no reemplaza la integración y regresión con PostgreSQL de staging ni la revisión completa de pantallas autenticadas y navegación móvil. No habilita ni prueba desactivaciones en cascada.


La integración PostgreSQL y los flujos de cascada se ejecutan con `tests/pagos/validar_local.ps1`, que crea y elimina únicamente su base temporal. Ver `documentacion/VALIDACION_FILTROS_Y_CASCADA_LOCAL.md`.
