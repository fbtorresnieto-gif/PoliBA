<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
apiAceptarMetodo('POST');
$datos = apiLeerJson();

$nombre = trim((string) ($datos['nombre'] ?? ''));
$apellido = trim((string) ($datos['apellido'] ?? ''));
$dni = trim((string) ($datos['dni'] ?? ''));
$direccion = trim((string) ($datos['direccion'] ?? ''));
$email = trim((string) ($datos['email'] ?? ''));
$contrasena = (string) ($datos['contrasena'] ?? '');
$confirmacion = (string) ($datos['confirmar_contrasena'] ?? '');
$telefono = trim((string) ($datos['telefono'] ?? ''));
$fechaNacimiento = trim((string) ($datos['fecha_nacimiento'] ?? ''));
$entidadId = isset($datos['entidad_id']) && $datos['entidad_id'] !== '' ? (int) $datos['entidad_id'] : null;

if ($nombre === '' || $apellido === '' || $dni === '' || $email === '' || $contrasena === '' || $fechaNacimiento === '') {
    apiResponder(['error' => 'Nombre, apellido, DNI, email, contraseña y fecha de nacimiento son obligatorios.'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) apiResponder(['error' => 'El email no es válido.'], 422);
if ($contrasena !== $confirmacion) apiResponder(['error' => 'Las contraseñas no coinciden.'], 422);
if (strlen($contrasena) < 6) apiResponder(['error' => 'La contraseña debe tener al menos 6 caracteres.'], 422);
$nacimiento = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaNacimiento);
$erroresFecha = DateTimeImmutable::getLastErrors();
if ($nacimiento === false || ($erroresFecha !== false && ($erroresFecha['warning_count'] > 0 || $erroresFecha['error_count'] > 0)) || $nacimiento->format('Y-m-d') !== $fechaNacimiento) {
    apiResponder(['error' => 'La fecha de nacimiento debe tener formato YYYY-MM-DD.'], 422);
}
$hoy = new DateTimeImmutable('today');
if ($nacimiento > $hoy || $nacimiento->diff($hoy)->y < 18) {
    apiResponder(['error' => 'Debés tener al menos 18 años para registrarte.'], 422);
}
if ($entidadId !== null && $entidadId < 1) apiResponder(['error' => 'entidad_id debe ser un entero positivo o null.'], 422);

try {
    $pdo = apiBaseDatos();
    $stmt = $pdo->query("SELECT id FROM roles WHERE nombre = 'Alumno' LIMIT 1");
    $rolId = $stmt->fetchColumn();
    if (!$rolId) apiResponder(['error' => 'No fue posible registrar el usuario.'], 500);
    $stmt = $pdo->prepare("
        INSERT INTO usuarios
            (nombre, apellido, dni, direccion, email, contrasena, telefono, fecha_nacimiento, fk_entidad, fk_rol)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        RETURNING id
    ");
    $stmt->execute([
        $nombre, $apellido, $dni, $direccion === '' ? null : $direccion,
        $email, password_hash($contrasena, PASSWORD_BCRYPT),
        $telefono === '' ? null : $telefono, $fechaNacimiento, $entidadId, (int) $rolId,
    ]);
    apiResponder(['data' => [
        'id' => (int) $stmt->fetchColumn(),
        'mensaje' => 'Registro completado. Ya podés iniciar sesión.',
    ]], 201);
} catch (PDOException $error) {
    if ($error->getCode() === '23505') {
        apiResponder(['error' => 'El DNI o el correo electrónico ya se encuentran registrados.'], 409);
    }
    apiErrorInterno($error, 'Error al registrar un Alumno en la API');
} catch (Throwable $error) {
    apiErrorInterno($error, 'Error al registrar un Alumno en la API');
}
