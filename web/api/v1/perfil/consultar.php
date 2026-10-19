<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
apiAceptarMetodo('GET');
try {
    [, $usuario] = apiContextoAutenticado();
    apiResponder(['data' => [
        'id' => $usuario['id'], 'nombre' => $usuario['nombre'],
        'apellido' => $usuario['apellido'], 'dni' => $usuario['dni'],
        'direccion' => $usuario['direccion'], 'email' => $usuario['email'],
        'telefono' => $usuario['telefono'], 'fecha_nacimiento' => $usuario['fecha_nacimiento'],
        'rol' => $usuario['rol_nombre'], 'entidad_id' => $usuario['fk_entidad'],
        'entidad_nombre' => $usuario['entidad_nombre'],
    ]]);
} catch (Throwable $error) {
    apiErrorInterno($error, 'Error al consultar el perfil en la API');
}
