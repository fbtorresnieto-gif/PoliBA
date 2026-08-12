<?php
$base_path = '../';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Validar que sea Gestor
redirect_if_not_logged_in(['Gestor']);

$user = get_logged_user();
$error_msg = '';
$success_msg = '';

// Cargar Polideportivos para selects
$polideportivos = [];
try {
    $stmt = $pdo->query("SELECT id, nombre FROM polideportivos WHERE estado = TRUE ORDER BY nombre ASC");
    $polideportivos = $stmt->fetchAll();
} catch (PDOException $e) {}

// Procesar Creación / Modificación de Administrador
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $nombre = trim($_POST['nombre']);
    $apellido = trim($_POST['apellido']);
    $dni = trim($_POST['dni']);
    $direccion = trim($_POST['direccion']);
    $email = trim($_POST['email']);
    $telefono = trim($_POST['telefono']);
    $fecha_nacimiento = $_POST['fecha_nacimiento'];
    $polideportivo_id = intval($_POST['fk_polideportivo']);
    
    if (empty($nombre) || empty($apellido) || empty($dni) || empty($email) || $polideportivo_id <= 0) {
        $error_msg = 'Por favor, completa los campos obligatorios.';
    } else {
        if ($_POST['action'] == 'crear') {
            $contrasena = trim($_POST['contrasena']);
            if (empty($contrasena)) {
                $error_msg = 'La contraseña es obligatoria para nuevos administradores.';
            } else {
                try {
                    $stmt = $pdo->query("SELECT id FROM roles WHERE nombre = 'Administrador' LIMIT 1");
                    $rol = $stmt->fetch();
                    $rol_id = $rol ? $rol['id'] : 2;
                    
                    $hash = password_hash($contrasena, PASSWORD_BCRYPT);
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO usuarios (nombre, apellido, dni, direccion, email, contrasena, telefono, fecha_nacimiento, fk_polideportivo, fk_rol)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$nombre, $apellido, $dni, $direccion, $email, $hash, $telefono, $fecha_nacimiento, $polideportivo_id, $rol_id]);
                    $success_msg = 'Administrador creado con éxito.';
                } catch (PDOException $e) {
                    $error_msg = 'El correo electrónico o DNI ya se encuentra registrado.';
                }
            }
        } elseif ($_POST['action'] == 'editar') {
            $id = intval($_POST['id']);
            $contrasena = trim($_POST['contrasena'] ?? '');
            try {
                if (!empty($contrasena)) {
                    $hash = password_hash($contrasena, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("
                        UPDATE usuarios 
                        SET nombre = ?, apellido = ?, dni = ?, direccion = ?, email = ?, telefono = ?, fecha_nacimiento = ?, fk_polideportivo = ?, contrasena = ?
                        WHERE id = ? AND fk_rol = (SELECT id FROM roles WHERE nombre = 'Administrador')
                    ");
                    $stmt->execute([$nombre, $apellido, $dni, $direccion, $email, $telefono, $fecha_nacimiento, $polideportivo_id, $hash, $id]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE usuarios 
                        SET nombre = ?, apellido = ?, dni = ?, direccion = ?, email = ?, telefono = ?, fecha_nacimiento = ?, fk_polideportivo = ?
                        WHERE id = ? AND fk_rol = (SELECT id FROM roles WHERE nombre = 'Administrador')
                    ");
                    $stmt->execute([$nombre, $apellido, $dni, $direccion, $email, $telefono, $fecha_nacimiento, $polideportivo_id, $id]);
                }
                
                if ($user && $id == $user['id']) {
                    unset($_SESSION['user_data']);
                }
                
                $success_msg = 'Administrador modificado con éxito.';
            } catch (PDOException $e) {
                $error_msg = 'Error al actualizar los datos del administrador.';
            }
        }
    }
}

// Procesar Baja (Eliminar de la DB)
if (isset($_GET['eliminar_admin'])) {
    $id = intval($_GET['eliminar_admin']);
    try {
        $stmt = $pdo->prepare("
            DELETE FROM usuarios 
            WHERE id = ? AND fk_rol = (SELECT id FROM roles WHERE nombre = 'Administrador')
        ");
        $stmt->execute([$id]);
        $success_msg = 'Administrador eliminado con éxito.';
    } catch (PDOException $e) {
        $error_msg = 'Error al eliminar el administrador. Podría tener elementos asociados.';
    }
}

// Cargar Estadísticas por Polideportivo
$stats = [];
try {
    $stmt = $pdo->query("
        SELECT p.id, p.nombre,
               (SELECT COUNT(*) FROM usuarios u WHERE u.fk_polideportivo = p.id AND u.fk_rol = (SELECT id FROM roles WHERE nombre = 'Alumno')) as total_alumnos,
               (SELECT COUNT(*) FROM canchas c WHERE c.fk_polideportivo = p.id AND c.estado = TRUE) as total_canchas,
               (SELECT COUNT(*) FROM deportes d WHERE d.fk_polideportivo = p.id AND d.estado = TRUE) as total_deportes
        FROM polideportivos p
        WHERE p.estado = TRUE
        ORDER BY p.nombre ASC
    ");
    $stats = $stmt->fetchAll();
} catch (PDOException $e) {}

// Cargar Administradores
$administradores = [];
try {
    $stmt = $pdo->query("
        SELECT u.*, p.nombre as polideportivo_nombre 
        FROM usuarios u
        JOIN roles r ON u.fk_rol = r.id
        LEFT JOIN polideportivos p ON u.fk_polideportivo = p.id
        WHERE r.nombre = 'Administrador'
        ORDER BY u.id ASC
    ");
    $administradores = $stmt->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <!-- 1. Estadísticas de los Polideportivos -->
    <h3 class="fw-bold text-dark mb-3"><i class="bi bi-bar-chart-fill text-success me-2"></i>Estadísticas Generales</h3>
    <div class="row g-3 mb-5">
        <?php foreach ($stats as $st): ?>
            <div class="col-md-6">
                <div class="poliba-container-card mt-0 p-4" style="border-left: 6px solid var(--poliba-dark-blue);">
                    <h5 class="fw-bold text-dark mb-3"><?= htmlspecialchars($st['nombre']); ?></h5>
                    <div class="row text-center">
                        <div class="col-4 border-end">
                            <div class="fs-3 fw-bold text-primary"><?= $st['total_alumnos']; ?></div>
                            <small class="text-muted text-uppercase fw-bold">Alumnos</small>
                        </div>
                        <div class="col-4 border-end">
                            <div class="fs-3 fw-bold text-success"><?= $st['total_canchas']; ?></div>
                            <small class="text-muted text-uppercase fw-bold">Canchas</small>
                        </div>
                        <div class="col-4">
                            <div class="fs-3 fw-bold text-info"><?= $st['total_deportes']; ?></div>
                            <small class="text-muted text-uppercase fw-bold">Deportes</small>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- 2. ABM de Administradores -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-dark m-0"><i class="bi bi-shield-lock-fill text-dark me-2"></i>Gestión de Administradores</h3>
        <button class="poliba-btn" data-bs-toggle="modal" data-bs-target="#crearAdminModal">Agregar Administrador</button>
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
                    <th>Nombre Completo</th>
                    <th>DNI</th>
                    <th>Gmail</th>
                    <th>Sede Asignada</th>
                    <th>Teléfono</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($administradores)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted">No hay administradores registrados en el sistema.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($administradores as $adm): ?>
                        <tr>
                            <td>#<?= $adm['id']; ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($adm['nombre'] . ' ' . $adm['apellido']); ?></td>
                            <td><?= htmlspecialchars($adm['dni']); ?></td>
                            <td><?= htmlspecialchars($adm['email']); ?></td>
                            <td>
                                <span class="badge bg-dark rounded-pill px-2 py-1">
                                    <?= htmlspecialchars($adm['polideportivo_nombre'] ?? 'Sin asignar'); ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($adm['telefono'] ?? '-'); ?></td>
                            <td>
                                <button class="btn btn-sm btn-dark rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#editAdminModal<?= $adm['id']; ?>">Editar</button>
                                <a href="administradores.php?eliminar_admin=<?= $adm['id']; ?>" 
                                   class="btn btn-sm btn-danger rounded-pill px-3"
                                   onclick="return confirm('¿Seguro deseas eliminar a este administrador?');">
                                    Eliminar
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modales Editar Administrador (Fuera de la tabla para Bootstrap 5) -->
<?php foreach ($administradores as $adm): ?>
    <div class="modal fade" id="editAdminModal<?= $adm['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form action="administradores.php" method="POST" class="modal-content border-0 shadow">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="id" value="<?= $adm['id']; ?>">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold">Editar Administrador</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Nombre *</label>
                            <input type="text" name="nombre" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($adm['nombre']); ?>">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Apellido *</label>
                            <input type="text" name="apellido" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($adm['apellido']); ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">DNI *</label>
                            <input type="text" name="dni" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($adm['dni']); ?>">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Fecha de Nacimiento</label>
                            <input type="date" name="fecha_nacimiento" class="form-control rounded-pill px-3" value="<?= $adm['fecha_nacimiento']; ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Dirección</label>
                        <input type="text" name="direccion" class="form-control rounded-pill px-3" value="<?= htmlspecialchars($adm['direccion'] ?? ''); ?>">
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Email *</label>
                            <input type="email" name="email" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($adm['email']); ?>">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Teléfono</label>
                            <input type="text" name="telefono" class="form-control rounded-pill px-3" value="<?= htmlspecialchars($adm['telefono'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Sede Asignada *</label>
                        <select name="fk_polideportivo" class="form-select rounded-pill px-3" required>
                            <?php foreach ($polideportivos as $poli): ?>
                                <option value="<?= $poli['id']; ?>" <?= $adm['fk_polideportivo'] == $poli['id'] ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($poli['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nueva Contraseña (Opcional)</label>
                        <input type="password" name="contrasena" class="form-control rounded-pill px-3" placeholder="Dejar en blanco para no cambiar">
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

<!-- Modal Crear Administrador -->
<div class="modal fade" id="crearAdminModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="administradores.php" method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="crear">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">Agregar Administrador</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Nombre *</label>
                        <input type="text" name="nombre" class="form-control rounded-pill px-3" required placeholder="Juan">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Apellido *</label>
                        <input type="text" name="apellido" class="form-control rounded-pill px-3" required placeholder="Gomez">
                    </div>
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">DNI *</label>
                        <input type="text" name="dni" class="form-control rounded-pill px-3" required placeholder="33444555">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Fecha de Nacimiento</label>
                        <input type="date" name="fecha_nacimiento" class="form-control rounded-pill px-3">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Dirección</label>
                    <input type="text" name="direccion" class="form-control rounded-pill px-3" placeholder="Av. Corrientes 123">
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Email *</label>
                        <input type="email" name="email" class="form-control rounded-pill px-3" required placeholder="juan.gomez@poliba.com">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Teléfono</label>
                        <input type="text" name="telefono" class="form-control rounded-pill px-3" placeholder="+54 9 11 ...">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Sede Asignada *</label>
                    <select name="fk_polideportivo" class="form-select rounded-pill px-3" required>
                        <option value="">-- Seleccionar Polideportivo --</option>
                        <?php foreach ($polideportivos as $poli): ?>
                            <option value="<?= $poli['id']; ?>"><?= htmlspecialchars($poli['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Contraseña *</label>
                    <input type="password" name="contrasena" class="form-control rounded-pill px-3" required placeholder="Mínimo 6 caracteres">
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                <button type="submit" class="poliba-btn py-2">Crear Administrador</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
