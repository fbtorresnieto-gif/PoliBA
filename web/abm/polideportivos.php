<?php
$base_path = '../';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Validar que sea Gestor
redirect_if_not_logged_in(['Gestor']);

$error_msg = '';
$success_msg = '';

// Procesar Alta / Modificación
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $nombre = trim($_POST['nombre']);
    $direccion = trim($_POST['direccion']);
    $horario_apertura = $_POST['horario_apertura'];
    $horario_cierre = $_POST['horario_cierre'];
    $coordenadas = trim($_POST['coordenadas']);
    $informacion = trim($_POST['informacion']);
    $fk_dia_apertura = !empty($_POST['fk_dia_apertura']) ? intval($_POST['fk_dia_apertura']) : null;
    $fk_dia_cierre = !empty($_POST['fk_dia_cierre']) ? intval($_POST['fk_dia_cierre']) : null;
    
    if (empty($nombre) || empty($direccion) || empty($horario_apertura) || empty($horario_cierre)) {
        $error_msg = 'Por favor, completa los campos obligatorios.';
    } else {
        if ($_POST['action'] == 'crear') {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO polideportivos (nombre, direccion, horario_apertura, horario_cierre, coordenadas, informacion, fk_dia_apertura, fk_dia_cierre, estado)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, TRUE)
                ");
                $stmt->execute([$nombre, $direccion, $horario_apertura, $horario_cierre, $coordenadas, $informacion, $fk_dia_apertura, $fk_dia_cierre]);
                $success_msg = 'Polideportivo creado con éxito.';
            } catch (PDOException $e) {
                $error_msg = 'Error al crear el polideportivo.';
            }
        } elseif ($_POST['action'] == 'editar') {
            $id = intval($_POST['id']);
            try {
                $stmt = $pdo->prepare("
                    UPDATE polideportivos 
                    SET nombre = ?, direccion = ?, horario_apertura = ?, horario_cierre = ?, coordenadas = ?, informacion = ?, fk_dia_apertura = ?, fk_dia_cierre = ?
                    WHERE id = ?
                ");
                $stmt->execute([$nombre, $direccion, $horario_apertura, $horario_cierre, $coordenadas, $informacion, $fk_dia_apertura, $fk_dia_cierre, $id]);
                $success_msg = 'Polideportivo modificado con éxito.';
            } catch (PDOException $e) {
                $error_msg = 'Error al actualizar el polideportivo.';
            }
        }
    }
}

// Procesar Baja (Baja Lógica / Estado)
if (isset($_GET['toggle_estado'])) {
    $id = intval($_GET['toggle_estado']);
    $nuevo_estado = $_GET['estado'] == '1' ? 'FALSE' : 'TRUE';
    try {
        $stmt = $pdo->prepare("UPDATE polideportivos SET estado = $nuevo_estado WHERE id = ?");
        $stmt->execute([$id]);
        $success_msg = 'Estado del polideportivo actualizado con éxito.';
    } catch (PDOException $e) {
        $error_msg = 'Error al cambiar el estado.';
    }
}

