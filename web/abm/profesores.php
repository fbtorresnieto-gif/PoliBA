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
    } else {
        try {
            // Obtener el ID del rol Profesor
            $stmt = $pdo->query("SELECT id FROM roles WHERE nombre = 'Profesor' LIMIT 1");
            $rol = $stmt->fetch();
            $rol_id = $rol ? $rol['id'] : 3;
            
            $hash = password_hash($contrasena, PASSWORD_BCRYPT);
            
            $stmt = $pdo->prepare("
                INSERT INTO usuarios (nombre, apellido, dni, direccion, email, contrasena, telefono, fecha_nacimiento, fk_polideportivo, fk_rol)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nombre, $apellido, $dni, $direccion, $email, $hash, $telefono, $fecha_nacimiento, $poli_id, $rol_id]);
            $success_msg = 'Profesor registrado con éxito.';
        } catch (PDOException $e) {
            $error_msg = 'El correo electrónico o DNI ya se encuentra registrado.';
        }
    }
}

// Procesar Eliminación (Baja) de Profesor
if (isset($_GET['eliminar_profesor'])) {
    $id = intval($_GET['eliminar_profesor']);
    try {
        $stmt = $pdo->prepare("
            DELETE FROM usuarios 
            WHERE id = ? AND fk_polideportivo = ? AND fk_rol = (SELECT id FROM roles WHERE nombre = 'Profesor')
        ");
        $stmt->execute([$id, $poli_id]);
        $success_msg = 'Profesor dado de baja con éxito.';
    } catch (PDOException $e) {
        $error_msg = 'Error al eliminar el profesor. Puede estar asignado a clases activas.';
    }
}

// Cargar Profesores de la sede
$profesores = [];
try {
    $stmt = $pdo->prepare("
        SELECT u.* 
        FROM usuarios u
        JOIN roles r ON u.fk_rol = r.id
        WHERE u.fk_polideportivo = ? AND r.nombre = 'Profesor'
        ORDER BY u.id ASC
    ");
    $stmt->execute([$poli_id]);
    $profesores = $stmt->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark m-0">Gestión de Profesores</h2>
            <small class="text-muted">Administrando Sede: <strong><?= htmlspecialchars($user['fk_polideportivo_nombre'] ?? 'Mi Polideportivo'); ?></strong></small>
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
                    <th>ID</th>
                    <th>Nombre Completo</th>
                    <th>DNI</th>
                    <th>Gmail</th>
                    <th>Teléfono</th>
                    <th>Nacimiento</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($profesores)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted">No hay profesores registrados para esta sede.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($profesores as $prof): ?>
                        <tr>
                            <td>#<?= $prof['id']; ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($prof['nombre'] . ' ' . $prof['apellido']); ?></td>
                            <td><?= htmlspecialchars($prof['dni']); ?></td>
                            <td><?= htmlspecialchars($prof['email']); ?></td>
                            <td><?= htmlspecialchars($prof['telefono'] ?? '-'); ?></td>
                            <td><?= $prof['fecha_nacimiento'] ? date('d/m/Y', strtotime($prof['fecha_nacimiento'])) : '-'; ?></td>
                            <td>
                                <a href="profesores.php?eliminar_profesor=<?= $prof['id']; ?>" 
                                   class="btn btn-sm btn-danger rounded-pill px-3"
                                   onclick="return confirm('¿Seguro deseas dar de baja a este profesor del sistema?');">
                                    Dar de Baja
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
                        <input type="text" name="dni" class="form-control rounded-pill px-3" required placeholder="28394857">
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
                        <input type="email" name="email" class="form-control rounded-pill px-3" required placeholder="juan.perez@poliba.com">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Teléfono</label>
                        <input type="text" name="telefono" class="form-control rounded-pill px-3" placeholder="+54 9 11 ...">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Contraseña de Acceso *</label>
                    <input type="password" name="contrasena" class="form-control rounded-pill px-3" required placeholder="Mínimo 6 caracteres">
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
