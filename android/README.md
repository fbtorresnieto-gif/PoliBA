# MultiPro Android

Aplicación Android nativa de MultiPro.

## Prueba local del MVP presencial (2026-10-27)

Se agregó la variante **local**, instalada como **MultiPro Local** (`com.multipro.mobile.local`), con almacenamiento y sesión independientes. Las variantes debug/release conservan la URL de producción y su identificador anterior; no usarlas para probar este MVP contra Railway. No se desplegó la API nueva allí.

La variante local muestra precio ARS por hora, confirmación gratuita/presencial con importe final, pago pendiente, cobro registrado, cancelación propia y devolución presencial pendiente/realizada. Online no se puede seleccionar. Ante información de precio incompleta o inválida bloquea la reserva en vez de suponer gratuidad. Conserva el importe de la API como texto decimal, sin convertir a float/double. La aceptación de precio y modalidad usa la misma respuesta de disponibilidad que mostró la confirmación; el servidor valida nuevamente precio y ocupación.

La URL local está fijada en `http://127.0.0.1:8080/api/v1/`. Requiere un puente USB/emulador con el servidor PHP local ya operativo:

```powershell
# En esta carpeta android, con SDK instalado:
$env:ANDROID_HOME = Join-Path $env:LOCALAPPDATA 'Android/Sdk'
.\gradlew.bat --offline -PmultiproTestBuildType=local :app:testLocalUnitTest :app:assembleLocal
$adb = Join-Path $env:ANDROID_HOME 'platform-tools/adb.exe'
& $adb devices -l
# Reemplazar DEVICE_SERIAL por el identificador de tu dispositivo/emulador:
& $adb -s DEVICE_SERIAL reverse tcp:8080 tcp:8080
& $adb -s DEVICE_SERIAL install -r app/build/outputs/apk/local/app-local.apk
```

APK: `app/build/outputs/apk/local/app-local.apk`. No requiere exponer PHP en la red Wi-Fi. El permiso HTTP se limita a 127.0.0.1 en recursos exclusivos de local, con el resto de destinos HTTP bloqueados. El cliente API local rechaza otros hosts/puertos y no sigue redirecciones. No altera la política HTTPS del manifest principal. Google Sign In está oculto/deshabilitado exclusivamente en local; para la prueba utilizar una cuenta **Alumno del staging**, con contraseña conocida por el usuario. No se copiaron contraseñas ni tokens a Android. Registrar cobros/devoluciones corresponde al administrador en la web local, no al alumno en el móvil.

Entrega estable: `C:\MultiPro-Migration\MultiPro-Pagos-MVP\staging-local\android-apk\MultiPro-Local-1.3.0-presencial.apk`. SHA256: `12CCBED3091B0F64B5A793198126D8AD1E72A98FC2D4FC7734254BBF9CCB2276`. Compilación local/debug y 8 pruebas unitarias aprobadas. **La prueba de uso móvil queda pendiente por elección del usuario**: el emulador API 36 falló por System UI sin foco y luego no terminó el arranque. Las pruebas instrumentadas de abajo están preparadas, pero no aprobadas; el smoke test Android→PHP tampoco se ejecutó. No se instaló en un teléfono físico. El emulador y el daemon de compilación se cerraron.

Pruebas de pantalla aisladas en emulador con servidor simulado dentro del dispositivo (no afectan staging):

```powershell
.\gradlew.bat --offline -PmultiproTestBuildType=local '-Pandroid.testInstrumentationRunnerArguments.class=com.multipro.mobile.PresentialScreensTest' :app:connectedLocalAndroidTest
```

No mantener `adb reverse tcp:8080` durante esas pruebas simuladas: el servidor de fixtures necesita ese puerto en el dispositivo. Después de cerrarlo, una prueba opcional de lectura contra el PHP real local:

Si falta el componente oficial UTP en la caché, la primera ejecución de pruebas necesita omitir `--offline` para descargarlo desde los repositorios oficiales configurados.

```powershell
& $adb -s DEVICE_SERIAL reverse tcp:8080 tcp:8080
.\gradlew.bat --offline -PmultiproTestBuildType=local '-Pandroid.testInstrumentationRunnerArguments.class=com.multipro.mobile.LocalApiSmokeTest' '-Pandroid.testInstrumentationRunnerArguments.localApiSmoke=true' :app:connectedLocalAndroidTest
```

