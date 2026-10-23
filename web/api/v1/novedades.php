<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
apiAceptarMetodo('GET');
try {
    $pdo = apiBaseDatos();
    require_once __DIR__ . '/../../includes/novedades.php';
    expirar_novedades($pdo);
    $stmt = $pdo->prepare("
        SELECT n.id, n.nombre, n.descripcion, n.fecha_inicio, n.fecha_fin,
               n.imagenurl, n.fk_entidad, e.nombre AS entidad_nombre
        FROM novedades n
        LEFT JOIN entidades e ON n.fk_entidad = e.id
        WHERE n.estado = TRUE AND n.fecha_fin >= ?
        ORDER BY n.fecha_inicio DESC
    ");
    $stmt->execute([hoy_ar()]);
    $data = array_map(static fn (array $fila): array => [
        'id' => (int) $fila['id'], 'nombre' => $fila['nombre'],
        'descripcion' => $fila['descripcion'], 'fecha_inicio' => $fila['fecha_inicio'],
        'fecha_fin' => $fila['fecha_fin'],
        'entidad_id' => $fila['fk_entidad'] === null ? null : (int) $fila['fk_entidad'],
        'entidad_nombre' => $fila['entidad_nombre'], 'imagen_url' => apiUrlImagen($fila['imagenurl']),
    ], $stmt->fetchAll());
    apiResponder(['data' => $data]);
} catch (Throwable $error) {
    apiErrorInterno($error, 'Error al listar novedades en la API');
}
