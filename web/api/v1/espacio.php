<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
apiAceptarMetodo('GET');
$espacioId = apiParametroEntero('id');
try {
    $pdo = apiBaseDatos();
    $stmt = $pdo->prepare("
        SELECT esp.id, esp.nombre, esp.descripcion, esp.imagenurl, esp.techado,
               esp.fk_entidad, e.nombre AS entidad_nombre, e.direccion AS entidad_direccion,
               e.horario_apertura, e.horario_cierre,
               da.nombre AS dia_apertura, dc.nombre AS dia_cierre
        FROM espacios esp
        JOIN entidades e ON esp.fk_entidad = e.id
        LEFT JOIN dias da ON e.fk_dia_apertura = da.id
        LEFT JOIN dias dc ON e.fk_dia_cierre = dc.id
        WHERE esp.id = ? AND esp.estado = TRUE AND e.estado = TRUE
    ");
    $stmt->execute([$espacioId]);
    $fila = $stmt->fetch();
    if (!$fila) apiResponder(['error' => 'Espacio no encontrado.'], 404);
    apiResponder(['data' => [
        'id' => (int) $fila['id'], 'nombre' => $fila['nombre'],
        'descripcion' => $fila['descripcion'], 'techado' => (bool) $fila['techado'],
        'entidad_id' => (int) $fila['fk_entidad'], 'entidad_nombre' => $fila['entidad_nombre'],
        'entidad_direccion' => $fila['entidad_direccion'], 'dia_apertura' => $fila['dia_apertura'],
        'dia_cierre' => $fila['dia_cierre'], 'horario_apertura' => $fila['horario_apertura'],
        'horario_cierre' => $fila['horario_cierre'], 'imagen_url' => apiUrlImagen($fila['imagenurl']),
    ]]);
} catch (Throwable $error) {
    apiErrorInterno($error, 'Error al consultar un espacio en la API');
}
