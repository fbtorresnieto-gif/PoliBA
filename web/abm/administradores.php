<?php
$base_path = '../';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Validar que sea Gestor
redirect_if_not_logged_in(['Gestor']);

$user = get_logged_user();
$error_msg = '';
$success_msg = '';

// Cargar Entidades para selects
$entidades = [];
try {
    $stmt = $pdo->query("SELECT id, nombre FROM entidades WHERE estado = TRUE ORDER BY nombre ASC");
    $entidades = $stmt->fetchAll();
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
    $entidad_id = intval($_POST['fk_entidad']);
    
    if (empty($nombre) || empty($apellido) || empty($dni) || empty($email) || empty($fecha_nacimiento) || $entidad_id <= 0) {
        $error_msg = 'Por favor, completa los campos obligatorios.';
    } elseif (!ctype_digit($dni)) {
        $error_msg = 'El DNI debe contener únicamente números.';
    } elseif (!empty($fecha_nacimiento) && date_diff(date_create($fecha_nacimiento), date_create('today'))->y < 21) {
        $error_msg = 'El administrador debe tener al menos 21 años de edad.';
    } elseif (!empty($telefono) && !preg_match('/^\+?[0-9]+$/', $telefono)) {
        $error_msg = 'El teléfono solo puede contener números y opcionalmente el símbolo + al inicio.';
    } else {
        if ($_POST['action'] == 'crear') {
            $contrasena = trim($_POST['contrasena']);
            if (empty($contrasena)) {
                $error_msg = 'La contraseña es obligatoria para nuevos administradores.';
            } elseif (strlen($contrasena) < 6) {
                $error_msg = 'La contraseña debe tener al menos 6 caracteres.';
            } else {
                try {
                    $stmt = $pdo->query("SELECT id FROM roles WHERE nombre = 'Administrador' LIMIT 1");
                    $rol = $stmt->fetch();
                    $rol_id = $rol ? $rol['id'] : 2;
                    
                    $hash = password_hash($contrasena, PASSWORD_BCRYPT);
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO usuarios (nombre, apellido, dni, direccion, email, contrasena, telefono, fecha_nacimiento, fk_entidad, fk_rol)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$nombre, $apellido, $dni, $direccion, $email, $hash, $telefono, $fecha_nacimiento, $entidad_id, $rol_id]);
                    $success_msg = 'Administrador creado con éxito.';
                } catch (PDOException $e) {
                    $error_msg = 'El correo electrónico o DNI ya se encuentra registrado.';
                }
            }
        }
    }
}

// Procesar Baja / Alta lógica (cambia el estado, no borra de la DB)
if (isset($_GET['cambiar_estado_admin'])) {
    $id = intval($_GET['cambiar_estado_admin']);
    $activar = (($_GET['estado'] ?? '') === '0'); // si estaba inactivo (0), se activa
    try {
        $stmt = $pdo->prepare("
            UPDATE usuarios SET estado = ?
            WHERE id = ? AND fk_rol = (SELECT id FROM roles WHERE nombre = 'Administrador')
        ");
        $stmt->bindValue(1, $activar, PDO::PARAM_BOOL);
        $stmt->bindValue(2, $id, PDO::PARAM_INT);
        $stmt->execute();
        $success_msg = $activar ? 'Administrador activado con éxito.' : 'Administrador desactivado con éxito.';
    } catch (PDOException $e) {
        $error_msg = 'Error al cambiar el estado del administrador.';
    }
}

// Cargar Administradores
$administradores = [];
try {
    $stmt = $pdo->query("
        SELECT u.*, e.nombre as entidad_nombre
        FROM usuarios u
        JOIN roles r ON u.fk_rol = r.id
        LEFT JOIN entidades e ON u.fk_entidad = e.id
        WHERE r.nombre = 'Administrador'
        ORDER BY u.id ASC
    ");
    $administradores = $stmt->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <!-- ABM de Administradores -->
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
                    <th>Nombre Completo</th>
                    <th>DNI</th>
                    <th>Gmail</th>
                    <th>Entidad Asignada</th>
                    <th>Teléfono</th>
                    <th>Estado</th>
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
                            <td class="fw-bold"><?= htmlspecialchars($adm['nombre'] . ' ' . $adm['apellido']); ?></td>
                            <td><?= htmlspecialchars($adm['dni']); ?></td>
                            <td><?= htmlspecialchars($adm['email']); ?></td>
                            <td>
                                <span class="badge bg-dark rounded-pill px-2 py-1">
                                    <?= htmlspecialchars($adm['entidad_nombre'] ?? 'Sin asignar'); ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($adm['telefono'] ?? '-'); ?></td>
                            <td>
                                <span class="badge rounded-pill <?= $adm['estado'] ? 'bg-success' : 'bg-danger'; ?>">
                                    <?= $adm['estado'] ? 'Activo' : 'Inactivo'; ?>
                                </span>
                            </td>
                            <td>
                                <a href="administradores.php?cambiar_estado_admin=<?= $adm['id']; ?>&estado=<?= $adm['estado'] ? '1' : '0'; ?>"
                                   class="btn btn-sm <?= $adm['estado'] ? 'btn-danger' : 'btn-success'; ?> rounded-pill px-3"
                                   onclick="return confirm('<?= $adm['estado'] ? '¿Seguro deseas desactivar a este administrador? No podrá iniciar sesión hasta que lo reactives.' : '¿Seguro deseas reactivar a este administrador?'; ?>');">
                                    <?= $adm['estado'] ? 'Desactivar' : 'Activar'; ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

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
                        <input type="text" name="dni" class="form-control rounded-pill px-3" required pattern="[0-9]+" inputmode="numeric" title="El DNI debe contener únicamente números" placeholder="33444555">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Fecha de Nacimiento *</label>
                        <input type="date" name="fecha_nacimiento" class="form-control rounded-pill px-3" required max="<?= date('Y-m-d', strtotime('-21 years')); ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Dirección</label>
                    <input type="text" name="direccion" class="form-control rounded-pill px-3" placeholder="Av. Corrientes 123">
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Email *</label>
                        <input type="email" name="email" class="form-control rounded-pill px-3" required placeholder="juan.gomez@multipro.com">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Teléfono</label>
                        <input type="text" name="telefono" class="form-control rounded-pill px-3" pattern="\+?[0-9]+" title="El teléfono solo puede contener números y opcionalmente el símbolo + al inicio" placeholder="Ej: +541122334455">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Entidad Asignada *</label>
                    <select name="fk_entidad" class="form-select rounded-pill px-3" required>
                        <option value="">-- Seleccionar Entidad --</option>
                        <?php foreach ($entidades as $entidad): ?>
                            <option value="<?= $entidad['id']; ?>"><?= htmlspecialchars($entidad['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Contraseña *</label>
                    <input type="password" name="contrasena" class="form-control rounded-pill px-3" required minlength="6" placeholder="Mínimo 6 caracteres">
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
