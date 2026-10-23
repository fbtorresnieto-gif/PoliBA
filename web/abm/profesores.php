<?php
$base_path = '../';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Validar que sea Administrador
redirect_if_not_logged_in(['Administrador']);

$user = get_logged_user();
$entidad_id = $user['fk_entidad']; // Entidad administrada

if (!$entidad_id) {
    die("Error: El administrador no tiene una entidad asignada.");
}

$error_msg = '';
$success_msg = '';

// Procesar Creación de Profesor
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'crear') {
    $nombre = trim($_POST['nombre']);
    $apellido = trim($_POST['apellido']);
    $dni = trim($_POST['dni']);
    $direccion = trim($_POST['direccion']);
    $email = trim($_POST['email']);
    $telefono = trim($_POST['telefono']);
    $fecha_nacimiento = $_POST['fecha_nacimiento'];
    $contrasena = trim($_POST['contrasena']);
    
    if (empty($nombre) || empty($apellido) || empty($dni) || empty($email) || empty($contrasena)) {
        $error_msg = 'Por favor, completa todos los campos obligatorios.';
    } elseif (!ctype_digit($dni)) {
        $error_msg = 'El DNI debe contener únicamente números.';
    } elseif (!empty($telefono) && !ctype_digit($telefono)) {
        $error_msg = 'El teléfono debe contener únicamente números.';
    } elseif (strlen($contrasena) < 6) {
        $error_msg = 'La contraseña debe tener al menos 6 caracteres.';
    } else {
        try {
            // Obtener el ID del rol Profesor
            $stmt = $pdo->query("SELECT id FROM roles WHERE nombre = 'Profesor' LIMIT 1");
            $rol = $stmt->fetch();
            $rol_id = $rol ? $rol['id'] : 3;
            
            $hash = password_hash($contrasena, PASSWORD_BCRYPT);
            
            $stmt = $pdo->prepare("
                INSERT INTO usuarios (nombre, apellido, dni, direccion, email, contrasena, telefono, fecha_nacimiento, fk_entidad, fk_rol)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nombre, $apellido, $dni, $direccion, $email, $hash, $telefono, $fecha_nacimiento, $entidad_id, $rol_id]);
            $success_msg = 'Profesor registrado con éxito.';
        } catch (PDOException $e) {
            $error_msg = 'El correo electrónico o DNI ya se encuentra registrado.';
        }
    }
}

// Procesar Baja / Alta lógica de Profesor (cambia el estado, no borra de la DB)
if (isset($_GET['cambiar_estado_profesor'])) {
    $id = intval($_GET['cambiar_estado_profesor']);
    $activar = (($_GET['estado'] ?? '') === '0'); // si estaba inactivo (0), se activa
    try {
        $stmt = $pdo->prepare("
            UPDATE usuarios SET estado = ?
            WHERE id = ? AND fk_entidad = ? AND fk_rol = (SELECT id FROM roles WHERE nombre = 'Profesor')
        ");
        $stmt->bindValue(1, $activar, PDO::PARAM_BOOL);
        $stmt->bindValue(2, $id, PDO::PARAM_INT);
        $stmt->bindValue(3, $entidad_id, PDO::PARAM_INT);
        $stmt->execute();
        $success_msg = $activar ? 'Profesor activado con éxito.' : 'Profesor dado de baja con éxito.';
    } catch (PDOException $e) {
        $error_msg = 'Error al cambiar el estado del profesor.';
    }
}

// Cargar Profesores de la sede
$profesores = [];
try {
    $stmt = $pdo->prepare("
        SELECT u.* 
        FROM usuarios u
        JOIN roles r ON u.fk_rol = r.id
        WHERE u.fk_entidad = ? AND r.nombre = 'Profesor'
        ORDER BY u.id ASC
    ");
    $stmt->execute([$entidad_id]);
    $profesores = $stmt->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark m-0">Gestión de Profesores</h2>
            <small class="text-muted">Administrando Entidad: <strong><?= htmlspecialchars($user['fk_entidad_nombre'] ?? 'Mi Entidad'); ?></strong></small>
        </div>
        <button class="poliba-btn" data-bs-toggle="modal" data-bs-target="#crearProfesorModal">Registrar Profesor</button>
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
                    <th>Teléfono</th>
                    <th>Nacimiento</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($profesores)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted">No hay profesores registrados para esta entidad.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($profesores as $prof): ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($prof['nombre'] . ' ' . $prof['apellido']); ?></td>
                            <td><?= htmlspecialchars($prof['dni']); ?></td>
                            <td><?= htmlspecialchars($prof['email']); ?></td>
                            <td><?= htmlspecialchars($prof['telefono'] ?? '-'); ?></td>
                            <td><?= $prof['fecha_nacimiento'] ? date('d/m/Y', strtotime($prof['fecha_nacimiento'])) : '-'; ?></td>
                            <td>
                                <span class="badge rounded-pill <?= $prof['estado'] ? 'bg-success' : 'bg-danger'; ?>">
                                    <?= $prof['estado'] ? 'Activo' : 'Inactivo'; ?>
                                </span>
                            </td>
                            <td>
                                <a href="profesores.php?cambiar_estado_profesor=<?= $prof['id']; ?>&estado=<?= $prof['estado'] ? '1' : '0'; ?>"
                                   class="btn btn-sm <?= $prof['estado'] ? 'btn-danger' : 'btn-success'; ?> rounded-pill px-3"
                                   onclick="return confirm('<?= $prof['estado'] ? '¿Seguro deseas dar de baja a este profesor? No podrá iniciar sesión hasta que lo reactives.' : '¿Seguro deseas reactivar a este profesor?'; ?>');">
                                    <?= $prof['estado'] ? 'Dar de Baja' : 'Activar'; ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Registrar Profesor -->
<div class="modal fade" id="crearProfesorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="profesores.php" method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="crear">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">Registrar Profesor</h5>
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
                        <input type="text" name="apellido" class="form-control rounded-pill px-3" required placeholder="Perez">
                    </div>
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">DNI *</label>
                        <input type="text" name="dni" class="form-control rounded-pill px-3" required pattern="[0-9]+" inputmode="numeric" title="El DNI debe contener únicamente números" placeholder="28394857">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Fecha de Nacimiento</label>
                        <input type="date" name="fecha_nacimiento" class="form-control rounded-pill px-3">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Dirección</label>
                    <input type="text" name="direccion" class="form-control rounded-pill px-3" placeholder="Calle Falsa 123">
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Email *</label>
                        <input type="email" name="email" class="form-control rounded-pill px-3" required placeholder="juan.perez@multipro.com">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Teléfono</label>
                        <input type="text" name="telefono" class="form-control rounded-pill px-3" pattern="[0-9]*" inputmode="numeric" title="El teléfono debe contener únicamente números" placeholder="Ej: 1122334455">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Contraseña de Acceso *</label>
                    <input type="password" name="contrasena" class="form-control rounded-pill px-3" required minlength="6" placeholder="Mínimo 6 caracteres">
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                <button type="submit" class="poliba-btn py-2">Registrar Profesor</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
