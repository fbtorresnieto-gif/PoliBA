<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../_google.php';

apiAceptarMetodo('POST');
$datos = apiLeerJson();
$google = apiVerificarGoogleIdToken(trim((string) ($datos['id_token'] ?? '')));
$email = strtolower(trim((string) $google['email']));

/** @return array<string, mixed>|false */
function buscarUsuarioGoogle(PDO $pdo, string $email): array|false
{
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombre, u.apellido, u.email, u.fk_entidad,
               r.nombre AS rol_nombre, e.nombre AS entidad_nombre
        FROM usuarios u
        JOIN roles r ON u.fk_rol = r.id
        LEFT JOIN entidades e ON u.fk_entidad = e.id
        WHERE LOWER(u.email) = LOWER(?)
        LIMIT 1
    ");
    $stmt->execute([$email]);
    return $stmt->fetch();
}

/** @param array<string, mixed> $usuario */
function responderSesionGoogle(PDO $pdo, array $usuario, int $status = 200): never
{
    if (!in_array(strtolower((string) $usuario['rol_nombre']), ['alumno', 'profesor'], true)) {
        apiResponder(['error' => 'Esta cuenta no tiene un rol habilitado para la aplicación móvil.'], 403);
    }
    $sesion = apiCrearSesion($pdo, (int) $usuario['id']);
    apiResponder(['data' => [
        'token' => $sesion['token'],
        'tipo_token' => 'Bearer',
        'vence_en' => $sesion['vence_en'],
        'usuario' => [
            'id' => (int) $usuario['id'],
            'nombre' => $usuario['nombre'],
            'apellido' => $usuario['apellido'],
            'email' => $usuario['email'],
            'rol' => $usuario['rol_nombre'],
            'entidad_id' => $usuario['fk_entidad'] === null ? null : (int) $usuario['fk_entidad'],
            'entidad_nombre' => $usuario['entidad_nombre'],
        ],
    ]], $status);
}

try {
    $pdo = apiBaseDatos();
    $usuario = buscarUsuarioGoogle($pdo, $email);
    if ($usuario) responderSesionGoogle($pdo, $usuario);

    $esRegistro = array_key_exists('dni', $datos) || array_key_exists('fecha_nacimiento', $datos);
    if (!$esRegistro) {
        apiResponder([
            'error' => 'Completá tus datos para crear la cuenta de MultiPro.',
            'codigo' => 'registro_requerido',
            'data' => [
                'nombre' => trim((string) ($google['given_name'] ?? '')),
                'apellido' => trim((string) ($google['family_name'] ?? '')),
                'email' => $email,
            ],
        ], 409);
    }

    $nombre = trim((string) ($datos['nombre'] ?? $google['given_name'] ?? ''));
    $apellido = trim((string) ($datos['apellido'] ?? $google['family_name'] ?? ''));
    $dni = trim((string) ($datos['dni'] ?? ''));
    $direccion = trim((string) ($datos['direccion'] ?? ''));
    $telefono = trim((string) ($datos['telefono'] ?? ''));
    $fechaNacimiento = trim((string) ($datos['fecha_nacimiento'] ?? ''));
    $entidadId = isset($datos['entidad_id']) && $datos['entidad_id'] !== ''
        ? (int) $datos['entidad_id'] : null;
    if ($nombre === '' || $apellido === '' || $dni === '' || $fechaNacimiento === '') {
        apiResponder(['error' => 'Nombre, apellido, DNI y fecha de nacimiento son obligatorios.'], 422);
    }
    $nacimiento = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaNacimiento);
    $erroresFecha = DateTimeImmutable::getLastErrors();
    if ($nacimiento === false
            || ($erroresFecha !== false && ($erroresFecha['warning_count'] > 0 || $erroresFecha['error_count'] > 0))
            || $nacimiento->format('Y-m-d') !== $fechaNacimiento) {
        apiResponder(['error' => 'La fecha de nacimiento debe tener formato YYYY-MM-DD.'], 422);
    }
    $hoy = new DateTimeImmutable('today');
    if ($nacimiento > $hoy || $nacimiento->diff($hoy)->y < 18) {
        apiResponder(['error' => 'Debés tener al menos 18 años para registrarte.'], 422);
    }
    if ($entidadId !== null && $entidadId < 1) {
        apiResponder(['error' => 'entidad_id debe ser un entero positivo o null.'], 422);
    }

    $rolId = $pdo->query("SELECT id FROM roles WHERE nombre = 'Alumno' LIMIT 1")->fetchColumn();
    if (!$rolId) apiResponder(['error' => 'No fue posible registrar el usuario.'], 500);
    $stmt = $pdo->prepare("
        INSERT INTO usuarios
            (nombre, apellido, dni, direccion, email, contrasena, telefono, fecha_nacimiento, fk_entidad, fk_rol)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        RETURNING id
    ");
    $stmt->execute([
        $nombre, $apellido, $dni, $direccion === '' ? null : $direccion, $email,
        password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
        $telefono === '' ? null : $telefono, $fechaNacimiento, $entidadId, (int) $rolId,
    ]);
    $usuario = buscarUsuarioGoogle($pdo, $email);
    if (!$usuario) apiResponder(['error' => 'No fue posible iniciar la sesión creada.'], 500);
    responderSesionGoogle($pdo, $usuario, 201);
} catch (PDOException $error) {
    if ($error->getCode() === '23505') {
        apiResponder(['error' => 'El DNI o el correo electrónico ya se encuentran registrados.'], 409);
    }
    apiErrorInterno($error, 'Error en la autenticación Google de la API');
} catch (Throwable $error) {
    apiErrorInterno($error, 'Error en la autenticación Google de la API');
}

