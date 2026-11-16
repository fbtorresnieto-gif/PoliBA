<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
apiAceptarMetodo('POST');

try {
    [$pdo, $usuario] = apiContextoAutenticado();
    $datos = apiLeerJson();
    $nombre = trim((string) ($datos['nombre'] ?? ''));
    $apellido = trim((string) ($datos['apellido'] ?? ''));
    $direccion = trim((string) ($datos['direccion'] ?? ''));
    $telefono = trim((string) ($datos['telefono'] ?? ''));

    if ($nombre === '' || $apellido === '') {
        apiResponder(['error' => 'Nombre y apellido son obligatorios.'], 422);
    }

    $stmt = $pdo->prepare('UPDATE usuarios SET nombre = ?, apellido = ?, direccion = ?, telefono = ? WHERE id = ?');
    $stmt->execute([$nombre, $apellido, $direccion, $telefono, $usuario['id']]);

    $usuario['nombre'] = $nombre;
    $usuario['apellido'] = $apellido;
    $usuario['direccion'] = $direccion;
    $usuario['telefono'] = $telefono;
    apiResponder(['data' => [
        'id' => $usuario['id'], 'nombre' => $usuario['nombre'],
        'apellido' => $usuario['apellido'], 'dni' => $usuario['dni'],
        'direccion' => $usuario['direccion'], 'email' => $usuario['email'],
        'telefono' => $usuario['telefono'], 'fecha_nacimiento' => $usuario['fecha_nacimiento'],
        'rol' => $usuario['rol_nombre'], 'entidad_id' => $usuario['fk_entidad'],
        'entidad_nombre' => $usuario['entidad_nombre'],
    ]]);
} catch (Throwable $error) {
    apiErrorInterno($error, 'Error al actualizar el perfil en la API');
}