El test de PHP consulta entidades sin autenticar ni crear reservas. Las pruebas de pantalla usan sesiones sintéticas, se limitan a la variante local y limpian su sesión al finalizar. No son una prueba de login/cobro real ni de un teléfono físico. Gradle genera pruebas solo para el tipo seleccionado: `multiproTestBuildType=local` opta por local sin cambiar el valor debug habitual.

Referencias oficiales para la configuración utilizada: [redirección de puertos ADB](https://developer.android.com/develop/ui/views/layout/webapps/access-local-server), [configuración de seguridad de red](https://developer.android.com/privacy-and-security/security-config) y [tipo de compilación de pruebas](https://developer.android.com/build/gradle-tips).

## Configuración

- Lenguaje: Java.
- Interfaces: XML.
- Package: `com.multipro.mobile`.
- Android mínimo: API 26 (Android 8.0).
- URL base: `https://multipro-production.up.railway.app/api/v1/`.
- Autenticación: `Authorization: Bearer {token}`.

## Estado de la versión 1.3.0

- Estructura del proyecto creada.
- Identidad visual base incorporada.
- Logo oficial aplicado en la pantalla inicial, acceso, inicio e ícono de la aplicación.
- Cliente Retrofit/OkHttp configurado.
- Los 18 endpoints vigentes están declarados en `ApiService`.
- El botón Comenzar abre el inicio de sesión.
- El login consume `POST /auth/login.php`.
- El acceso con Google usa Credential Manager, envía el ID token a `POST /auth/google.php`
  y recibe la misma sesión Bearer que el login tradicional.
- Si el correo verificado por Google todavía no existe, la app solicita DNI, fecha de
  nacimiento y los demás datos obligatorios antes de crear una cuenta de Alumno.
- El token y los datos mínimos de sesión se cifran con Android Keystore.
- La sesión se verifica mediante `GET /auth/yo.php`.
- La pantalla inicial se adapta al rol Alumno o Profesor.
- El cierre de sesión consume `POST /auth/logout.php`.
- El Alumno puede listar entidades, consultar sus espacios y ver el detalle de cada espacio.
- El Alumno puede elegir una fecha y consultar la disponibilidad horaria de un espacio.
- El Alumno puede confirmar una reserva sobre un horario disponible.
- El Alumno puede listar, consultar y cancelar sus propias reservas activas.
- El Profesor puede consultar sus módulos activos y los alumnos inscriptos por fecha.
- El Profesor puede registrar asistencia como presente, ausente o lluvia.
- Ambos roles pueden consultar su perfil personal y las novedades vigentes.
- Identidad visual modernizada a partir del sistema de diseño de la web, con imágenes y tarjetas elevadas.
- El alta pública de un Alumno se encuentra disponible desde la pantalla de acceso.
- Después del login, Novedades funciona como inicio y las demás opciones se organizan en un menú lateral por rol.
- No se almacenan credenciales en el código ni en el repositorio.

## Configuración de Google

- Web Client ID utilizado por Android y por la validación del servidor:
  `915863016319-g522r49r3bfhpe3q71v4fbte24argtre.apps.googleusercontent.com`.
- Package Android: `com.multipro.mobile`.
- SHA-1 de la firma debug de esta PC:
  `72:A9:B5:78:0F:64:A0:94:B5:48:5E:81:14:7F:95:D1:91:6B:E8:6E`.
- En Google Cloud debe existir un cliente OAuth de tipo Android para ese package y esa
  huella, dentro del mismo proyecto OAuth que utiliza la web.
- Para una firma release se debe crear otro cliente Android con la huella de la clave
  release. La huella debug no sirve para una APK/AAB firmada para publicación.
- El Client ID es un identificador público. El Client Secret continúa únicamente en
  Railway y nunca se incorpora a la aplicación.

## Apertura en Android Studio

Abrir esta carpeta `android` como proyecto. Android Studio deberá descargar el SDK y las dependencias declaradas durante la primera sincronización.

No incorporar `local.properties`, tokens, contraseñas ni claves de firma a Git.

## Generar la APK de prueba

Desde esta carpeta:

```powershell
.\gradlew.bat clean assembleDebug
```

La APK se genera en:

```text
app/build/outputs/apk/debug/app-debug.apk
```

La APK `debug` es para pruebas internas. La publicación final requerirá una APK o AAB `release` firmada.
