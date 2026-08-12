<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$reserva_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$reserva = null;

if ($reserva_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, c.nombre as cancha_nombre, p.nombre as polideportivo_nombre, p.direccion as polideportivo_direccion,
                   u.nombre as usuario_nombre, u.apellido as usuario_apellido
            FROM reservas r
            JOIN canchas c ON r.fk_cancha = c.id
            JOIN polideportivos p ON c.fk_polideportivo = p.id
            JOIN usuarios u ON r.fk_usuario = u.id
            WHERE r.id = ?
        ");
        $stmt->execute([$reserva_id]);
        $reserva = $stmt->fetch();
    } catch (PDOException $e) {
        // Log
    }
}

if (!$reserva) {
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="container my-5 text-center"><div class="alert alert-danger">La reserva especificada no existe o ha sido eliminada.</div></div>';
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
                    <small class="text-muted text-uppercase fw-bold">Polideportivo</small>
                    <div class="fs-5 fw-bold text-dark"><?= htmlspecialchars($reserva['polideportivo_nombre']); ?></div>
                    <small class="text-muted"><?= htmlspecialchars($reserva['polideportivo_direccion']); ?></small>
                </div>
                
                <div class="mb-3">
                    <small class="text-muted text-uppercase fw-bold">Cancha</small>
                    <div class="fs-5 fw-bold text-dark"><?= htmlspecialchars($reserva['cancha_nombre']); ?></div>
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
                <a href="cancha.php?id=<?= $reserva['fk_cancha']; ?>" class="poliba-btn fw-bold">Reservar otro turno</a>
                <a href="index.php" class="poliba-btn-dark fw-bold">Volver al Inicio</a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
