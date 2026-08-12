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

// Procesar Creación / Modificación de Canchas
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion']);
    $imagenURL = trim($_POST['imagenURL']);
    $techado = isset($_POST['techado']) ? 1 : 0;
    
    if (empty($nombre)) {
        $error_msg = 'El nombre de la cancha es obligatorio.';
    } else {
        if ($_POST['action'] == 'crear') {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO canchas (nombre, descripcion, imagenURL, techado, fk_polideportivo, estado)
                    VALUES (?, ?, ?, ?, ?, TRUE)
                ");
                $stmt->execute([$nombre, $descripcion, $imagenURL, $techado, $poli_id]);
                $success_msg = 'Cancha creada con éxito.';
            } catch (PDOException $e) {
                $error_msg = 'Error al crear la cancha.';
            }
        } elseif ($_POST['action'] == 'editar') {
            $id = intval($_POST['id']);
            try {
                $stmt = $pdo->prepare("
                    UPDATE canchas 
                    SET nombre = ?, descripcion = ?, imagenURL = ?, techado = ? 
                    WHERE id = ? AND fk_polideportivo = ?
                ");
                $stmt->execute([$nombre, $descripcion, $imagenURL, $techado, $id, $poli_id]);
                $success_msg = 'Cancha modificada con éxito.';
            } catch (PDOException $e) {
                $error_msg = 'Error al modificar la cancha.';
            }
        }
    }
}

// Procesar Alta/Baja Lógica
if (isset($_GET['toggle_estado'])) {
    $id = intval($_GET['toggle_estado']);
    $nuevo_estado = $_GET['estado'] == '1' ? 'FALSE' : 'TRUE';
    try {
        $stmt = $pdo->prepare("UPDATE canchas SET estado = $nuevo_estado WHERE id = ? AND fk_polideportivo = ?");
        $stmt->execute([$id, $poli_id]);
        $success_msg = 'Estado de la cancha actualizado.';
    } catch (PDOException $e) {
        $error_msg = 'Error al actualizar el estado.';
    }
}

// Cargar Canchas de la sede
$canchas = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM canchas WHERE fk_polideportivo = ? ORDER BY id ASC");
    $stmt->execute([$poli_id]);
    $canchas = $stmt->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark m-0">ABM Canchas</h2>
            <small class="text-muted">Administrando Sede: <strong><?= htmlspecialchars($user['fk_polideportivo_nombre'] ?? 'Mi Polideportivo'); ?></strong></small>
        </div>
        <button class="poliba-btn" data-bs-toggle="modal" data-bs-target="#crearCanchaModal">Agregar Cancha</button>
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
                    <th>Descripción</th>
                    <th>Tipo</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($canchas)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">No hay canchas registradas para esta sede.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($canchas as $can): ?>
                        <tr>
                            <td>#<?= $can['id']; ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($can['nombre']); ?></td>
                            <td><?= htmlspecialchars($can['descripcion']); ?></td>
                            <td>
                                <span class="badge rounded-pill <?= $can['techado'] ? 'bg-dark' : 'bg-secondary'; ?>">
                                    <?= $can['techado'] ? 'Techada' : 'Descubierta'; ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge rounded-pill <?= $can['estado'] ? 'bg-success' : 'bg-danger'; ?>">
                                    <?= $can['estado'] ? 'Activa' : 'Inactiva'; ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-dark rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#editCanchaModal<?= $can['id']; ?>">Editar</button>
                                <a href="canchas.php?toggle_estado=<?= $can['id']; ?>&estado=<?= $can['estado'] ? '1' : '0'; ?>" 
                                   class="btn btn-sm <?= $can['estado'] ? 'btn-danger' : 'btn-success'; ?> rounded-pill px-3"
                                   onclick="return confirm('¿Seguro deseas cambiar el estado de esta cancha?');">
                                    <?= $can['estado'] ? 'Desactivar' : 'Activar'; ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modales Editar Cancha (Fuera de la tabla para Bootstrap 5) -->
<?php foreach ($canchas as $can): ?>
    <div class="modal fade" id="editCanchaModal<?= $can['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form action="canchas.php" method="POST" class="modal-content border-0 shadow">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="id" value="<?= $can['id']; ?>">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold">Editar Cancha #<?= $can['id']; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre de la Cancha *</label>
                        <input type="text" name="nombre" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($can['nombre']); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Imagen URL o Nombre del Archivo</label>
                        <input type="text" name="imagenURL" class="form-control rounded-pill px-3" value="<?= htmlspecialchars($can['imagenURL'] ?? ''); ?>">
                    </div>
                    <div class="mb-3 form-check form-switch ms-1">
                        <input class="form-check-input" type="checkbox" name="techado" id="editTechado<?= $can['id']; ?>" <?= $can['techado'] ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold" for="editTechado<?= $can['id']; ?>">Cancha Techada</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Descripción / Ubicación</label>
                        <textarea name="descripcion" class="form-control px-3" rows="3" style="border-radius:15px;"><?= htmlspecialchars($can['descripcion'] ?? ''); ?></textarea>
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

<!-- Modal Crear Cancha -->
<div class="modal fade" id="crearCanchaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="canchas.php" method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="crear">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">Agregar Cancha</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">Nombre de la Cancha *</label>
                    <input type="text" name="nombre" class="form-control rounded-pill px-3" required placeholder="Cancha de Tenis 2">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Imagen URL o Nombre del Archivo</label>
                    <input type="text" name="imagenURL" class="form-control rounded-pill px-3" placeholder="cancha_tenis.jpg">
                </div>
                <div class="mb-3 form-check form-switch ms-1">
                    <input class="form-check-input" type="checkbox" name="techado" id="crearTechado">
                    <label class="form-check-label fw-bold" for="crearTechado">Cancha Techada</label>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Descripción / Ubicación</label>
                    <textarea name="descripcion" class="form-control px-3" rows="3" style="border-radius:15px;" placeholder="Detalles de superficie, iluminación, etc..."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                <button type="submit" class="poliba-btn py-2">Crear Cancha</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
