<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

// Si ya está logueado, redirigir al perfil
if (is_logged_in()) {
    header("Location: perfil.php");
    exit;
}

$error_msg = '';
$success_msg = '';

// Obtener los polideportivos para el selector
$polideportivos = [];
try {
    $stmt = $pdo->query("SELECT id, nombre FROM polideportivos WHERE estado = TRUE");
    $polideportivos = $stmt->fetchAll();
} catch (PDOException $e) {
    // Si falla, el selector estará vacío
}

// Procesar registro
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'registro') {
    $nombre = trim($_POST['nombre']);
    $apellido = trim($_POST['apellido']);
    $dni = trim($_POST['dni']);
    $direccion = trim($_POST['direccion']);
    $email = trim($_POST['email']);
    $contrasena = $_POST['contrasena'];
    $confirmar_contrasena = $_POST['confirmar_contrasena'];
    $telefono = trim($_POST['telefono']);
    $fecha_nacimiento = $_POST['fecha_nacimiento'];
    $polideportivo_id = !empty($_POST['fk_polideportivo']) ? intval($_POST['fk_polideportivo']) : null;
    
    // Validaciones
    if (empty($nombre) || empty($apellido) || empty($dni) || empty($email) || empty($contrasena) || empty($fecha_nacimiento)) {
        $error_msg = 'Por favor, completa todos los campos obligatorios (*).';
    } elseif ($contrasena !== $confirmar_contrasena) {
        $error_msg = 'Las contraseñas no coinciden.';
    } elseif (strlen($contrasena) < 6) {
        $error_msg = 'La contraseña debe tener al menos 6 caracteres.';
    } else {
        // Validar edad mínima (18 años)
        $diff = date_diff(date_create($fecha_nacimiento), date_create('today'));
        $edad = $diff->y;
        if ($edad < 18) {
            $error_msg = 'Debes tener al menos 18 años para registrarte en el sistema. Los menores deben ser inscritos por sus tutores desde su perfil.';
        } else {
            try {
                if (register_alumno($nombre, $apellido, $dni, $direccion, $email, $contrasena, $telefono, $fecha_nacimiento, $polideportivo_id)) {
                    $success_msg = '¡Registro completado con éxito! Ahora puedes iniciar sesión.';
                } else {
                    $error_msg = 'Hubo un error al registrar el usuario. Por favor, intenta de nuevo.';
                }
            } catch (Exception $e) {
                $error_msg = $e->getMessage();
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="poliba-container-card">
            <h2 class="section-title text-dark">Registro de Alumno</h2>
            
            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success_msg)): ?>
                <div class="alert alert-success" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($success_msg); ?>
                    <div class="mt-2">
                        <a href="login.php" class="poliba-btn-dark px-3 py-1">Iniciar Sesión</a>
                    </div>
                </div>
            <?php else: ?>
                <form action="registro.php" method="POST">
                    <input type="hidden" name="action" value="registro">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-dark">Nombre *</label>
                            <input type="text" name="nombre" class="form-control rounded-pill px-3" required 
                                   value="<?= isset($_POST['nombre']) ? htmlspecialchars($_POST['nombre']) : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-dark">Apellido *</label>
                            <input type="text" name="apellido" class="form-control rounded-pill px-3" required 
                                   value="<?= isset($_POST['apellido']) ? htmlspecialchars($_POST['apellido']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-dark">DNI *</label>
                            <input type="text" name="dni" class="form-control rounded-pill px-3" required 
                                   value="<?= isset($_POST['dni']) ? htmlspecialchars($_POST['dni']) : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-dark">Fecha de Nacimiento *</label>
                            <input type="date" name="fecha_nacimiento" class="form-control rounded-pill px-3" required 
                                   value="<?= isset($_POST['fecha_nacimiento']) ? htmlspecialchars($_POST['fecha_nacimiento']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold text-dark">Dirección</label>
                        <input type="text" name="direccion" class="form-control rounded-pill px-3" 
                               value="<?= isset($_POST['direccion']) ? htmlspecialchars($_POST['direccion']) : ''; ?>">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-dark">Email *</label>
                            <input type="email" name="email" class="form-control rounded-pill px-3" required 
                                   value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-dark">Teléfono</label>
                            <input type="text" name="telefono" class="form-control rounded-pill px-3" 
                                   value="<?= isset($_POST['telefono']) ? htmlspecialchars($_POST['telefono']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold text-dark">Polideportivo Favorito/Cercano</label>
                        <select name="fk_polideportivo" class="form-select rounded-pill px-3">
                            <option value="">-- Seleccionar Polideportivo --</option>
                            <?php foreach ($polideportivos as $poli): ?>
                                <option value="<?= $poli['id']; ?>" <?= (isset($_POST['fk_polideportivo']) && $_POST['fk_polideportivo'] == $poli['id']) ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($poli['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-dark">Contraseña *</label>
                            <input type="password" name="contrasena" class="form-control rounded-pill px-3" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-dark">Confirmar Contraseña *</label>
                            <input type="password" name="confirmar_contrasena" class="form-control rounded-pill px-3" required>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="poliba-btn px-5 py-2 fw-bold text-uppercase">Registrarse</button>
                        <p class="mt-3 text-muted">¿Ya tienes cuenta? <a href="login.php" class="text-dark fw-bold">Inicia Sesión</a></p>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
