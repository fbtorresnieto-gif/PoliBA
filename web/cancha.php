<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$cancha_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$cancha = null;
$error_msg = '';
$success_msg = '';
$reserva_id = 0;

if ($cancha_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT c.*, p.nombre as polideportivo_nombre, p.horario_apertura, p.horario_cierre,
                   p.fk_dia_apertura, p.fk_dia_cierre,
                   da.nombre as dia_apertura_nombre, da.orden as dia_apertura_orden,
                   dc.nombre as dia_cierre_nombre, dc.orden as dia_cierre_orden
            FROM canchas c
            JOIN polideportivos p ON c.fk_polideportivo = p.id
            LEFT JOIN dias da ON p.fk_dia_apertura = da.id
            LEFT JOIN dias dc ON p.fk_dia_cierre = dc.id
            WHERE c.id = ? AND c.estado = TRUE AND p.estado = TRUE
        ");
        $stmt->execute([$cancha_id]);
        $cancha = $stmt->fetch();
    } catch (PDOException $e) {
        // Log
    }
}

if (!$cancha) {
    header("Location: canchas.php");
    exit;
}

// Fecha seleccionada (default: mañana)
$selected_date = isset($_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d', strtotime('+1 day'));

// Calcular día de la semana para la fecha seleccionada
$dia_semana_num = intval(date('N', strtotime($selected_date))); // 1 = Lunes, 7 = Domingo
$dias_semana_nombres = [
    1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles',
    4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'
];
$nombre_dia_actual = $dias_semana_nombres[$dia_semana_num] ?? '';

// Verificar si el polideportivo abre en este día de la semana
$orden_apertura = $cancha['dia_apertura_orden'] !== null ? intval($cancha['dia_apertura_orden']) : 1;
$orden_cierre = $cancha['dia_cierre_orden'] !== null ? intval($cancha['dia_cierre_orden']) : 7;

$esta_abierto_hoy = false;
if ($orden_apertura <= $orden_cierre) {
    $esta_abierto_hoy = ($dia_semana_num >= $orden_apertura && $dia_semana_num <= $orden_cierre);
} else {
    // Si envuelve fin de semana (ej: Martes (2) a Domingo (7) o Viernes (5) a Martes (2))
    $esta_abierto_hoy = ($dia_semana_num >= $orden_apertura || $dia_semana_num <= $orden_cierre);
}

// Generar slots de 1 hora dinámicamente según horario de apertura y cierre del polideportivo
$hora_inicio = !empty($cancha['horario_apertura']) ? intval(substr($cancha['horario_apertura'], 0, 2)) : 8;
$hora_fin = !empty($cancha['horario_cierre']) ? intval(substr($cancha['horario_cierre'], 0, 2)) : 22;

$slots = [];
for ($h = $hora_inicio; $h < $hora_fin; $h++) {
    $time_str = sprintf('%02d:00:00', $h);
    $label = sprintf('%02d:00 hs', $h);
    $slots[$time_str] = $label;
}

// Obtener las reservas existentes para esta cancha en la fecha seleccionada
$reservas_existentes = [];
try {
    $stmt = $pdo->prepare("
        SELECT horario 
        FROM reservas 
        WHERE fk_cancha = ? AND fecha_de_asistencia = ? AND estado = 'reservado'
    ");
    $stmt->execute([$cancha_id, $selected_date]);
    $reservas_existentes = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // Log
}

// Obtener las clases programadas para esta cancha en este día de la semana
$clases_dia = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.nombre, c.horario_inicio, c.horario_cierre, d.nombre as deporte_nombre
        FROM clases c
        JOIN dias_clases dc ON c.id = dc.fk_clase
        JOIN dias dia ON dc.fk_dia = dia.id
        LEFT JOIN deportes d ON c.fk_deporte = d.id
        WHERE c.fk_canchas = ? AND dia.orden = ? AND c.estado = TRUE
    ");
    $stmt->execute([$cancha_id, $dia_semana_num]);
    $clases_dia = $stmt->fetchAll();
} catch (PDOException $e) {
    // Log
}

// Procesar la solicitud de reserva (Solo Alumnos)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'reservar') {
    redirect_if_not_logged_in(['Alumno']);
    
    $user = get_logged_user();
    $fecha = $_POST['fecha'];
    $horario = $_POST['horario'];
    
    // 1. Validaciones básicas
    if (empty($fecha) || empty($horario)) {
        $error_msg = 'Por favor, seleccioná una fecha y un horario válidos.';
    } elseif (strtotime($fecha) < strtotime(date('Y-m-d'))) {
        $error_msg = 'No podés realizar reservas para fechas pasadas.';
    } elseif (!$esta_abierto_hoy) {
        $error_msg = 'El polideportivo se encuentra cerrado los días ' . $nombre_dia_actual . '.';
    } elseif (!array_key_exists($horario, $slots)) {
        $error_msg = 'El horario seleccionado está fuera del horario de atención del polideportivo (' . date('H:i', strtotime($cancha['horario_apertura'])) . ' a ' . date('H:i', strtotime($cancha['horario_cierre'])) . ' hs).';
    } elseif ($fecha === date('Y-m-d') && $horario <= date('H:i:s')) {
        $error_msg = 'No podés reservar un horario que ya ha transcurrido hoy.';
    } elseif (in_array($horario, $reservas_existentes)) {
        $error_msg = 'El horario seleccionado ya se encuentra reservado por otro usuario.';
    } else {
        // 2. Validar que no colisione con ninguna clase deportiva
        $slot_start_sec = strtotime("1970-01-01 $horario");
        $slot_end_sec = $slot_start_sec + 3600;
        $clase_collision = null;
        
        foreach ($clases_dia as $cl) {
            $cl_start_sec = strtotime("1970-01-01 " . $cl['horario_inicio']);
            $cl_end_sec = strtotime("1970-01-01 " . $cl['horario_cierre']);
            if ($slot_start_sec < $cl_end_sec && $slot_end_sec > $cl_start_sec) {
                $clase_collision = $cl;
                break;
            }
        }
        
        if ($clase_collision) {
            $error_msg = 'El horario seleccionado no está disponible debido a la clase deportiva "' . htmlspecialchars($clase_collision['nombre']) . '" (' . date('H:i', strtotime($clase_collision['horario_inicio'])) . ' a ' . date('H:i', strtotime($clase_collision['horario_cierre'])) . ' hs).';
        } else {
            try {
                if ($db_driver_used === 'postgresql') {
                    $stmt = $pdo->prepare("
                        INSERT INTO reservas (fk_cancha, fk_usuario, fecha_de_asistencia, horario, estado)
                        VALUES (?, ?, ?, ?, 'reservado')
                        RETURNING id
                    ");
                    $stmt->execute([$cancha_id, $user['id'], $fecha, $horario]);
                    $reserva_id = $stmt->fetchColumn();
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO reservas (fk_cancha, fk_usuario, fecha_de_asistencia, horario, estado)
                        VALUES (?, ?, ?, ?, 'reservado')
                    ");
                    $stmt->execute([$cancha_id, $user['id'], $fecha, $horario]);
                    $reserva_id = $pdo->lastInsertId();
                }
                
                if ($reserva_id) {
                    $success_msg = '¡Reserva completada con éxito!';
                    // Actualizar reservas existentes para la vista
                    $reservas_existentes[] = $horario;
                } else {
                    $error_msg = 'Hubo un error al registrar la reserva. Por favor, intentá nuevamente.';
                }
            } catch (PDOException $e) {
                $error_msg = 'Error al registrar la reserva en el sistema.';
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container my-4">
    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php" class="text-dark">Home</a></li>
            <li class="breadcrumb-item"><a href="canchas.php" class="text-dark">Canchas</a></li>
            <li class="breadcrumb-item active" aria-current="page">Reservar</li>
        </ol>
    </nav>

    <div class="poliba-container-card mt-0 p-4">
        <div class="row align-items-center mb-4 pb-3 border-bottom">
            <div class="col-md-8">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <span class="badge bg-dark rounded-pill px-3 py-2"><?= htmlspecialchars($cancha['polideportivo_nombre']); ?></span>
                    <span class="badge bg-secondary rounded-pill px-3 py-2">
                        <?= $cancha['techado'] ? '<i class="bi bi-house-door-fill me-1"></i> Techada' : '<i class="bi bi-brightness-high-fill me-1"></i> Descubierta'; ?>
                    </span>
                    <span class="badge bg-light text-dark border rounded-pill px-3 py-2">
                        <i class="bi bi-clock me-1 text-primary"></i> <?= date('H:i', strtotime($cancha['horario_apertura'])); ?> a <?= date('H:i', strtotime($cancha['horario_cierre'])); ?> hs
                    </span>
                    <span class="badge bg-light text-dark border rounded-pill px-3 py-2">
                        <i class="bi bi-calendar-week me-1 text-primary"></i> <?= htmlspecialchars($cancha['dia_apertura_nombre'] ?? 'Lunes'); ?> a <?= htmlspecialchars($cancha['dia_cierre_nombre'] ?? 'Sábado'); ?>
                    </span>
                </div>
                <h2 class="fw-bold text-dark m-0"><?= htmlspecialchars($cancha['nombre']); ?></h2>
                <p class="text-muted mt-2 mb-0"><?= htmlspecialchars($cancha['descripcion']); ?></p>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <a href="polideportivo.php?id=<?= $cancha['fk_polideportivo']; ?>" class="poliba-btn-dark btn-sm">Ver Sede</a>
            </div>
        </div>

        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success p-4 mb-4" role="alert" style="border-radius: 12px;">
                <h4 class="alert-heading fw-bold"><i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success_msg); ?></h4>
                <p class="mb-3">Tu turno ha sido registrado para el día <strong><?= $nombre_dia_actual; ?> <?= date('d/m/Y', strtotime($selected_date)); ?></strong> a las <strong><?= date('H:i', strtotime($_POST['horario'])); ?> hs</strong>.</p>
                <hr>
                <p class="mb-2"><strong>¡Invitá a tus amigos!</strong> Compartí el link de reserva para coordinar el partido:</p>
                
                <?php 
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                $share_url = "$protocol://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']) . "/ver_reserva.php?id=$reserva_id";
                ?>
                <div class="input-group mb-3" style="max-width: 500px;">
                    <input type="text" class="form-control" value="<?= $share_url; ?>" id="shareLink" readonly>
                    <button class="poliba-btn-dark px-3" type="button" onclick="navigator.clipboard.writeText(document.getElementById('shareLink').value); alert('¡Link copiado al portapapeles!');">Copiar Link</button>
                </div>
                <div class="mt-3">
                    <a href="perfil.php" class="poliba-btn px-4 py-2">Ir a Mi Perfil</a>
                    <a href="cancha.php?id=<?= $cancha_id; ?>" class="poliba-btn-dark px-4 py-2 ms-2">Hacer otra Reserva</a>
                </div>
            </div>
        <?php else: ?>
            <!-- Formulario de Selección de Fecha -->
            <form action="" method="GET" class="mb-4">
                <input type="hidden" name="id" value="<?= $cancha_id; ?>">
                <div class="row align-items-end g-3">
                    <div class="col-md-5">
                        <label class="form-label fw-bold text-dark">1. Seleccioná la Fecha de Reserva</label>
                        <input type="date" name="fecha" class="form-control rounded-pill px-4" 
                               value="<?= htmlspecialchars($selected_date); ?>" 
                               min="<?= date('Y-m-d'); ?>" 
                               onchange="this.form.submit()">
                    </div>
                    <div class="col-md-4">
                        <span class="d-block text-muted small mb-1">Día correspondiente:</span>
                        <span class="badge <?= $esta_abierto_hoy ? 'bg-primary' : 'bg-danger'; ?> rounded-pill px-3 py-2 fs-6">
                            <?= $nombre_dia_actual; ?> (<?= $esta_abierto_hoy ? 'Abierto' : 'Cerrado'; ?>)
                        </span>
                    </div>
                    <div class="col-md-3 text-md-end">
                        <button type="submit" class="poliba-btn-dark w-100 rounded-pill py-2">Consultar Disponibilidad</button>
                    </div>
                </div>
            </form>

            <?php if (!$esta_abierto_hoy): ?>
                <!-- Notificación de Polideportivo Cerrado -->
                <div class="alert alert-warning d-flex align-items-center mb-4 p-4 rounded-4 shadow-sm" role="alert">
                    <i class="bi bi-exclamation-triangle-fill fs-1 me-3 text-warning"></i>
                    <div>
                        <h5 class="alert-heading fw-bold mb-1">Polideportivo Cerrado</h5>
                        <p class="mb-0">El polideportivo <strong><?= htmlspecialchars($cancha['polideportivo_nombre']); ?></strong> permanece cerrado los días <strong><?= $nombre_dia_actual; ?></strong>. Los días de apertura son de <strong><?= htmlspecialchars($cancha['dia_apertura_nombre'] ?? 'Lunes'); ?></strong> a <strong><?= htmlspecialchars($cancha['dia_cierre_nombre'] ?? 'Sábado'); ?></strong> en el horario de <strong><?= date('H:i', strtotime($cancha['horario_apertura'])); ?> a <?= date('H:i', strtotime($cancha['horario_cierre'])); ?> hs</strong>. Por favor, elegí otra fecha en el calendario.</p>
                    </div>
                </div>
            <?php else: ?>
                <!-- Formulario de Selección de Horarios -->
                <form action="" method="POST">
                    <input type="hidden" name="action" value="reservar">
                    <input type="hidden" name="fecha" value="<?= htmlspecialchars($selected_date); ?>">

                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold text-dark m-0">2. Seleccioná el Horario Disponible</h5>
                        
                        <!-- Barra de Referencias -->
                        <div class="d-flex flex-wrap gap-2 align-items-center small mt-2 mt-md-0">
                            <span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="bi bi-circle-fill me-1" style="font-size: 0.6rem;"></i> Disponible</span>
                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger"><i class="bi bi-x-circle-fill me-1"></i> Reservado</span>
                            <span class="badge bg-warning bg-opacity-15 text-dark border border-warning"><i class="bi bi-calendar-x-fill me-1 text-warning"></i> Clase Deportiva</span>
                            <span class="badge bg-secondary bg-opacity-10 text-muted border"><i class="bi bi-clock-history me-1"></i> Pasado</span>
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <?php 
                        $disponibles_count = 0;
                        foreach ($slots as $time_val => $label): 
                            $slot_start_sec = strtotime("1970-01-01 $time_val");
                            $slot_end_sec = $slot_start_sec + 3600;
                            
                            // 1. ¿Está reservado por un alumno?
                            $is_reserved = in_array($time_val, $reservas_existentes);
                            
                            // 2. ¿Está ocupado por una clase deportiva?
                            $clase_ocupante = null;
                            foreach ($clases_dia as $cl) {
                                $cl_start_sec = strtotime("1970-01-01 " . $cl['horario_inicio']);
                                $cl_end_sec = strtotime("1970-01-01 " . $cl['horario_cierre']);
                                if ($slot_start_sec < $cl_end_sec && $slot_end_sec > $cl_start_sec) {
                                    $clase_ocupante = $cl;
                                    break;
                                }
                            }
                            
                            // 3. ¿Es un horario pasado si la fecha es hoy?
                            $is_past = false;
                            if ($selected_date === date('Y-m-d')) {
                                $current_time = date('H:i:s');
                                if ($time_val <= $current_time) {
                                    $is_past = true;
                                }
                            }
                            
                            $is_available = !$is_reserved && !$clase_ocupante && !$is_past;
                            if ($is_available) $disponibles_count++;
                        ?>
                            <div class="col-sm-6 col-md-4 col-lg-3">
                                <?php if ($is_reserved): ?>
                                    <!-- Slot Ocupado por Reserva de Usuario -->
                                    <div class="border border-danger border-opacity-25 rounded-pill p-2 text-center position-relative d-flex align-items-center justify-content-between h-100 bg-light text-muted px-3" style="min-height: 55px;" title="Horario ya reservado por otro usuario">
                                        <span class="text-decoration-line-through text-muted fw-bold"><?= $label; ?></span>
                                        <span class="badge bg-danger rounded-pill px-2 py-1" style="font-size: 0.72rem;">
                                            <i class="bi bi-x-circle-fill me-1"></i>Ocupado
                                        </span>
                                    </div>
                                <?php elseif ($clase_ocupante): ?>
                                    <!-- Slot Ocupado por Clase Deportiva Programada -->
                                    <div class="border border-warning bg-warning bg-opacity-10 rounded-pill p-2 text-center position-relative d-flex align-items-center justify-content-between h-100 px-3" style="min-height: 55px;" title="Ocupado por Clase: <?= htmlspecialchars($clase_ocupante['nombre']); ?> (<?= date('H:i', strtotime($clase_ocupante['horario_inicio'])); ?> a <?= date('H:i', strtotime($clase_ocupante['horario_cierre'])); ?> hs)">
                                        <span class="text-decoration-line-through text-dark fw-bold"><?= $label; ?></span>
                                        <span class="badge bg-warning text-dark rounded-pill px-2 py-1 text-truncate" style="font-size: 0.72rem; max-width: 110px;" title="<?= htmlspecialchars($clase_ocupante['nombre']); ?>">
                                            <i class="bi bi-calendar-x-fill me-1"></i>Clase
                                        </span>
                                    </div>
                                <?php elseif ($is_past): ?>
                                    <!-- Slot Pasado (Horario no disponible) -->
                                    <div class="border rounded-pill p-2 text-center position-relative d-flex align-items-center justify-content-between h-100 bg-light text-muted px-3 opacity-50" style="min-height: 55px;" title="Horario no disponible (ya transcurrido)">
                                        <span class="text-decoration-line-through text-muted fw-bold"><?= $label; ?></span>
                                        <span class="badge bg-secondary rounded-pill px-2 py-1" style="font-size: 0.72rem;">
                                            <i class="bi bi-clock-history me-1"></i>Pasado
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <!-- Slot Disponible para Reservar -->
                                    <div class="border border-success rounded-pill p-2 text-center position-relative d-flex align-items-center justify-content-center h-100 hover-shadow cursor-pointer bg-white" style="min-height: 55px;">
                                        <input class="form-check-input position-absolute" type="radio" name="horario" 
                                               id="slot_<?= $time_val; ?>" value="<?= $time_val; ?>" required
                                               style="opacity: 0; width: 100%; height: 100%; top: 0; left: 0; cursor: pointer;">
                                        <label class="form-check-label w-100 h-100 d-flex align-items-center justify-content-center fw-bold text-dark cursor-pointer m-0" 
                                               for="slot_<?= $time_val; ?>" 
                                               style="cursor: pointer;">
                                            <i class="bi bi-circle text-success me-2 slot-icon"></i>
                                            <?= $label; ?>
                                        </label>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($disponibles_count === 0): ?>
                        <div class="alert alert-info text-center py-3 my-3">
                            <i class="bi bi-info-circle-fill me-2"></i> No quedan turnos disponibles para esta cancha en la fecha seleccionada. Por favor, elegí otra fecha.
                        </div>
                    <?php endif; ?>

                    <div class="text-center pt-3 border-top">
                        <?php if (is_logged_in() && has_role('Alumno')): ?>
                            <button type="submit" class="poliba-btn px-5 py-2 fw-bold text-uppercase" <?= $disponibles_count === 0 ? 'disabled' : ''; ?>>Confirmar Reserva</button>
                        <?php elseif (!is_logged_in()): ?>
                            <a href="login.php" class="poliba-btn-dark px-5 py-2 fw-bold text-uppercase text-decoration-none">Iniciar sesión para Reservar</a>
                        <?php else: ?>
                            <div class="alert alert-warning d-inline-block">Las reservas de canchas están disponibles únicamente para usuarios catalogados como Alumnos.</div>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
/* CSS adicional local para los slots de horas */
.hover-shadow {
    transition: all 0.25s ease;
}
.hover-shadow:hover {
    background-color: rgba(197, 216, 82, 0.25) !important;
    box-shadow: 0 4px 10px rgba(0,0,0,0.08);
    border-color: var(--poliba-dark-blue) !important;
    transform: translateY(-2px);
}
input[type="radio"]:checked + label {
    color: var(--poliba-dark-blue);
    background-color: var(--poliba-olive);
    border-radius: 30px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
input[type="radio"]:checked + label .slot-icon {
    color: var(--poliba-dark-blue) !important;
}
input[type="radio"]:checked + label .slot-icon::before {
    content: "\F26A" !important; /* icon bi-check-circle-fill */
}
.cursor-pointer {
    cursor: pointer;
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
