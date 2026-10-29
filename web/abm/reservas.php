<?php
$base_path = '../';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/pagos/ServicioReservas.php';
require_once __DIR__ . '/../includes/pagos/ConfiguracionEspacio.php';

// Validar que sea Administrador
redirect_if_not_logged_in(['Administrador']);

$user = get_logged_user();
$entidad_id = $user['fk_entidad']; // Entidad administrada

if (!$entidad_id) {
    die("Error: El administrador no tiene una entidad asignada.");
}

$error_msg = '';
$success_msg = '';

// Fecha seleccionada (default: hoy)
$selected_date = isset($_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d');

// Acciones administrativas: actor de sesion, CSRF y autorizacion por entidad en servicio.
$_SESSION['csrf_reservas'] ??= bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token=$_POST['csrf_reservas']??'';
        if(!is_string($token)||!hash_equals($_SESSION['csrf_reservas'],$token))throw new RuntimeException('Recargá la página antes de continuar.');
        $id=filter_var($_POST['reserva_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$id)throw new RuntimeException('Seleccioná una reserva válida.');
        $servicio=new \MultiPro\Pagos\ServicioReservas($pdo);
        switch($_POST['action']??'') {
            case 'registrar_cobro': $servicio->registrarCobro($id,(int)$user['id']);$success_msg='Cobro presencial registrado.';break;
            case 'registrar_devolucion': $servicio->registrarDevolucion($id,(int)$user['id']);$success_msg='Devolución total registrada.';break;
            case 'cancelar':
                $motivo=$_POST['motivo']??'';if(!is_string($motivo))throw new RuntimeException('Indicá un motivo válido.');
                $servicio->cancelarAdministrador($id,(int)$user['id'],$motivo);
                $success_msg='Reserva cancelada. Si estaba cobrada, queda una devolución total pendiente.';break;
            default: throw new RuntimeException('La acción solicitada no es válida.');
        }
        notificacionesDespacharSilencioso($pdo);
    } catch(PDOException $e) { $error_msg='No se pudo registrar la operación. Recargá la página e intentá nuevamente.';
    } catch(RuntimeException $e) { $error_msg=$e->getMessage(); }
}

// Cargar todas las reservas para la fecha seleccionada en los espacios de esta entidad
$reservas = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, e.nombre as espacio_nombre, u.nombre as alu_nombre, u.apellido as alu_apellido, u.dni as alu_dni, u.telefono as alu_telefono,
            EXISTS(SELECT 1 FROM reserva_movimientos_presenciales m WHERE m.fk_reserva=r.id AND m.tipo='cobro') AS presencial_cobrado,
            EXISTS(SELECT 1 FROM reserva_movimientos_presenciales m WHERE m.fk_reserva=r.id AND m.tipo='devolucion_solicitada') AS devolucion_pendiente,
            EXISTS(SELECT 1 FROM reserva_movimientos_presenciales m WHERE m.fk_reserva=r.id AND m.tipo='devolucion') AS devolucion_realizada
        FROM reservas r
        JOIN espacios e ON r.fk_espacio = e.id
        JOIN usuarios u ON r.fk_usuario = u.id
        WHERE e.fk_entidad = ? AND r.fecha_de_asistencia = ?
        ORDER BY r.horario ASC, e.nombre ASC
    ");
    $stmt->execute([$entidad_id, $selected_date]);
    $reservas = $stmt->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="mb-4">
        <h2 class="fw-bold text-dark m-0">Reservas de Espacios del Día</h2>
        <small class="text-muted">Administrando Entidad: <strong><?= htmlspecialchars($user['fk_entidad_nombre'] ?? 'Mi Entidad'); ?></strong></small>
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
                    <th>Espacio</th>
                    <th>Alumno</th>
                    <th>DNI</th>
                    <th>Teléfono</th>
                    <th>Estado</th>
                    <th>Importe y pago</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reservas)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No hay reservas registradas para el día <?= date('d/m/Y', strtotime($selected_date)); ?>.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($reservas as $res): 
                        $is_active = strtolower($res['estado']) == 'reservado';
                    ?>
                        <tr>
                            <td class="fw-bold fs-5 text-dark"><?= date('H:i', strtotime($res['horario'])); ?> hs</td>
                            <td><?= htmlspecialchars($res['espacio_nombre']); ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($res['alu_nombre'] . ' ' . $res['alu_apellido']); ?></td>
                            <td><?= htmlspecialchars($res['alu_dni']); ?></td>
                            <td><?= htmlspecialchars($res['alu_telefono'] ?? '-'); ?></td>
                            <td>
                                <span class="badge rounded-pill <?= $is_active ? 'bg-success' : (str_starts_with($res['estado'],'pendiente') ? 'bg-warning text-dark' : 'bg-danger'); ?>">
                                    <?= htmlspecialchars(\MultiPro\Pagos\ConfiguracionEspacio::estadoReserva($res['estado'])); ?>
                                </span>
                                <?php if ($res['estado']==='cancelado' && !empty($res['motivo_cancelacion'])): ?>
                                    <small class="d-block mt-1">Motivo: <?= htmlspecialchars($res['motivo_cancelacion']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="fw-bold"><?= htmlspecialchars(\MultiPro\Pagos\ConfiguracionEspacio::etiqueta($res['importe_total_ars'])); ?></span>
                                <?php if ($res['modalidad_pago']==='presencial'): ?>
                                    <small class="d-block"><?= $res['presencial_cobrado'] ? 'Cobro presencial registrado' : ($res['estado']==='cancelado' ? 'Sin cobro registrado' : 'Pago presencial pendiente'); ?></small>
                                    <?php if ($res['devolucion_realizada']): ?><small class="d-block">Devolución total registrada</small>
                                    <?php elseif ($res['devolucion_pendiente']): ?><small class="d-block text-warning-emphasis">Devolución total pendiente</small><?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($res['estado']==='pendiente_presencial' && !$res['presencial_cobrado']): ?>
                                    <form method="POST" class="mb-2" onsubmit="return confirm('¿Confirmás que recibiste el importe final de esta reserva?');">
                                        <input type="hidden" name="csrf_reservas" value="<?= htmlspecialchars($_SESSION['csrf_reservas']); ?>">
                                        <input type="hidden" name="reserva_id" value="<?= $res['id']; ?>"><input type="hidden" name="action" value="registrar_cobro">
                                        <button type="submit" class="btn btn-success btn-sm">Registrar cobro presencial</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($res['devolucion_pendiente'] && !$res['devolucion_realizada']): ?>
                                    <form method="POST" class="mb-2" onsubmit="return confirm('¿Confirmás que la entidad ya entregó la devolución total al titular?');">
                                        <input type="hidden" name="csrf_reservas" value="<?= htmlspecialchars($_SESSION['csrf_reservas']); ?>">
                                        <input type="hidden" name="reserva_id" value="<?= $res['id']; ?>"><input type="hidden" name="action" value="registrar_devolucion">
                                        <button type="submit" class="btn btn-success btn-sm">Registrar devolución realizada</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (in_array($res['estado'],['reservado','pendiente_presencial'],true) && $res['modalidad_pago']!=='online'): ?>
                                    <details><summary class="text-danger">Cancelar reserva</summary>
                                        <form method="POST" class="mt-2">
                                            <input type="hidden" name="csrf_reservas" value="<?= htmlspecialchars($_SESSION['csrf_reservas']); ?>">
                                            <input type="hidden" name="reserva_id" value="<?= $res['id']; ?>"><input type="hidden" name="action" value="cancelar">
                                            <label class="small" for="motivo<?= $res['id']; ?>">Motivo obligatorio</label>
                                            <textarea name="motivo" id="motivo<?= $res['id']; ?>" class="form-control form-control-sm mb-2" required maxlength="500" rows="2"></textarea>
                                            <small class="d-block mb-2">Si ya fue cobrada, se generará una devolución total pendiente.</small>
                                            <button type="submit" class="btn btn-danger btn-sm">Confirmar cancelación</button>
                                        </form>
                                    </details>
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
