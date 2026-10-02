<?php
$base_path = '../';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Validar que sea Administrador
redirect_if_not_logged_in(['Administrador']);

$user = get_logged_user();
$poli_id = $user['fk_polideportivo']; // Sede administrada

if (!$poli_id) {
    die("Error: El administrador no tiene una entidad asignada.");
}

$error_msg = '';
$success_msg = '';

// Procesar Creación / Modificación de Deportes
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $nombre = trim($_POST['nombre']);
    $texto = trim($_POST['texto']);
    $imagenURL = trim($_POST['imagenURL']);
    
    if (empty($nombre)) {
        $error_msg = 'El nombre de la actividad es obligatorio.';
    } elseif (mb_strlen($nombre) < 2) {
        $error_msg = 'El nombre de la actividad debe tener al menos 2 caracteres.';
    } elseif (!empty($imagenURL) && (!filter_var($imagenURL, FILTER_VALIDATE_URL) && !preg_match('/^https?:\/\//i', $imagenURL))) {
        $error_msg = 'La URL de la imagen debe ser un enlace válido (ejemplo: https://ejemplo.com/imagen.jpg).';
    } else {
        // Verificar si la entidad está activa
        $stmt_p = $pdo->prepare("SELECT estado, nombre FROM polideportivos WHERE id = ?");
        $stmt_p->execute([$poli_id]);
        $poli_row = $stmt_p->fetch();
        if (!$poli_row || !$poli_row['estado']) {
            $error_msg = 'No es posible gestionar actividades porque la entidad se encuentra desactivada.';
        } else {
            $chk_id = ($_POST['action'] == 'editar') ? intval($_POST['id']) : 0;
            // Validar unicidad dentro de la entidad
            $stmt_chk = $pdo->prepare("SELECT COUNT(*) FROM deportes WHERE LOWER(nombre) = LOWER(?) AND fk_polideportivo = ? AND id != ?");
            $stmt_chk->execute([$nombre, $poli_id, $chk_id]);
            if ($stmt_chk->fetchColumn() > 0) {
                $error_msg = "Ya existe una actividad con el nombre \"$nombre\" en esta entidad.";
            } else {
                if ($_POST['action'] == 'crear') {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO deportes (nombre, texto, imagenURL, fk_polideportivo, estado)
                            VALUES (?, ?, ?, ?, TRUE)
                        ");
                        $stmt->execute([$nombre, $texto, $imagenURL, $poli_id]);
                        $success_msg = 'Actividad creada con éxito.';
                    } catch (PDOException $e) {
                        $error_msg = 'Error al crear la actividad: ' . $e->getMessage();
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
                        $success_msg = 'Actividad modificada con éxito.';
                    } catch (PDOException $e) {
                        $error_msg = 'Error al modificar la actividad.';
                    }
                }
            }
        }
    }
}

// Procesar Alta/Baja Lógica con Cascada
if (isset($_GET['toggle_estado'])) {
    $id = intval($_GET['toggle_estado']);
    $nuevo_estado = $_GET['estado'] == '1' ? 'FALSE' : 'TRUE';
    try {
        if ($nuevo_estado == 'FALSE') {
            // Desactivar actividad y en cascada todos sus módulos
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE deportes SET estado = FALSE WHERE id = ? AND fk_polideportivo = ?");
            $stmt->execute([$id, $poli_id]);

            $stmt_c = $pdo->prepare("UPDATE clases SET estado = FALSE WHERE fk_deporte = ? AND fk_polideportivo = ?");
            $stmt_c->execute([$id, $poli_id]);

            $pdo->commit();
            $success_msg = 'Actividad desactivada. Los módulos asociados fueron desactivados automáticamente.';
        } else {
            // Verificar si la entidad está activa
            $stmt_p = $pdo->prepare("SELECT estado, nombre FROM polideportivos WHERE id = ?");
            $stmt_p->execute([$poli_id]);
            $poli_row = $stmt_p->fetch();
            if (!$poli_row || !$poli_row['estado']) {
                $error_msg = 'No se puede activar la actividad porque la entidad a la que pertenece se encuentra desactivada.';
            } else {
                $stmt = $pdo->prepare("UPDATE deportes SET estado = TRUE WHERE id = ? AND fk_polideportivo = ?");
                $stmt->execute([$id, $poli_id]);
                $success_msg = 'Actividad activada con éxito.';
            }
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_msg = 'Error al actualizar el estado de la actividad.';
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
            <h2 class="fw-bold text-dark m-0">ABM Actividades</h2>
            <small class="text-muted">Administrando Entidad: <strong><?= htmlspecialchars($user['fk_polideportivo_nombre'] ?? 'Mi Entidad'); ?></strong></small>
        </div>
        <button class="poliba-btn" data-bs-toggle="modal" data-bs-target="#crearDeporteModal">Agregar Actividad</button>
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
                    <th>Nombre</th>
                    <th>Descripción</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($deportes)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted">No hay actividades registradas para esta entidad.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($deportes as $dep): ?>
                        <tr>
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
                                   onclick="return confirm('¿Seguro deseas cambiar el estado de esta actividad? Si la desactivas, todos sus módulos asociados se desactivarán automáticamente.');">
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

<!-- Modales Editar Actividad (Fuera de la tabla para Bootstrap 5) -->
<?php foreach ($deportes as $dep): ?>
    <div class="modal fade" id="editDeporteModal<?= $dep['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form action="deportes.php" method="POST" class="modal-content border-0 shadow">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="id" value="<?= $dep['id']; ?>">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold">Editar Actividad: <?= htmlspecialchars($dep['nombre']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre de la Actividad *</label>
                        <input type="text" name="nombre" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($dep['nombre']); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Imagen URL</label>
                        <input type="url" name="imagenURL" class="form-control rounded-pill px-3" placeholder="https://ejemplo.com/voley.jpg" value="<?= htmlspecialchars($dep['imagenurl'] ?? $dep['imagenURL'] ?? ''); ?>">
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

<!-- Modal Crear Actividad -->
<div class="modal fade" id="crearDeporteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="deportes.php" method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="crear">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">Agregar Actividad</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">Nombre de la Actividad *</label>
                    <input type="text" name="nombre" class="form-control rounded-pill px-3" required placeholder="Vóley">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Imagen URL</label>
                    <input type="url" name="imagenURL" class="form-control rounded-pill px-3" placeholder="https://ejemplo.com/voley.jpg">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Descripción / Información</label>
                    <textarea name="texto" class="form-control px-3" rows="4" style="border-radius:15px;" placeholder="Detalles de la disciplina..."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                <button type="submit" class="poliba-btn py-2">Crear Actividad</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
