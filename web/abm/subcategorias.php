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

// Procesar Creación / Modificación de Subcategorías
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $nombre = trim($_POST['nombre']);
    $edad_minima = intval($_POST['edad_minima']);
    $edad_maxima = intval($_POST['edad_maxima']);
    $fk_deporte = intval($_POST['fk_deporte']);
    $fk_categoria = !empty($_POST['fk_categoria']) ? intval($_POST['fk_categoria']) : null;
    
    if (empty($nombre) || $edad_minima < 0 || $edad_maxima <= 0 || $fk_deporte <= 0) {
        $error_msg = 'Por favor, completa los campos obligatorios.';
    } elseif ($edad_minima > $edad_maxima) {
        $error_msg = 'La edad mínima no puede ser mayor que la edad máxima.';
    } else {
        if ($_POST['action'] == 'crear') {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO subcategorias (nombre, edad_minima, edad_maxima, fk_deporte, fk_categoria, fk_polideportivo, estado)
                    VALUES (?, ?, ?, ?, ?, ?, TRUE)
                ");
                $stmt->execute([$nombre, $edad_minima, $edad_maxima, $fk_deporte, $fk_categoria, $poli_id]);
                $success_msg = 'Subcategoría creada con éxito.';
            } catch (PDOException $e) {
                $error_msg = 'Error al crear la subcategoría.';
            }
        } elseif ($_POST['action'] == 'editar') {
            $id = intval($_POST['id']);
            try {
                $stmt = $pdo->prepare("
                    UPDATE subcategorias 
                    SET nombre = ?, edad_minima = ?, edad_maxima = ?, fk_deporte = ?, fk_categoria = ? 
                    WHERE id = ? AND fk_polideportivo = ?
                ");
                $stmt->execute([$nombre, $edad_minima, $edad_maxima, $fk_deporte, $fk_categoria, $id, $poli_id]);
                $success_msg = 'Subcategoría modificada con éxito.';
            } catch (PDOException $e) {
                $error_msg = 'Error al modificar la subcategoría.';
            }
        }
    }
}

// Procesar Alta/Baja Lógica
if (isset($_GET['toggle_estado'])) {
    $id = intval($_GET['toggle_estado']);
    $nuevo_estado = $_GET['estado'] == '1' ? 'FALSE' : 'TRUE';
    try {
        $stmt = $pdo->prepare("UPDATE subcategorias SET estado = $nuevo_estado WHERE id = ? AND fk_polideportivo = ?");
        $stmt->execute([$id, $poli_id]);
        $success_msg = 'Estado de la subcategoría actualizado.';
    } catch (PDOException $e) {
        $error_msg = 'Error al actualizar el estado.';
    }
}

// Cargar Subcategorías de la sede
$subcategorias = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.*, d.nombre as deporte_nombre, c.nombre as categoria_nombre 
        FROM subcategorias s
        JOIN deportes d ON s.fk_deporte = d.id
        LEFT JOIN categoria c ON s.fk_categoria = c.id
        WHERE s.fk_polideportivo = ?
        ORDER BY s.id ASC
    ");
    $stmt->execute([$poli_id]);
    $subcategorias = $stmt->fetchAll();
} catch (PDOException $e) {}

// Cargar Deportes para selects
$deportes = [];
try {
    $stmt = $pdo->prepare("SELECT id, nombre FROM deportes WHERE fk_polideportivo = ? AND estado = TRUE ORDER BY nombre ASC");
    $stmt->execute([$poli_id]);
    $deportes = $stmt->fetchAll();
} catch (PDOException $e) {}

