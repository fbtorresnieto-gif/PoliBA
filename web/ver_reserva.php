<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$token = isset($_GET['t']) ? trim($_GET['t']) : '';
$reserva = null;

// El link se identifica con un token secreto (no con el ID, que es adivinable)
if (preg_match('/^[a-f0-9]{32}$/', $token)) {
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, esp.nombre as espacio_nombre, e.nombre as entidad_nombre, e.direccion as entidad_direccion,
                   u.nombre as usuario_nombre, u.apellido as usuario_apellido
            FROM reservas r
            JOIN espacios esp ON r.fk_espacio = esp.id
            JOIN entidades e ON esp.fk_entidad = e.id
            JOIN usuarios u ON r.fk_usuario = u.id
            WHERE r.token_compartir = ?
        ");
        $stmt->execute([$token]);
        $reserva = $stmt->fetch();
    } catch (PDOException $e) {
        // Log
    }
}

// Si no existe, está cancelada o ya pasó el horario de inicio, no se muestra ningún dato
$disponible = false;
if ($reserva && strtolower($reserva['estado']) === 'reservado') {
    $inicio = strtotime($reserva['fecha_de_asistencia'] . ' ' . $reserva['horario']);
    $disponible = $inicio !== false && $inicio > time();
}

if (!$disponible) {
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="container my-5 text-center"><div class="alert alert-warning">Esta reserva ya no está disponible.</div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center my-5">
    <div class="col-md-7 col-lg-6">
        <div class="poliba-container-card text-center border-success border border-2">
            <i class="bi bi-calendar2-check-fill text-success fs-1 mb-3"></i>
            <h2 class="fw-bold mb-4" style="color: var(--poliba-dark-blue);">Detalle de Reserva</h2>
            
            <div class="text-start bg-light p-4 rounded-3 mb-4">
                <div class="mb-3">
                    <small class="text-muted text-uppercase fw-bold">Entidad</small>
                    <div class="fs-5 fw-bold text-dark"><?= htmlspecialchars($reserva['entidad_nombre']); ?></div>
                    <small class="text-muted"><?= htmlspecialchars($reserva['entidad_direccion']); ?></small>
                </div>
                
                <div class="mb-3">
                    <small class="text-muted text-uppercase fw-bold">Espacio</small>
                    <div class="fs-5 fw-bold text-dark"><?= htmlspecialchars($reserva['espacio_nombre']); ?></div>
                </div>

                <div class="row mb-3">
                    <div class="col-6">
                        <small class="text-muted text-uppercase fw-bold">Fecha</small>
                        <div class="fs-5 fw-bold text-dark"><?= date('d/m/Y', strtotime($reserva['fecha_de_asistencia'])); ?></div>
                    </div>
                    <div class="col-6">
                        <small class="text-muted text-uppercase fw-bold">Horario</small>
                        <div class="fs-5 fw-bold text-dark"><?= date('H:i', strtotime($reserva['horario'])); ?> hs</div>
                    </div>
                </div>

                <div class="mb-3">
                    <small class="text-muted text-uppercase fw-bold">Organizador / Reservado por</small>
                    <div class="fs-6 fw-bold text-dark"><?= htmlspecialchars($reserva['usuario_nombre'] . ' ' . $reserva['usuario_apellido']); ?></div>
                </div>

                <div>
                    <small class="text-muted text-uppercase fw-bold d-block mb-1">Estado</small>
                    <span class="badge rounded-pill px-3 py-2 <?= strtolower($reserva['estado']) == 'reservado' ? 'bg-success' : 'bg-danger'; ?>">
                        <?= htmlspecialchars(strtoupper($reserva['estado'])); ?>
                    </span>
                </div>
            </div>
            
            <div class="d-grid gap-2">
                <a href="espacio.php?id=<?= $reserva['fk_espacio']; ?>" class="poliba-btn fw-bold">Reservar otro espacio</a>
                <a href="index.php" class="poliba-btn-dark fw-bold">Volver al Inicio</a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
