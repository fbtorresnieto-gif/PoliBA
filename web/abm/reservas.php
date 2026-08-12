<?php
$base_path = '../';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Validar que sea Administrador
redirect_if_not_logged_in(['Administrador']);

$user = get_logged_user();
$poli_id = $user['fk_polideportivo']; // Sede administrada

if (!$poli_id) {
    die("Error: El administrador no tiene una sede polideportiva asignada.");
}

$error_msg = '';
$success_msg = '';

// Fecha seleccionada (default: hoy)
$selected_date = isset($_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d');

// Cancelar/modificar reserva por el administrador
if (isset($_GET['cancelar_reserva_admin'])) {
    $res_id = intval($_GET['cancelar_reserva_admin']);
    try {
        $stmt = $pdo->prepare("
            UPDATE reservas 
            SET estado = 'cancelado' 
            WHERE id = ? AND fk_cancha IN (SELECT id FROM canchas WHERE fk_polideportivo = ?)
        ");
        if ($stmt->execute([$res_id, $poli_id])) {
            $success_msg = 'Reserva cancelada con éxito.';
        }
    } catch (PDOException $e) {
        $error_msg = 'Error al cancelar la reserva.';
    }
}

// Cargar todas las reservas para la fecha seleccionada en las canchas de esta sede
$reservas = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, c.nombre as cancha_nombre, u.nombre as alu_nombre, u.apellido as alu_apellido, u.dni as alu_dni, u.telefono as alu_telefono
        FROM reservas r
        JOIN canchas c ON r.fk_cancha = c.id
        JOIN usuarios u ON r.fk_usuario = u.id
        WHERE c.fk_polideportivo = ? AND r.fecha_de_asistencia = ?
        ORDER BY r.horario ASC, c.nombre ASC
    ");
    $stmt->execute([$poli_id, $selected_date]);
    $reservas = $stmt->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="mb-4">
        <h2 class="fw-bold text-dark m-0">Reservas de Canchas del Día</h2>
        <small class="text-muted">Administrando Sede: <strong><?= htmlspecialchars($user['fk_polideportivo_nombre'] ?? 'Mi Polideportivo'); ?></strong></small>
    </div>

    <!-- Selector de fecha -->
    <div class="poliba-container-card mt-0 p-4 mb-4">
        <form action="reservas.php" method="GET" class="row align-items-end g-3">
            <div class="col-md-5">
                <label class="form-label fw-bold text-dark">Filtrar por Fecha</label>
                <input type="date" name="fecha" class="form-control rounded-pill px-4" 
                       value="<?= htmlspecialchars($selected_date); ?>" 
                       onchange="this.form.submit()">
            </div>
            <div class="col-md-3">
                <button type="submit" class="poliba-btn-dark w-100 rounded-pill py-2">Actualizar Vista</button>
            </div>
        </form>
    </div>

    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>
    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>

    <!-- Listado de reservas -->
    <div class="table-responsive">
        <table class="table table-poliba table-striped">
            <thead>
                <tr>
                    <th>Horario</th>
                    <th>Cancha</th>
                    <th>Alumno</th>
                    <th>DNI</th>
                    <th>Teléfono</th>
                    <th>Estado</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reservas)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No hay reservas registradas para el día <?= date('d/m/Y', strtotime($selected_date)); ?>.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($reservas as $res): 
                        $is_active = strtolower($res['estado']) == 'reservado';
                    ?>
                        <tr>
                            <td class="fw-bold fs-5 text-dark"><?= date('H:i', strtotime($res['horario'])); ?> hs</td>
                            <td><?= htmlspecialchars($res['cancha_nombre']); ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($res['alu_nombre'] . ' ' . $res['alu_apellido']); ?></td>
                            <td><?= htmlspecialchars($res['alu_dni']); ?></td>
                            <td><?= htmlspecialchars($res['alu_telefono'] ?? '-'); ?></td>
                            <td>
                                <span class="badge rounded-pill <?= $is_active ? 'bg-success' : 'bg-danger'; ?>">
                                    <?= htmlspecialchars(strtoupper($res['estado'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($is_active): ?>
                                    <a href="reservas.php?fecha=<?= $selected_date; ?>&cancelar_reserva_admin=<?= $res['id']; ?>" 
                                       class="btn btn-sm btn-danger rounded-pill px-3"
                                       onclick="return confirm('¿Seguro deseas cancelar esta reserva de cancha?');">
                                        Cancelar
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