// Cargar Categorías generales para selects
$categorias = [];
try {
    $stmt = $pdo->query("SELECT id, nombre FROM categoria ORDER BY id ASC");
    $categorias = $stmt->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark m-0">ABM Subcategorías</h2>
            <small class="text-muted">Administrando Sede: <strong><?= htmlspecialchars($user['fk_polideportivo_nombre'] ?? 'Mi Polideportivo'); ?></strong></small>
        </div>
        <button class="poliba-btn" data-bs-toggle="modal" data-bs-target="#crearSubModal">Agregar Subcategoría</button>
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
                    <th>Deporte</th>
                    <th>Categoría General</th>
                    <th>Rango Edad</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($subcategorias)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted">No hay subcategorías registradas para esta sede.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($subcategorias as $sub): ?>
                        <tr>
                            <td>#<?= $sub['id']; ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($sub['nombre']); ?></td>
                            <td><?= htmlspecialchars($sub['deporte_nombre']); ?></td>
                            <td><?= htmlspecialchars($sub['categoria_nombre'] ?? 'General'); ?></td>
                            <td><?= $sub['edad_minima']; ?> a <?= $sub['edad_maxima']; ?> años</td>
                            <td>
                                <span class="badge rounded-pill <?= $sub['estado'] ? 'bg-success' : 'bg-danger'; ?>">
                                    <?= $sub['estado'] ? 'Activa' : 'Inactiva'; ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-dark rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#editSubModal<?= $sub['id']; ?>">Editar</button>
                                <a href="subcategorias.php?toggle_estado=<?= $sub['id']; ?>&estado=<?= $sub['estado'] ? '1' : '0'; ?>" 
                                   class="btn btn-sm <?= $sub['estado'] ? 'btn-danger' : 'btn-success'; ?> rounded-pill px-3"
                                   onclick="return confirm('¿Seguro deseas cambiar el estado de esta subcategoría?');">
                                    <?= $sub['estado'] ? 'Desactivar' : 'Activar'; ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modales Editar Subcategoría (Fuera de la tabla para Bootstrap 5) -->
<?php foreach ($subcategorias as $sub): ?>
    <div class="modal fade" id="editSubModal<?= $sub['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form action="subcategorias.php" method="POST" class="modal-content border-0 shadow">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="id" value="<?= $sub['id']; ?>">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold">Editar Subcategoría #<?= $sub['id']; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre *</label>
                        <input type="text" name="nombre" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($sub['nombre']); ?>">
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Edad Mínima *</label>
                            <input type="number" name="edad_minima" class="form-control rounded-pill px-3" required value="<?= $sub['edad_minima']; ?>">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Edad Máxima *</label>
                            <input type="number" name="edad_maxima" class="form-control rounded-pill px-3" required value="<?= $sub['edad_maxima']; ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Deporte *</label>
                            <select name="fk_deporte" class="form-select rounded-pill px-3" required>
                                <?php foreach ($deportes as $dep): ?>
                                    <option value="<?= $dep['id']; ?>" <?= $sub['fk_deporte'] == $dep['id'] ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($dep['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Categoría General</label>
                            <select name="fk_categoria" class="form-select rounded-pill px-3">
                                <option value="">-- General --</option>
                                <?php foreach ($categorias as $cat): ?>
                                    <option value="<?= $cat['id']; ?>" <?= $sub['fk_categoria'] == $cat['id'] ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($cat['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
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

<!-- Modal Crear Subcategoría -->
<div class="modal fade" id="crearSubModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="subcategorias.php" method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="crear">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">Agregar Subcategoría</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">Nombre *</label>
                    <input type="text" name="nombre" class="form-control rounded-pill px-3" required placeholder="Vóley Cadetes">
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Edad Mínima *</label>
                        <input type="number" name="edad_minima" class="form-control rounded-pill px-3" required placeholder="13" min="0">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Edad Máxima *</label>
                        <input type="number" name="edad_maxima" class="form-control rounded-pill px-3" required placeholder="17" min="0">
                    </div>
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Deporte *</label>
                        <select name="fk_deporte" class="form-select rounded-pill px-3" required>
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($deportes as $dep): ?>
                                <option value="<?= $dep['id']; ?>"><?= htmlspecialchars($dep['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Categoría General</label>
                        <select name="fk_categoria" class="form-select rounded-pill px-3">
                            <option value="">-- General --</option>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?= $cat['id']; ?>"><?= htmlspecialchars($cat['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                <button type="submit" class="poliba-btn py-2">Crear Subcategoría</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
