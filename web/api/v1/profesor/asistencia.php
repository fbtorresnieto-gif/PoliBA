<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
apiAceptarMetodo('POST');
$datos = apiLeerJson();
$moduloId = isset($datos['modulo_id']) ? (int) $datos['modulo_id'] : 0;
$inscripcionId = isset($datos['inscripcion_id']) ? (int) $datos['inscripcion_id'] : 0;
$estado = strtolower(trim((string) ($datos['estado'] ?? '')));
$fecha = trim((string) ($datos['fecha'] ?? ''));
if ($moduloId < 1 || $inscripcionId < 1) apiResponder(['error' => 'modulo_id e inscripcion_id deben ser enteros positivos.'], 422);
if (!in_array($estado, ['presente', 'ausente', 'lluvia'], true)) apiResponder(['error' => 'El estado debe ser presente, ausente o lluvia.'], 422);
$fechaObjeto = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
$erroresFecha = DateTimeImmutable::getLastErrors();
if ($fechaObjeto === false || ($erroresFecha !== false && ($erroresFecha['warning_count'] > 0 || $erroresFecha['error_count'] > 0)) || $fechaObjeto->format('Y-m-d') !== $fecha) {
    apiResponder(['error' => 'La fecha debe tener formato YYYY-MM-DD.'], 422);
}

try {
    [$pdo, $usuario] = apiContextoAutenticado('Profesor');
    $stmt = $pdo->prepare("
        SELECT i.id
        FROM inscripcion i
        JOIN modulos m ON i.fk_modulo = m.id
        WHERE i.id = ? AND i.fk_modulo = ?
          AND i.estado = 'activo' AND i.lista_espera = FALSE
          AND m.fk_usuario_profesor = ? AND m.estado = TRUE
    ");
    $stmt->execute([$inscripcionId, $moduloId, $usuario['id']]);
    if (!$stmt->fetchColumn()) apiResponder(['error' => 'Inscripción o módulo no encontrado.'], 404);

    $stmt = $pdo->prepare("
        INSERT INTO asistencia (fk_inscripcion, fk_modulo, asistencia, fecha)
        VALUES (?, ?, ?, ?)
        ON CONFLICT (fk_inscripcion, fecha)
        DO UPDATE SET asistencia = EXCLUDED.asistencia, fk_modulo = EXCLUDED.fk_modulo
        RETURNING id
    ");
    $stmt->execute([$inscripcionId, $moduloId, $estado, $fecha]);
    apiResponder(['data' => [
        'id' => (int) $stmt->fetchColumn(), 'modulo_id' => $moduloId,
        'inscripcion_id' => $inscripcionId, 'fecha' => $fecha, 'estado' => $estado,
    ]]);
} catch (Throwable $error) {
    apiErrorInterno($error, 'Error al registrar asistencia en la API');
}
