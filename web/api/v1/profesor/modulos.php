<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
apiAceptarMetodo('GET');
try {
    [$pdo, $usuario] = apiContextoAutenticado('Profesor');
    $stmt = $pdo->prepare("
        SELECT c.id, c.nombre, c.descripcion, c.horario_inicio, c.horario_cierre,
               c.cupo_maximo, c.fk_actividad, a.nombre AS actividad_nombre,
               c.fk_entidad, e.nombre AS entidad_nombre,
               c.fk_espacio, esp.nombre AS espacio_nombre
        FROM modulos c
        JOIN actividades a ON c.fk_actividad = a.id
        JOIN entidades e ON c.fk_entidad = e.id
        LEFT JOIN espacios esp ON c.fk_espacio = esp.id
        WHERE c.fk_usuario_profesor = ? AND c.estado = TRUE
        ORDER BY c.nombre ASC
    ");
    $stmt->execute([$usuario['id']]);
    $data = array_map(static fn (array $fila): array => [
        'id' => (int) $fila['id'], 'nombre' => $fila['nombre'],
        'descripcion' => $fila['descripcion'], 'horario_inicio' => $fila['horario_inicio'],
        'horario_cierre' => $fila['horario_cierre'], 'cupo_maximo' => (int) $fila['cupo_maximo'],
        'actividad_id' => (int) $fila['fk_actividad'], 'actividad_nombre' => $fila['actividad_nombre'],
        'entidad_id' => (int) $fila['fk_entidad'], 'entidad_nombre' => $fila['entidad_nombre'],
        'espacio_id' => $fila['fk_espacio'] === null ? null : (int) $fila['fk_espacio'],
        'espacio_nombre' => $fila['espacio_nombre'],
    ], $stmt->fetchAll());
    apiResponder(['data' => $data]);
} catch (Throwable $error) {
    apiErrorInterno($error, 'Error al listar módulos del Profesor en la API');
}
