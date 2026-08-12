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
            SELECT c.*, p.nombre as polideportivo_nombre 
            FROM canchas c
            JOIN polideportivos p ON c.fk_polideportivo = p.id
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

// Listado de slots de horas estándar de reserva
$slots = [
    '09:00:00' => '09:00 hs',
    '10:00:00' => '10:00 hs',
    '11:00:00' => '11:00 hs',
    '12:00:00' => '12:00 hs',
    '13:00:00' => '13:00 hs',
    '14:00:00' => '14:00 hs',
    '15:00:00' => '15:00 hs',
    '16:00:00' => '16:00 hs',
    '17:00:00' => '17:00 hs',
    '18:00:00' => '18:00 hs',
    '19:00:00' => '19:00 hs',
    '20:00:00' => '20:00 hs',
    '21:00:00' => '21:00 hs'
];

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

// Procesar la solicitud de reserva (Solo Alumnos)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'reservar') {
    redirect_if_not_logged_in(['Alumno']);
    
    $user = get_logged_user();
    $fecha = $_POST['fecha'];
    $horario = $_POST['horario'];
    
    if (empty($fecha) || empty($horario)) {
        $error_msg = 'Por favor, selecciona una fecha y un horario válidos.';
    } elseif (in_array($horario, $reservas_existentes)) {
        $error_msg = 'El horario seleccionado ya se encuentra reservado.';
    } elseif (strtotime($fecha) < strtotime(date('Y-m-d'))) {
        $error_msg = 'No puedes realizar reservas para fechas pasadas.';
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
                $error_msg = 'Hubo un error al realizar la reserva. Por favor, intenta nuevamente.';
            }
        } catch (PDOException $e) {
            $error_msg = 'Error al registrar la reserva en el sistema.';
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
        <div class="row align-items-center mb-4">
            <div class="col-md-8">
                <span class="badge bg-dark rounded-pill px-3 py-2 mb-2"><?= htmlspecialchars($cancha['polideportivo_nombre']); ?></span>
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
                <p class="mb-3">Tu turno ha sido registrado para el día <strong><?= date('d/m/Y', strtotime($selected_date)); ?></strong> a las <strong><?= date('H:i', strtotime($_POST['horario'])); ?> hs</strong>.</p>
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
                </div>
            </div>
        <?php else: ?>
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
                    <div class="col-md-3">
                        <button type="submit" class="poliba-btn-dark w-100 rounded-pill py-2">Actualizar Fecha</button>
                    </div>
                </div>
            </form>

            <form action="" method="POST">
                <input type="hidden" name="action" value="reservar">
                <input type="hidden" name="fecha" value="<?= htmlspecialchars($selected_date); ?>">

                <h5 class="fw-bold text-dark mb-3">2. Seleccioná el Horario Disponible</h5>
                
                <div class="row g-3 mb-4">
                    <?php foreach ($slots as $time_val => $label): 
                        $is_reserved = in_array($time_val, $reservas_existentes);
                    ?>
                        <div class="col-sm-6 col-md-4 col-lg-3">
                            <div class="border rounded-pill p-2 text-center position-relative d-flex align-items-center justify-content-center h-100 <?= $is_reserved ? 'bg-light text-muted' : 'border-success hover-shadow'; ?>" style="min-height: 55px;">
                                <input class="form-check-input position-absolute" type="radio" name="horario" 
                                       id="slot_<?= $time_val; ?>" value="<?= $time_val; ?>" required
                                       <?= $is_reserved ? 'disabled' : ''; ?> 
                                       style="opacity: 0; width: 100%; height: 100%; top: 0; left: 0; cursor: <?= $is_reserved ? 'not-allowed' : 'pointer'; ?>;">
                                <label class="form-check-label w-100 h-100 d-flex align-items-center justify-content-center fw-bold" 
                                       for="slot_<?= $time_val; ?>" 
                                       style="cursor: <?= $is_reserved ? 'not-allowed' : 'pointer'; ?>;">
                                    <?php if ($is_reserved): ?>
                                        <i class="bi bi-x-circle-fill text-danger me-2"></i>
                                        <del><?= $label; ?></del>
                                    <?php else: ?>
                                        <i class="bi bi-circle text-success me-2 slot-icon"></i>
                                        <?= $label; ?>
                                    <?php endif; ?>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="text-center pt-3 border-top">
                    <?php if (is_logged_in() && has_role('Alumno')): ?>
                        <button type="submit" class="poliba-btn px-5 py-2 fw-bold text-uppercase">Confirmar Reserva</button>
                    <?php elseif (!is_logged_in()): ?>
                        <a href="login.php" class="poliba-btn-dark px-5 py-2 fw-bold text-uppercase text-decoration-none">Iniciar sesión para Reservar</a>
                    <?php else: ?>
                        <div class="alert alert-warning d-inline-block">Las reservas de canchas están disponibles únicamente para usuarios catalogados como Alumnos.</div>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<style>
/* CSS adicional local para los slots de horas */
.hover-shadow:hover {
    background-color: rgba(197, 216, 82, 0.2);
    box-shadow: 0 4px 8px rgba(0,0,0,0.05);
    border-color: var(--poliba-dark-blue) !important;
}
input[type="radio"]:checked + label {
    color: var(--poliba-dark-blue);
}
input[type="radio"]:checked + label .slot-icon {
    content: "\F26A"; /* icon bi-check-circle-fill */
    font-family: "bootstrap-icons";
    color: var(--poliba-dark-blue) !important;
}
input[type="radio"]:checked + label .slot-icon::before {
    content: "\F26A";
}
/* Al seleccionar, marcar el contenedor visualmente */
input[type="radio"]:checked + label {
    background-color: var(--poliba-olive);
    border-radius: 30px;
}
</style>
