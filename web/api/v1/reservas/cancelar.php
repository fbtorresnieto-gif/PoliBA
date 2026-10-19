<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
apiAceptarMetodo('POST');
$datos = apiLeerJson();
$reservaId = isset($datos['reserva_id']) ? (int) $datos['reserva_id'] : 0;
if ($reservaId < 1) apiResponder(['error' => 'reserva_id es obligatorio y debe ser un entero positivo.'], 422);
try {
    [$pdo, $usuario] = apiContextoAutenticado('Alumno');
    $stmt = $pdo->prepare("
        UPDATE reservas
        SET estado = 'cancelado'
        WHERE id = ? AND fk_usuario = ? AND estado = 'reservado'
        RETURNING id
    ");
    $stmt->execute([$reservaId, $usuario['id']]);
    if (!$stmt->fetchColumn()) apiResponder(['error' => 'Reserva activa no encontrada.'], 404);
    apiResponder(['data' => ['id' => $reservaId, 'estado' => 'cancelado']]);
} catch (Throwable $error) {
    apiErrorInterno($error, 'Error al cancelar una reserva propia en la API');
}
