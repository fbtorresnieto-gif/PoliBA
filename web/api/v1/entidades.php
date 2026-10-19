<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
apiAceptarMetodo('GET');

try {
    $pdo = apiBaseDatos();
    $stmt = $pdo->query("
        SELECT e.id, e.nombre, e.direccion, e.informacion,
               d1.nombre AS dia_apertura, d2.nombre AS dia_cierre,
               e.horario_apertura, e.horario_cierre, e.coordenadas, e.imagenurl
        FROM entidades e
        LEFT JOIN dias d1 ON e.fk_dia_apertura = d1.id
        LEFT JOIN dias d2 ON e.fk_dia_cierre = d2.id
        WHERE e.estado = TRUE
        ORDER BY e.nombre ASC
    ");
    $data = array_map(static fn (array $fila): array => [
        'id' => (int) $fila['id'], 'nombre' => $fila['nombre'],
        'direccion' => $fila['direccion'], 'informacion' => $fila['informacion'],
        'dia_apertura' => $fila['dia_apertura'], 'dia_cierre' => $fila['dia_cierre'],
        'horario_apertura' => $fila['horario_apertura'], 'horario_cierre' => $fila['horario_cierre'],
        'coordenadas' => $fila['coordenadas'], 'imagen_url' => apiUrlImagen($fila['imagenurl']),
    ], $stmt->fetchAll());
    apiResponder(['data' => $data]);
} catch (Throwable $error) {
    apiErrorInterno($error, 'Error al listar entidades en la API');
}
