# MultiPro Android

Aplicación Android nativa de MultiPro.

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
