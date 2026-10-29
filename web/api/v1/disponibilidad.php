<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_cobro_espacio.php';
apiAceptarMetodo('GET');
$espacioId = apiParametroEntero('espacio_id');
$fecha = apiParametroFecha('fecha');

try {
    $pdo = apiBaseDatos();
    $stmt = $pdo->prepare("
        SELECT esp.id, esp.precio_hora_ars, esp.pago_presencial_habilitado, e.horario_apertura, e.horario_cierre,
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

    $fechaObjeto = new DateTimeImmutable($fecha);
    $diaNumero = (int) $fechaObjeto->format('N');
    $ordenApertura = $espacio['dia_apertura_orden'] === null ? 1 : (int) $espacio['dia_apertura_orden'];
    $ordenCierre = $espacio['dia_cierre_orden'] === null ? 7 : (int) $espacio['dia_cierre_orden'];
    $abierto = $ordenApertura <= $ordenCierre
        ? $diaNumero >= $ordenApertura && $diaNumero <= $ordenCierre
        : $diaNumero >= $ordenApertura || $diaNumero <= $ordenCierre;

    $stmt = $pdo->prepare("
        SELECT horario FROM reservas
        WHERE fk_espacio = ? AND fecha_de_asistencia = ? AND estado IN ('reservado','pendiente_pago','pendiente_presencial')
    ");
    $stmt->execute([$espacioId, $fecha]);
    $reservados = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    $stmt = $pdo->prepare("
        SELECT c.nombre, c.horario_inicio, c.horario_cierre
        FROM modulos c
        JOIN dias_modulos dm ON c.id = dm.fk_modulo
        JOIN dias d ON dm.fk_dia = d.id
        WHERE c.fk_espacio = ? AND d.orden = ? AND c.estado = TRUE
    ");
    $stmt->execute([$espacioId, $diaNumero]);
    $modulos = $stmt->fetchAll();

    $horaInicio = (int) substr((string) $espacio['horario_apertura'], 0, 2);
    $horaFin = (int) substr((string) $espacio['horario_cierre'], 0, 2);
    $ahora = new DateTimeImmutable();
    $slots = [];
    for ($hora = $horaInicio; $hora < $horaFin; $hora++) {
        $inicio = sprintf('%02d:00:00', $hora);
        $inicioSegundos = strtotime('1970-01-01 ' . $inicio);
        $finSegundos = $inicioSegundos + 3600;
        $moduloOcupante = null;
        foreach ($modulos as $modulo) {
            $moduloInicio = strtotime('1970-01-01 ' . $modulo['horario_inicio']);
            $moduloFin = strtotime('1970-01-01 ' . $modulo['horario_cierre']);
            if ($inicioSegundos < $moduloFin && $finSegundos > $moduloInicio) {
                $moduloOcupante = $modulo['nombre'];
                break;
            }
        }
        $pasado = $fecha < $ahora->format('Y-m-d')
            || ($fecha === $ahora->format('Y-m-d') && $inicio <= $ahora->format('H:i:s'));
        $reservado = isset($reservados[$inicio]);
        $motivo = !$abierto ? 'entidad_cerrada'
            : ($pasado ? 'horario_pasado'
            : ($reservado ? 'reservado'
            : ($moduloOcupante !== null ? 'modulo' : null)));
        $slots[] = [
            'horario' => $inicio,
            'disponible' => $motivo === null,
            'motivo_no_disponible' => $motivo,
            'modulo' => $moduloOcupante,
        ];
    }

    apiResponder(['data' => [
        ...apiCobroEspacio($espacio),
        'espacio_id' => $espacioId, 'fecha' => $fecha,
        'entidad_abierta' => $abierto, 'slots' => $slots,
    ]]);
} catch (Throwable $error) {
    apiErrorInterno($error, 'Error al consultar disponibilidad en la API');
}
