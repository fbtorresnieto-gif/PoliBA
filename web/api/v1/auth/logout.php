<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
apiAceptarMetodo('POST');
try {
    $token = apiTokenBearer();
    $pdo = apiBaseDatos();
    $stmt = $pdo->prepare("
        UPDATE api_sesiones
        SET revocado_en = CURRENT_TIMESTAMP
        WHERE token_hash = ? AND revocado_en IS NULL AND vence_en > CURRENT_TIMESTAMP
    ");
    $stmt->execute([hash('sha256', $token)]);
    if ($stmt->rowCount() === 0) {
        apiResponder(['error' => 'La sesión no es válida o ha vencido.'], 401);
    }
    apiResponder(['data' => ['mensaje' => 'Sesión cerrada correctamente.']]);
} catch (Throwable $error) {
    apiErrorInterno($error, 'Error al cerrar una sesión de la API');
}