// Cargar Polideportivos
$polideportivos = [];
try {
    $stmt = $pdo->query("
        SELECT p.*, d1.nombre as dia_apertura_nombre, d2.nombre as dia_cierre_nombre 
        FROM polideportivos p
        LEFT JOIN dias d1 ON p.fk_dia_apertura = d1.id
        LEFT JOIN dias d2 ON p.fk_dia_cierre = d2.id
        ORDER BY p.id ASC
    ");
    $polideportivos = $stmt->fetchAll();
} catch (PDOException $e) {}

// Cargar Dias
$dias = [];
try {
    $stmt = $pdo->query("SELECT * FROM dias ORDER BY orden ASC");
    $dias = $stmt->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-dark m-0">ABM Polideportivos</h2>
        <button class="poliba-btn" data-bs-toggle="modal" data-bs-target="#crearPoliModal">Agregar Polideportivo</button>
    </div>

    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>
    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-poliba table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>Dirección</th>
                    <th>Horario</th>
                    <th>Días</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($polideportivos as $poli): ?>
                    <tr>
                        <td>#<?= $poli['id']; ?></td>
                        <td class="fw-bold"><?= htmlspecialchars($poli['nombre']); ?></td>
                        <td><?= htmlspecialchars($poli['direccion']); ?></td>
                        <td><?= date('H:i', strtotime($poli['horario_apertura'])); ?> - <?= date('H:i', strtotime($poli['horario_cierre'])); ?></td>
                        <td><?= htmlspecialchars($poli['dia_apertura_nombre'] ?? 'Lunes'); ?> a <?= htmlspecialchars($poli['dia_cierre_nombre'] ?? 'Sábado'); ?></td>
                        <td>
                            <span class="badge rounded-pill <?= $poli['estado'] ? 'bg-success' : 'bg-danger'; ?>">
                                <?= $poli['estado'] ? 'Activo' : 'Inactivo'; ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-dark rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#editPoliModal<?= $poli['id']; ?>">Editar</button>
                            <a href="polideportivos.php?toggle_estado=<?= $poli['id']; ?>&estado=<?= $poli['estado'] ? '1' : '0'; ?>" 
                               class="btn btn-sm <?= $poli['estado'] ? 'btn-danger' : 'btn-success'; ?> rounded-pill px-3"
                               onclick="return confirm('¿Seguro deseas cambiar el estado de este polideportivo?');">
                                <?= $poli['estado'] ? 'Desactivar' : 'Activar'; ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modales de Edición (Colocados fuera de la tabla para HTML válido en Bootstrap 5) -->
<?php foreach ($polideportivos as $poli): ?>
    <div class="modal fade" id="editPoliModal<?= $poli['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form action="polideportivos.php" method="POST" class="modal-content border-0 shadow">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="id" value="<?= $poli['id']; ?>">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold">Editar Polideportivo #<?= $poli['id']; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre *</label>
                        <input type="text" name="nombre" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($poli['nombre']); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Dirección *</label>
                        <input type="text" name="direccion" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($poli['direccion']); ?>">
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Hora Apertura *</label>
                            <input type="time" name="horario_apertura" class="form-control rounded-pill px-3" required value="<?= $poli['horario_apertura']; ?>">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Hora Cierre *</label>
                            <input type="time" name="horario_cierre" class="form-control rounded-pill px-3" required value="<?= $poli['horario_cierre']; ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Día Apertura *</label>
                            <select name="fk_dia_apertura" class="form-select rounded-pill px-3">
                                <?php foreach ($dias as $dia): ?>
                                    <option value="<?= $dia['id']; ?>" <?= $poli['fk_dia_apertura'] == $dia['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($dia['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Día Cierre *</label>
                            <select name="fk_dia_cierre" class="form-select rounded-pill px-3">
                                <?php foreach ($dias as $dia): ?>
                                    <option value="<?= $dia['id']; ?>" <?= $poli['fk_dia_cierre'] == $dia['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($dia['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Coordenadas (Lat,Lng)</label>
                        <input type="text" name="coordenadas" class="form-control rounded-pill px-3" placeholder="-34.574,-58.448" value="<?= htmlspecialchars($poli['coordenadas'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Información General</label>
                        <textarea name="informacion" class="form-control px-3" rows="3" style="border-radius:15px;"><?= htmlspecialchars($poli['informacion'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                    <button type="submit" class="poliba-btn py-2">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<!-- Modal Crear Polideportivo -->
<div class="modal fade" id="crearPoliModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="polideportivos.php" method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="crear">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">Agregar Polideportivo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">Nombre *</label>
                    <input type="text" name="nombre" class="form-control rounded-pill px-3" required placeholder="Polideportivo Chacarita">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Dirección *</label>
                    <input type="text" name="direccion" class="form-control rounded-pill px-3" required placeholder="Av. Alvarez Thomas 123">
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Hora Apertura *</label>
                        <input type="time" name="horario_apertura" class="form-control rounded-pill px-3" required value="08:00">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Hora Cierre *</label>
                        <input type="time" name="horario_cierre" class="form-control rounded-pill px-3" required value="22:00">
                    </div>
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Día Apertura *</label>
                        <select name="fk_dia_apertura" class="form-select rounded-pill px-3">
                            <?php foreach ($dias as $dia): ?>
                                <option value="<?= $dia['id']; ?>"><?= htmlspecialchars($dia['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Día Cierre *</label>
                        <select name="fk_dia_cierre" class="form-select rounded-pill px-3">
                            <?php foreach ($dias as $dia): ?>
                                <option value="<?= $dia['id']; ?>" <?= $dia['orden'] == 6 ? 'selected' : ''; ?>><?= htmlspecialchars($dia['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Coordenadas (Lat,Lng)</label>
                    <input type="text" name="coordenadas" class="form-control rounded-pill px-3" placeholder="-34.574,-58.448">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Información General</label>
                    <textarea name="informacion" class="form-control px-3" rows="3" style="border-radius:15px;" placeholder="Descripción corta del polideportivo..."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                <button type="submit" class="poliba-btn py-2">Crear Polideportivo</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
