<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../../includes/RecuperacionContrasena.php';
apiAceptarMetodo('POST');

$datos = apiLeerJson();
$email = mb_strtolower(trim((string) ($datos['email'] ?? '')), 'UTF-8');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    apiResponder(['error' => 'Ingresá un correo electrónico válido.'], 422);
}

try {
    $servicio = new RecuperacionContrasena(
        apiBaseDatos(),
        static fn(string $destinatario, string $nombre, string $asunto, string $html): bool => false
    );
    $servicio->solicitar($email);
} catch (Throwable $error) {
    error_log('Recuperacion de contrasena movil no disponible: ' . $error->getMessage());
}

apiResponder(['data' => [
    'mensaje' => 'Si ese correo está registrado en MultiPro, vas a recibir un enlace para restablecer tu contraseña en los próximos minutos.',
]], 202);
