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

// Procesar Creación / Modificación de Deportes
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $nombre = trim($_POST['nombre']);
    $texto = trim($_POST['texto']);
    $imagenURL = trim($_POST['imagenURL']);
    
    if (empty($nombre)) {
        $error_msg = 'El nombre del deporte es obligatorio.';
    } else {
        if ($_POST['action'] == 'crear') {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO deportes (nombre, texto, imagenURL, fk_polideportivo, estado)
                    VALUES (?, ?, ?, ?, TRUE)
                ");
                $stmt->execute([$nombre, $texto, $imagenURL, $poli_id]);
                $success_msg = 'Deporte creado con éxito.';
            } catch (PDOException $e) {
                $error_msg = 'Error al crear el deporte.';
            }
        } elseif ($_POST['action'] == 'editar') {
            $id = intval($_POST['id']);
            try {
                $stmt = $pdo->prepare("
                    UPDATE deportes 
                    SET nombre = ?, texto = ?, imagenURL = ? 
                    WHERE id = ? AND fk_polideportivo = ?
                ");
                $stmt->execute([$nombre, $texto, $imagenURL, $id, $poli_id]);
                $success_msg = 'Deporte modificado con éxito.';
            } catch (PDOException $e) {
                $error_msg = 'Error al modificar el deporte.';
            }
        }
    }
}

// Procesar Alta/Baja Lógica
if (isset($_GET['toggle_estado'])) {
    $id = intval($_GET['toggle_estado']);
    $nuevo_estado = $_GET['estado'] == '1' ? 'FALSE' : 'TRUE';
    try {
        $stmt = $pdo->prepare("UPDATE deportes SET estado = $nuevo_estado WHERE id = ? AND fk_polideportivo = ?");
        $stmt->execute([$id, $poli_id]);
        $success_msg = 'Estado del deporte actualizado.';
    } catch (PDOException $e) {
        $error_msg = 'Error al actualizar el estado.';
    }
}

// Cargar Deportes de la sede
$deportes = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM deportes WHERE fk_polideportivo = ? ORDER BY id ASC");
    $stmt->execute([$poli_id]);
    $deportes = $stmt->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark m-0">ABM Deportes</h2>
            <small class="text-muted">Administrando Sede: <strong><?= htmlspecialchars($user['fk_polideportivo_nombre'] ?? 'Mi Polideportivo'); ?></strong></small>
        </div>
        <button class="poliba-btn" data-bs-toggle="modal" data-bs-target="#crearDeporteModal">Agregar Deporte</button>
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
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($deportes)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted">No hay deportes registrados para esta sede.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($deportes as $dep): ?>
                        <tr>
                            <td>#<?= $dep['id']; ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($dep['nombre']); ?></td>
                            <td><?= htmlspecialchars(substr($dep['texto'], 0, 150)) . (strlen($dep['texto']) > 150 ? '...' : ''); ?></td>
                            <td>
                                <span class="badge rounded-pill <?= $dep['estado'] ? 'bg-success' : 'bg-danger'; ?>">
                                    <?= $dep['estado'] ? 'Activo' : 'Inactivo'; ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-dark rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#editDeporteModal<?= $dep['id']; ?>">Editar</button>
                                <a href="deportes.php?toggle_estado=<?= $dep['id']; ?>&estado=<?= $dep['estado'] ? '1' : '0'; ?>" 
                                   class="btn btn-sm <?= $dep['estado'] ? 'btn-danger' : 'btn-success'; ?> rounded-pill px-3"
                                   onclick="return confirm('¿Seguro deseas cambiar el estado de este deporte?');">
                                    <?= $dep['estado'] ? 'Desactivar' : 'Activar'; ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modales Editar Deporte (Fuera de la tabla para Bootstrap 5) -->
<?php foreach ($deportes as $dep): ?>
    <div class="modal fade" id="editDeporteModal<?= $dep['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form action="deportes.php" method="POST" class="modal-content border-0 shadow">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="id" value="<?= $dep['id']; ?>">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold">Editar Deporte #<?= $dep['id']; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre del Deporte *</label>
                        <input type="text" name="nombre" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($dep['nombre']); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Imagen URL o Nombre del Archivo</label>
                        <input type="text" name="imagenURL" class="form-control rounded-pill px-3" value="<?= htmlspecialchars($dep['imagenURL'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Descripción / Información</label>
                        <textarea name="texto" class="form-control px-3" rows="4" style="border-radius:15px;"><?= htmlspecialchars($dep['texto'] ?? ''); ?></textarea>
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

<!-- Modal Crear Deporte -->
<div class="modal fade" id="crearDeporteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="deportes.php" method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="crear">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">Agregar Deporte</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">Nombre del Deporte *</label>
                    <input type="text" name="nombre" class="form-control rounded-pill px-3" required placeholder="Vóley">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Imagen URL o Nombre del Archivo</label>
                    <input type="text" name="imagenURL" class="form-control rounded-pill px-3" placeholder="voley.jpg">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Descripción / Información</label>
                    <textarea name="texto" class="form-control px-3" rows="4" style="border-radius:15px;" placeholder="Detalles de la disciplina..."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                <button type="submit" class="poliba-btn py-2">Crear Deporte</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
