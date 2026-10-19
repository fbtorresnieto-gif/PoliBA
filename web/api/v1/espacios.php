<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
apiAceptarMetodo('GET');
$entidadId = apiParametroEntero('entidad_id');
try {
    $pdo = apiBaseDatos();
    $stmt = $pdo->prepare("
        SELECT esp.id, esp.nombre, esp.descripcion, esp.imagenurl, esp.techado,
               esp.fk_entidad, e.nombre AS entidad_nombre
        FROM espacios esp
        JOIN entidades e ON esp.fk_entidad = e.id
        WHERE esp.fk_entidad = ? AND esp.estado = TRUE AND e.estado = TRUE
        ORDER BY esp.nombre ASC
    ");
    $stmt->execute([$entidadId]);
    $data = array_map(static fn (array $fila): array => [
        'id' => (int) $fila['id'], 'nombre' => $fila['nombre'],
        'descripcion' => $fila['descripcion'], 'techado' => (bool) $fila['techado'],
        'entidad_id' => (int) $fila['fk_entidad'], 'entidad_nombre' => $fila['entidad_nombre'],
        'imagen_url' => apiUrlImagen($fila['imagenurl']),
    ], $stmt->fetchAll());
    apiResponder(['data' => $data]);
} catch (Throwable $error) {
    apiErrorInterno($error, 'Error al listar espacios en la API');
}
