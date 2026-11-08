# Pruebas de MultiPro

Ejecutar desde la raíz salvo indicación contraria. Estos comandos existen en el repositorio; varios requieren Docker, PostgreSQL temporal, PHP configurado o un emulador. Nunca apuntarlos a Producción.

## Secuencia mínima

1. Revisar `git diff --check` y el diff acotado.
2. Ejecutar la prueba focalizada del módulo.
3. Ampliar a regresión sólo según impacto: contrato compartido, esquema, permisos, reservas, pagos o autenticación.
4. Registrar ambiente, comando, esperado, observado y límites.

## Comandos focalizados

### PHP

```powershell
php -l ruta\archivo.php
```

Aplicarlo a cada PHP modificado. No demuestra funcionamiento HTTP ni acceso a datos.

### Android

Desde `android/`, comandos documentados en [`android/README.md`](../android/README.md):

```powershell
.\gradlew.bat --offline -PmultiproTestBuildType=local :app:testLocalUnitTest :app:assembleLocal
.\gradlew.bat --offline -PmultiproTestBuildType=local '-Pandroid.testInstrumentationRunnerArguments.class=com.multipro.mobile.PresentialScreensTest' :app:connectedLocalAndroidTest
.\gradlew.bat --offline -PmultiproTestBuildType=local '-Pandroid.testInstrumentationRunnerArguments.class=com.multipro.mobile.LocalApiSmokeTest' '-Pandroid.testInstrumentationRunnerArguments.localApiSmoke=true' :app:connectedLocalAndroidTest
```

Las pruebas instrumentadas requieren emulador/dispositivo y backend local controlado.

### Filtros sin PostgreSQL

```powershell
php -d extension=pdo_sqlite tests/filtros/validar.php
```

Sirve para lógica focalizada; SQLite no valida compatibilidad PostgreSQL.

### Suites PostgreSQL temporales

```powershell
& .\tests\pagos\validar_local.ps1
& .\tests\correo\validar_local.ps1
& .\tests\filtros\validar_georef_local.ps1
& .\tests\lista_espera\validar_local.ps1
& .\database\migrations\tests\validar_002_local.ps1
```

Leer el script antes de ejecutarlo y confirmar contenedor, base origen y base temporal. No reutilizar estos scripts en Railway. La suite de pagos integra regresiones de servicios, API y módulos relacionados; usarla cuando cambien esquema, reservas, pagos, permisos o contratos compartidos.

### Paquete Railway

```powershell
& .\deploy\railway\verify-package.ps1
```

Sólo valida el paquete local. No consulta ni despliega Railway.

## Matriz por cambio

- Web/UI: lint PHP, flujo afectado, roles permitidos/prohibidos y vista móvil/escritorio.
- API: autenticación, propietario/entidad, entrada inválida, códigos, repetición y campos sensibles ausentes.
- Esquema: migración y rollback en copia temporal, conservación de historia, restricciones, permisos y segunda ejecución cuando deba ser idempotente.
- Reservas/pagos: gratuitas y presenciales, precio visto/vigente, concurrencia, cancelación, cobro y devolución. Mantener online bloqueado salvo alcance aprobado.
- Correo/Cron: envío local deshabilitado por defecto; probar estados de cola, reintento, duplicados y omisión de reservas canceladas. Verificar Cron remoto por separado.
- Android: unitarias/ensamblado; instrumentación sólo si cambia navegación, red, formularios o presentación.

## Reglas de datos y permisos

- Usar datos ficticios o anonimizados y bases temporales identificables.
- No imprimir destinatarios, tokens, contraseñas, cadenas de conexión ni payloads de proveedor.
- Probar acceso autorizado y denegado; una respuesta exitosa como administrador no demuestra aislamiento.
- No borrar historia de pagos, cobros, devoluciones, promociones o auditoría para facilitar una prueba.
- Una simulación local no acredita integración real con correo, Railway o Mercado Pago.
