<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google_config.php';

// Si ya está logueado, redirigir al perfil
if (is_logged_in()) {
    header("Location: perfil.php");
    exit;
}

$error_msg = isset($_GET['error']) ? trim($_GET['error']) : '';
$show_google_setup_modal = isset($_GET['google_setup_required']) && $_GET['google_setup_required'] == 1;

// Procesar login convencional
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'login') {
    $email = trim($_POST['email']);
    $password = trim($_POST['contrasena']);
    
    if (empty($email) || empty($password)) {
        $error_msg = 'Por favor, completa todos los campos.';
    } else {
        if (login($email, $password)) {
            header("Location: perfil.php");
            exit;
        } else {
            $error_msg = 'Correo electrónico o contraseña incorrectos. Si no te has registrado, haz clic en Registrate.';
        }
    }
}

// Opción de Demostración Rápida de Google Login para Tesis
if (isset($_GET['google_demo_login']) && $_GET['google_demo_login'] == 1) {
    global $pdo;
    $email = 'gero@gmail.com'; 
    try {
        $stmt = $pdo->prepare("
            SELECT u.*, r.nombre as rol_nombre 
            FROM usuarios u 
            JOIN roles r ON u.fk_rol = r.id 
            WHERE u.email = ?
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_nombre'] = $user['nombre'];
            $_SESSION['user_apellido'] = $user['apellido'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_rol'] = $user['rol_nombre'];
            $_SESSION['user_polideportivo'] = $user['fk_polideportivo'];
            
            unset($user['contrasena']);
            $_SESSION['user_data'] = $user;
            
            header("Location: perfil.php?google_success=1");
            exit;
        }
    } catch (PDOException $e) {
        $error_msg = 'Error en inicio rápido de demostración.';
    }
}

$google_auth_url = get_google_auth_url();

require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="poliba-container-card text-center" style="background-color: var(--poliba-olive); border-radius: 16px;">
            <h2 class="fw-bold mb-4" style="color: var(--poliba-dark-blue);">Iniciar sesión</h2>
            
            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger text-start py-2" role="alert" style="font-size: 0.95rem;">
                    <i class="bi bi-exclamation-circle-fill me-2"></i> <?= htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>

            <?php if ($show_google_setup_modal): ?>
                <div class="alert alert-warning text-start py-3 mb-4" role="alert" style="font-size: 0.9rem; border-left: 4px solid #f59e0b; background-color: #fffbe6; color: #78350f;">
                    <div class="fw-bold mb-1"><i class="bi bi-gear-fill me-1"></i> Configuración de Google OAuth 2.0</div>
                    <div>Para activar el botón oficial de Google con redirección a <code>accounts.google.com</code>:</div>
                    <ol class="ps-3 my-2">
                        <li>Creá un Client ID en <a href="https://console.cloud.google.com/apis/credentials" target="_blank" class="fw-bold text-dark">Google Cloud Console</a>.</li>
                        <li>Ingresá como URI de redirección: <code><?= htmlspecialchars(get_google_redirect_uri()); ?></code></li>
                        <li>Pegá tu Client ID y Secret en <code class="fw-bold">web/includes/google_config.php</code></li>
                    </ol>
                    <div class="mt-2 text-center pt-2 border-top border-warning">
                        <span class="d-block mb-2 font-normal">¿Querés probar el ingreso de demostración para la tesis ahora?</span>
                        <a href="login.php?google_demo_login=1" class="btn btn-sm btn-dark rounded-pill px-3 py-1 fw-bold">Entrar como Alumno de prueba (Google Demo)</a>
                    </div>
                </div>
            <?php endif; ?>
            
            <form action="login.php" method="POST" class="text-start">
                <input type="hidden" name="action" value="login">
                
                <div class="mb-3">
                    <input type="email" name="email" class="form-control rounded-pill px-4 py-2" placeholder="Email" required 
                           value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
                
                <div class="mb-3">
                    <input type="password" name="contrasena" class="form-control rounded-pill px-4 py-2" placeholder="Contraseña" required>
                </div>
                
                <div class="d-grid gap-2 mb-3">
                    <button type="submit" class="poliba-btn-dark py-2 rounded-pill fw-bold text-uppercase fs-5">Iniciar</button>
                </div>
            </form>
            
            <div class="mb-4">
                <a href="#" class="text-decoration-none text-dark fw-bold" onclick="alert('Se enviaría un correo de restauración al Gmail ingresado.'); return false;">
                    Olvidé mi contraseña
                </a>
            </div>
            
            <div class="mb-2 text-dark fw-bold">Si tenés cuenta de Google</div>
            <div class="d-grid gap-2 mb-4">
                <a href="<?= htmlspecialchars($google_auth_url); ?>" class="poliba-btn-dark py-2 rounded-pill fw-bold text-decoration-none d-flex align-items-center justify-content-center">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/c/c1/Google_%22G%22_logo.svg" width="20" height="20" class="me-2" alt="Google">
                    Iniciar sesión con Google
                </a>
            </div>
            
            <div class="mb-2 text-dark fw-bold">¿Sos nuevo en nuestro sistema?</div>
            <div class="d-grid gap-2">
                <a href="registro.php" class="poliba-btn-dark py-2 rounded-pill fw-bold text-decoration-none">Registrate</a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
