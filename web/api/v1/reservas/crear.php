<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
apiAceptarMetodo('POST');
$datos = apiLeerJson();
$espacioId = isset($datos['espacio_id']) ? (int) $datos['espacio_id'] : 0;
$fecha = trim((string) ($datos['fecha'] ?? ''));
$horario = trim((string) ($datos['horario'] ?? ''));
if ($espacioId < 1) apiResponder(['error' => 'espacio_id es obligatorio y debe ser un entero positivo.'], 422);
$fechaObjeto = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
$erroresFecha = DateTimeImmutable::getLastErrors();
if ($fechaObjeto === false || ($erroresFecha !== false && ($erroresFecha['warning_count'] > 0 || $erroresFecha['error_count'] > 0)) || $fechaObjeto->format('Y-m-d') !== $fecha) {
    apiResponder(['error' => 'La fecha debe tener formato YYYY-MM-DD.'], 422);
}
if (preg_match('/^([01][0-9]|2[0-3]):00(?::00)?$/', $horario) !== 1) {
    apiResponder(['error' => 'El horario debe corresponder a una hora completa con formato HH:00.'], 422);
}
if (strlen($horario) === 5) $horario .= ':00';

try {
    [$pdo, $usuario] = apiContextoAutenticado('Alumno');
    $stmt = $pdo->prepare("
        SELECT e.horario_apertura, e.horario_cierre,
               da.orden AS dia_apertura_orden, dc.orden AS dia_cierre_orden
        FROM espacios esp
        JOIN entidades e ON esp.fk_entidad = e.id
        LEFT JOIN dias da ON e.fk_dia_apertura = da.id
        LEFT JOIN dias dc ON e.fk_dia_cierre = dc.id
        WHERE esp.id = ? AND esp.estado = TRUE AND e.estado = TRUE
    ");
    $stmt->execute([$espacioId]);
    $espacio = $stmt->fetch();
    if (!$espacio) apiResponder(['error' => 'Espacio no encontrado.'], 404);

    $hoy = new DateTimeImmutable('today');
    if ($fechaObjeto < $hoy) apiResponder(['error' => 'No podés reservar una fecha pasada.'], 422);
    $diaNumero = (int) $fechaObjeto->format('N');
    $ordenApertura = $espacio['dia_apertura_orden'] === null ? 1 : (int) $espacio['dia_apertura_orden'];
    $ordenCierre = $espacio['dia_cierre_orden'] === null ? 7 : (int) $espacio['dia_cierre_orden'];
    $abierto = $ordenApertura <= $ordenCierre
        ? $diaNumero >= $ordenApertura && $diaNumero <= $ordenCierre
        : $diaNumero >= $ordenApertura || $diaNumero <= $ordenCierre;
    if (!$abierto) apiResponder(['error' => 'La entidad se encuentra cerrada en la fecha seleccionada.'], 409);

    $hora = (int) substr($horario, 0, 2);
    $horaInicio = (int) substr((string) $espacio['horario_apertura'], 0, 2);
    $horaFin = (int) substr((string) $espacio['horario_cierre'], 0, 2);
    if ($hora < $horaInicio || $hora >= $horaFin) apiResponder(['error' => 'El horario está fuera del horario de atención.'], 422);
    $ahora = new DateTimeImmutable();
    if ($fecha === $ahora->format('Y-m-d') && $horario <= $ahora->format('H:i:s')) {
        apiResponder(['error' => 'No podés reservar un horario que ya ha transcurrido.'], 409);
    }

    $stmt = $pdo->prepare("
        SELECT 1 FROM reservas
        WHERE fk_espacio = ? AND fecha_de_asistencia = ? AND horario = ? AND estado = 'reservado'
        LIMIT 1
    ");
    $stmt->execute([$espacioId, $fecha, $horario]);
    if ($stmt->fetchColumn()) apiResponder(['error' => 'El horario ya se encuentra reservado.'], 409);

    $inicio = strtotime('1970-01-01 ' . $horario);
    $fin = $inicio + 3600;
    $stmt = $pdo->prepare("
        SELECT c.nombre, c.horario_inicio, c.horario_cierre
        FROM modulos c
        JOIN dias_modulos dm ON c.id = dm.fk_modulo
        JOIN dias d ON dm.fk_dia = d.id
        WHERE c.fk_espacio = ? AND d.orden = ? AND c.estado = TRUE
    ");
    $stmt->execute([$espacioId, $diaNumero]);
    foreach ($stmt->fetchAll() as $modulo) {
        $moduloInicio = strtotime('1970-01-01 ' . $modulo['horario_inicio']);
        $moduloFin = strtotime('1970-01-01 ' . $modulo['horario_cierre']);
        if ($inicio < $moduloFin && $fin > $moduloInicio) {
            apiResponder(['error' => 'El horario está ocupado por un módulo.'], 409);
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO reservas (fk_espacio, fk_usuario, fecha_de_asistencia, horario, estado)
        VALUES (?, ?, ?, ?, 'reservado')
        RETURNING id
    ");
    $stmt->execute([$espacioId, $usuario['id'], $fecha, $horario]);
    apiResponder(['data' => [
        'id' => (int) $stmt->fetchColumn(), 'espacio_id' => $espacioId,
        'fecha' => $fecha, 'horario' => $horario, 'estado' => 'reservado',
    ]], 201);
} catch (PDOException $error) {
    if ($error->getCode() === '23505') apiResponder(['error' => 'El horario ya no se encuentra disponible.'], 409);
    apiErrorInterno($error, 'Error al crear una reserva en la API');
} catch (Throwable $error) {
    apiErrorInterno($error, 'Error al crear una reserva en la API');
}
