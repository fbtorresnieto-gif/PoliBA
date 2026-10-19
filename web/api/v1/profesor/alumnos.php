<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
apiAceptarMetodo('GET');
$moduloId = apiParametroEntero('modulo_id');
$fecha = apiParametroFecha('fecha');
try {
    [$pdo, $usuario] = apiContextoAutenticado('Profesor');
    $stmt = $pdo->prepare("
        SELECT id, nombre FROM modulos
        WHERE id = ? AND fk_usuario_profesor = ? AND estado = TRUE
    ");
    $stmt->execute([$moduloId, $usuario['id']]);
    $modulo = $stmt->fetch();
    if (!$modulo) apiResponder(['error' => 'Módulo no encontrado.'], 404);

    $stmt = $pdo->prepare("
        SELECT i.id AS inscripcion_id,
               u.id AS usuario_id, u.nombre AS usuario_nombre,
               u.apellido AS usuario_apellido, u.dni AS usuario_dni,
               m.id AS menor_id, m.nombre AS menor_nombre,
               m.apellido AS menor_apellido, m.dni AS menor_dni,
               asi.asistencia
        FROM inscripcion i
        LEFT JOIN usuarios u ON i.fk_usuario = u.id
        LEFT JOIN menores m ON i.fk_menor = m.id
        LEFT JOIN asistencia asi ON asi.fk_inscripcion = i.id AND asi.fecha = ?
        WHERE i.fk_modulo = ? AND i.estado = 'activo' AND i.lista_espera = FALSE
        ORDER BY u.apellido, u.nombre, m.apellido, m.nombre ASC
    ");
    $stmt->execute([$fecha, $moduloId]);
    $data = array_map(static function (array $fila): array {
        $esMenor = $fila['menor_id'] !== null;
        return [
            'inscripcion_id' => (int) $fila['inscripcion_id'],
            'es_menor' => $esMenor,
            'persona_id' => (int) ($esMenor ? $fila['menor_id'] : $fila['usuario_id']),
            'nombre' => $esMenor ? $fila['menor_nombre'] : $fila['usuario_nombre'],
            'apellido' => $esMenor ? $fila['menor_apellido'] : $fila['usuario_apellido'],
            'dni' => $esMenor ? $fila['menor_dni'] : $fila['usuario_dni'],
            'asistencia' => $fila['asistencia'],
        ];
    }, $stmt->fetchAll());
    apiResponder(['data' => [
        'modulo' => ['id' => (int) $modulo['id'], 'nombre' => $modulo['nombre']],
        'fecha' => $fecha, 'alumnos' => $data,
    ]]);
} catch (Throwable $error) {
    apiErrorInterno($error, 'Error al listar alumnos de un módulo en la API');
}
