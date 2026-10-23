<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
apiAceptarMetodo('POST');
$datos = apiLeerJson();
$email = trim((string) ($datos['email'] ?? ''));
$contrasena = trim((string) ($datos['contrasena'] ?? ''));
if ($email === '' || $contrasena === '') {
    apiResponder(['error' => 'Email y contraseña son obligatorios.'], 422);
}

try {
    $pdo = apiBaseDatos();
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombre, u.apellido, u.email, u.contrasena,
               u.fk_entidad, r.nombre AS rol_nombre, e.nombre AS entidad_nombre
        FROM usuarios u
        JOIN roles r ON u.fk_rol = r.id
        LEFT JOIN entidades e ON u.fk_entidad = e.id
        WHERE u.email = ? AND u.estado = TRUE
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();
    $rol = strtolower((string) ($usuario['rol_nombre'] ?? ''));
    if (!$usuario || !password_verify($contrasena, $usuario['contrasena']) || !in_array($rol, ['alumno', 'profesor'], true)) {
        apiResponder(['error' => 'Correo electrónico o contraseña incorrectos.'], 401);
    }

    $sesion = apiCrearSesion($pdo, (int) $usuario['id']);
    apiResponder(['data' => [
        'token' => $sesion['token'],
        'tipo_token' => 'Bearer',
        'vence_en' => $sesion['vence_en'],
        'usuario' => [
            'id' => (int) $usuario['id'], 'nombre' => $usuario['nombre'],
            'apellido' => $usuario['apellido'], 'email' => $usuario['email'],
            'rol' => $usuario['rol_nombre'],
            'entidad_id' => $usuario['fk_entidad'] === null ? null : (int) $usuario['fk_entidad'],
            'entidad_nombre' => $usuario['entidad_nombre'],
        ],
    ]]);
} catch (Throwable $error) {
    apiErrorInterno($error, 'Error en el login de la API');
}
