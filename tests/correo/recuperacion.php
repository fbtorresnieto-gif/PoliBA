<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/web/includes/mailer.php';
require dirname(__DIR__, 2) . '/web/includes/RecuperacionContrasena.php';

function asegurar(bool $condicion, string $mensaje): void
{
    if (!$condicion) throw new RuntimeException($mensaje);
}

$pdo = new PDO(
    sprintf('pgsql:host=127.0.0.1;port=5433;dbname=%s;sslmode=disable', getenv('MULTIPRO_TEST_DB')),
    (string) getenv('MULTIPRO_TEST_USER'),
    (string) getenv('MULTIPRO_TEST_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$usuario = $pdo->query("SELECT id,nombre,email,contrasena FROM usuarios WHERE estado=TRUE ORDER BY id LIMIT 1")->fetch();
asegurar((bool) $usuario, 'Falta usuario activo de prueba');
$usuarioId = (int) $usuario['id'];
$hashAnterior = (string) $usuario['contrasena'];
$pdo->prepare('DELETE FROM password_resets WHERE fk_usuario=?')->execute([$usuarioId]);

$mensajes = [];
$enviar = static function (string $email, string $nombre, string $asunto, string $html) use (&$mensajes): bool {
    $mensajes[] = compact('email', 'nombre', 'asunto', 'html');
    return true;
};
$servicio = new RecuperacionContrasena($pdo, $enviar);

$servicio->solicitar((string) $usuario['email'], 'http://127.0.0.1:8080');
asegurar(count($mensajes) === 1, 'No se intento un unico correo');
asegurar(str_contains($mensajes[0]['asunto'], 'MultiPro'), 'Asunto sin nombre de producto');
preg_match('/token=([0-9a-f]{64})/', $mensajes[0]['html'], $coincidencia);
$token1 = $coincidencia[1] ?? '';
asegurar(strlen($token1) === 64, 'El correo no contiene token aleatorio');
asegurar($servicio->tokenValido($token1), 'Token enviado no quedo activo');
$fila = $pdo->prepare('SELECT token_hash,enviado_en,usado_en FROM password_resets WHERE fk_usuario=?');
$fila->execute([$usuarioId]);
$reset = $fila->fetch();
asegurar($reset['token_hash'] === hash('sha256', $token1), 'No se guardo exclusivamente el hash');
asegurar($reset['token_hash'] !== $token1, 'Token crudo persistido');
asegurar($reset['enviado_en'] !== null && $reset['usado_en'] === null, 'Estado de envio incorrecto');

$servicio->solicitar((string) $usuario['email'], 'http://127.0.0.1:8080');
preg_match('/token=([0-9a-f]{64})/', $mensajes[1]['html'], $coincidencia2);
$token2 = $coincidencia2[1] ?? '';
asegurar(!$servicio->tokenValido($token1), 'Solicitud nueva no invalido token anterior');
asegurar($servicio->tokenValido($token2), 'Segundo token no quedo activo');

$fallido = new RecuperacionContrasena($pdo, static fn (): bool => false);
$fallido->solicitar((string) $usuario['email'], 'http://127.0.0.1:8080');
asegurar(!$servicio->tokenValido($token2), 'Fallo de envio dejo token anterior activo');
asegurar((int) $pdo->query('SELECT count(*) FROM password_resets WHERE usado_en IS NULL')->fetchColumn() === 0, 'Fallo de envio dejo token utilizable');

$antesDesconocido = (int) $pdo->query('SELECT count(*) FROM password_resets')->fetchColumn();
$servicio->solicitar('cuenta-inexistente@example.invalid', 'http://127.0.0.1:8080');
asegurar((int) $pdo->query('SELECT count(*) FROM password_resets')->fetchColumn() === $antesDesconocido, 'Cuenta inexistente genero token');

// Limpiar la ventana de tasa en esta base aislada y crear un token final.
$pdo->prepare("UPDATE password_resets SET created_at=created_at-INTERVAL '2 hours'")->execute();
$servicio->solicitar((string) $usuario['email'], 'http://127.0.0.1:8080');
$ultimo = end($mensajes);
preg_match('/token=([0-9a-f]{64})/', $ultimo['html'], $coincidencia3);
$tokenFinal = $coincidencia3[1] ?? '';

$pdo->prepare("INSERT INTO api_sesiones(fk_usuario,token_hash,vence_en) VALUES(?, ?, CURRENT_TIMESTAMP+INTERVAL '1 day')")
    ->execute([$usuarioId, hash('sha256', 'sesion-prueba')]);
asegurar($servicio->restablecer($tokenFinal, 'ClaveNuevaSegura2026!', 'ClaveNuevaSegura2026!'), 'No se restablecio la contrasena');
asegurar(!$servicio->tokenValido($tokenFinal), 'Token reutilizable despues del cambio');
$hashNuevo = (string) $pdo->query('SELECT contrasena FROM usuarios WHERE id=' . $usuarioId)->fetchColumn();
asegurar($hashNuevo !== $hashAnterior && password_verify('ClaveNuevaSegura2026!', $hashNuevo), 'Hash de contrasena no actualizado');
$revocada = $pdo->prepare('SELECT revocado_en IS NOT NULL FROM api_sesiones WHERE fk_usuario=? ORDER BY id DESC LIMIT 1');
$revocada->execute([$usuarioId]);
asegurar((bool) $revocada->fetchColumn(), 'Sesion API no revocada');
asegurar(!$servicio->restablecer($tokenFinal, 'OtraClaveSegura2026!', 'OtraClaveSegura2026!'), 'Token se uso dos veces');

$htmlSeguro = build_reset_email('<script>alert(1)</script>', "https://example.test/reset?x='y");
asegurar(!str_contains($htmlSeguro, '<script>'), 'Nombre no escapado en HTML');
asegurar(str_contains($htmlSeguro, '&lt;script&gt;'), 'Nombre escapado ausente');

// Cinco solicitudes en una hora como maximo; la sexta no llama al proveedor.
$pdo->prepare('DELETE FROM password_resets WHERE fk_usuario=?')->execute([$usuarioId]);
$mensajes = [];
for ($i = 0; $i < 6; $i++) $servicio->solicitar((string) $usuario['email'], 'http://127.0.0.1:8080');
asegurar(count($mensajes) === 5, 'Limite horario por cuenta incorrecto');
asegurar((int) $pdo->query('SELECT count(*) FROM password_resets WHERE fk_usuario=' . $usuarioId)->fetchColumn() === 5, 'Limite creo filas adicionales');

echo "PRUEBAS_RECUPERACION_BREVO_LOCAL_OK\n";
