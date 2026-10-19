<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
apiAceptarMetodo('GET');
$reservaId = apiParametroEntero('id');
try {
    [$pdo, $usuario] = apiContextoAutenticado('Alumno');
    $stmt = $pdo->prepare("
        SELECT r.id, r.fk_espacio, r.fecha_de_asistencia, r.horario, r.estado,
               esp.nombre AS espacio_nombre, e.id AS entidad_id,
               e.nombre AS entidad_nombre, e.direccion AS entidad_direccion
        FROM reservas r
        JOIN espacios esp ON r.fk_espacio = esp.id
        JOIN entidades e ON esp.fk_entidad = e.id
        WHERE r.id = ? AND r.fk_usuario = ?
    ");
    $stmt->execute([$reservaId, $usuario['id']]);
    $fila = $stmt->fetch();
    if (!$fila) apiResponder(['error' => 'Reserva no encontrada.'], 404);
    apiResponder(['data' => [
        'id' => (int) $fila['id'], 'espacio_id' => (int) $fila['fk_espacio'],
        'espacio_nombre' => $fila['espacio_nombre'], 'entidad_id' => (int) $fila['entidad_id'],
        'entidad_nombre' => $fila['entidad_nombre'], 'entidad_direccion' => $fila['entidad_direccion'],
        'fecha' => $fila['fecha_de_asistencia'], 'horario' => $fila['horario'],
        'estado' => $fila['estado'],
    ]]);
} catch (Throwable $error) {
    apiErrorInterno($error, 'Error al consultar una reserva propia en la API');
}
