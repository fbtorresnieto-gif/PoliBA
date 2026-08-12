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

// Procesar Creación / Modificación de Novedades
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion']);
    $fecha_inicio = $_POST['fecha_inicio'];
    $fecha_fin = $_POST['fecha_fin'];
    $imagenURL = trim($_POST['imagenURL']);
    
    if (empty($nombre) || empty($descripcion) || empty($fecha_inicio) || empty($fecha_fin)) {
        $error_msg = 'Por favor, completa los campos obligatorios.';
    } else {
        if ($_POST['action'] == 'crear') {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO novedades (nombre, descripcion, fecha_inicio, fecha_fin, imagenURL, fk_polideportivo, estado)
                    VALUES (?, ?, ?, ?, ?, ?, TRUE)
                ");
                $stmt->execute([$nombre, $descripcion, $fecha_inicio, $fecha_fin, $imagenURL, $poli_id]);
                $success_msg = 'Novedad publicada con éxito.';
            } catch (PDOException $e) {
                $error_msg = 'Error al crear la novedad.';
            }
        } elseif ($_POST['action'] == 'editar') {
            $id = intval($_POST['id']);
            try {
                $stmt = $pdo->prepare("
                    UPDATE novedades 
                    SET nombre = ?, descripcion = ?, fecha_inicio = ?, fecha_fin = ?, imagenURL = ? 
                    WHERE id = ? AND fk_polideportivo = ?
                ");
                $stmt->execute([$nombre, $descripcion, $fecha_inicio, $fecha_fin, $imagenURL, $id, $poli_id]);
                $success_msg = 'Novedad modificada con éxito.';
            } catch (PDOException $e) {
                $error_msg = 'Error al modificar la novedad.';
            }
        }
    }
}

// Procesar Alta/Baja Lógica
if (isset($_GET['toggle_estado'])) {
    $id = intval($_GET['toggle_estado']);
    $nuevo_estado = $_GET['estado'] == '1' ? 'FALSE' : 'TRUE';
    try {
        $stmt = $pdo->prepare("UPDATE novedades SET estado = $nuevo_estado WHERE id = ? AND fk_polideportivo = ?");
        $stmt->execute([$id, $poli_id]);
        $success_msg = 'Estado de la novedad actualizado.';
    } catch (PDOException $e) {
        $error_msg = 'Error al actualizar el estado.';
    }
}

// Cargar Novedades de la sede
$novedades = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM novedades WHERE fk_polideportivo = ? ORDER BY id ASC");
    $stmt->execute([$poli_id]);
    $novedades = $stmt->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark m-0">ABM Novedades</h2>
            <small class="text-muted">Administrando Sede: <strong><?= htmlspecialchars($user['fk_polideportivo_nombre'] ?? 'Mi Polideportivo'); ?></strong></small>
        </div>
        <button class="poliba-btn" data-bs-toggle="modal" data-bs-target="#crearNovedadModal">Agregar Novedad</button>
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
                    <th>Título</th>
                    <th>Vigencia</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($novedades)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted">No hay novedades registradas para esta sede.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($novedades as $nov): ?>
                        <tr>
                            <td>#<?= $nov['id']; ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($nov['nombre']); ?></td>
                            <td><?= date('d/m/Y', strtotime($nov['fecha_inicio'])); ?> al <?= date('d/m/Y', strtotime($nov['fecha_fin'])); ?></td>
                            <td>
                                <span class="badge rounded-pill <?= $nov['estado'] ? 'bg-success' : 'bg-danger'; ?>">
                                    <?= $nov['estado'] ? 'Activa' : 'Inactiva'; ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-dark rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#editNovedadModal<?= $nov['id']; ?>">Editar</button>
                                <a href="novedades.php?toggle_estado=<?= $nov['id']; ?>&estado=<?= $nov['estado'] ? '1' : '0'; ?>" 
                                   class="btn btn-sm <?= $nov['estado'] ? 'btn-danger' : 'btn-success'; ?> rounded-pill px-3"
                                   onclick="return confirm('¿Seguro deseas cambiar el estado de esta novedad?');">
                                    <?= $nov['estado'] ? 'Desactivar' : 'Activar'; ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modales Editar Novedad (Fuera de la tabla para Bootstrap 5) -->
<?php foreach ($novedades as $nov): ?>
    <div class="modal fade" id="editNovedadModal<?= $nov['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form action="novedades.php" method="POST" class="modal-content border-0 shadow">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="id" value="<?= $nov['id']; ?>">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold">Editar Novedad #<?= $nov['id']; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Título *</label>
                        <input type="text" name="nombre" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($nov['nombre']); ?>">
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Fecha Inicio *</label>
                            <input type="date" name="fecha_inicio" class="form-control rounded-pill px-3" required value="<?= $nov['fecha_inicio']; ?>">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Fecha Fin *</label>
                            <input type="date" name="fecha_fin" class="form-control rounded-pill px-3" required value="<?= $nov['fecha_fin']; ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Imagen URL o Nombre del Archivo</label>
                        <input type="text" name="imagenURL" class="form-control rounded-pill px-3" value="<?= htmlspecialchars($nov['imagenURL'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Descripción *</label>
                        <textarea name="descripcion" class="form-control px-3" rows="4" style="border-radius:15px;" required><?= htmlspecialchars($nov['descripcion'] ?? ''); ?></textarea>
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

<!-- Modal Crear Novedad -->
<div class="modal fade" id="crearNovedadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="novedades.php" method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="crear">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">Agregar Novedad</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">Título *</label>
                    <input type="text" name="nombre" class="form-control rounded-pill px-3" required placeholder="Nuevo Torneo Interno">
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Fecha Inicio *</label>
                        <input type="date" name="fecha_inicio" class="form-control rounded-pill px-3" required value="<?= date('Y-m-d'); ?>">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Fecha Fin *</label>
                        <input type="date" name="fecha_fin" class="form-control rounded-pill px-3" required value="<?= date('Y-m-d', strtotime('+1 week')); ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Imagen URL o Nombre del Archivo</label>
                    <input type="text" name="imagenURL" class="form-control rounded-pill px-3" placeholder="novedad_torneo.jpg">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Descripción *</label>
                    <textarea name="descripcion" class="form-control px-3" rows="4" style="border-radius:15px;" required placeholder="Detalles de la noticia..."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                <button type="submit" class="poliba-btn py-2">Publicar Novedad</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
