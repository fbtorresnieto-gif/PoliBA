<?php
// Página: Olvidé mi contraseña — MultiPro
// Paso 1: El usuario ingresa su email y se le envía un link de reseteo.

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/RecuperacionContrasena.php';

// Si ya está logueado, no tiene sentido estar acá
if (is_logged_in()) {
    header("Location: perfil.php");
    exit;
}

$success_msg = '';
$error_msg   = '';
$_SESSION['csrf_password_reset'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'solicitar_reset') {
    $email = trim($_POST['email'] ?? '');
    $csrf = $_POST['csrf_password_reset'] ?? '';

    if (!is_string($csrf) || !hash_equals($_SESSION['csrf_password_reset'], $csrf)) {
        $error_msg = 'Recargá la página antes de solicitar otro enlace.';
    } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = 'Por favor ingresá un email válido.';
    } else {
        global $pdo;
        // Siempre mostramos el mismo mensaje para no revelar si el email existe o no (seguridad)
        $success_msg = 'Si ese email está registrado en MultiPro, vas a recibir un enlace para restablecer tu contraseña en los próximos minutos.';
        try {
            $servicio = new RecuperacionContrasena($pdo, 'send_email');
            $servicio->solicitar($email);
        } catch (Throwable $e) {
            // No exponer existencia de cuenta ni detalles del proveedor.
            error_log('Recuperacion de contrasena no disponible: ' . $e->getMessage());
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="poliba-container-card text-center" style="background-color: var(--poliba-olive); border-radius: 16px;">

            <!-- Icono y título -->
            <div class="mb-4">
                <div style="width:64px; height:64px; background:rgba(255,255,255,0.15); border-radius:50%; display:inline-flex; align-items:center; justify-content:center; margin-bottom:16px;">
                    <i class="bi bi-shield-lock-fill" style="font-size:28px; color:#ffffff;"></i>
                </div>
                <h2 class="fw-bold mb-1" style="color:#ffffff;">Olvidé mi contraseña</h2>
                <p style="color:rgba(255,255,255,0.75); font-size:0.92rem; margin:0;">
                    Ingresá tu email y te enviaremos un enlace para restablecerla.
                </p>
            </div>

            <?php if (!empty($success_msg)): ?>
                <!-- Estado éxito -->
                <div style="background:rgba(197,216,82,0.15); border:1px solid rgba(197,216,82,0.5); border-radius:12px; padding:20px 24px; margin-bottom:24px;">
                    <i class="bi bi-envelope-check-fill" style="font-size:36px; color:#c5d852; display:block; margin-bottom:12px;"></i>
                    <p style="color:#ffffff; font-size:0.95rem; margin:0; line-height:1.6;">
                        <?= htmlspecialchars($success_msg) ?>
                    </p>
                </div>
                <div class="d-grid">
                    <a href="login.php" class="poliba-btn-dark py-2 rounded-pill fw-bold text-decoration-none">
                        <i class="bi bi-arrow-left me-2"></i>Volver al inicio de sesión
                    </a>
                </div>

            <?php else: ?>
                <!-- Mensaje de error -->
                <?php if (!empty($error_msg)): ?>
                    <div class="alert alert-danger text-start py-2 mb-3" role="alert" style="font-size:0.92rem;">
                        <i class="bi bi-exclamation-circle-fill me-2"></i><?= htmlspecialchars($error_msg) ?>
                    </div>
                <?php endif; ?>

                <!-- Formulario -->
                <form action="olvide_contrasena.php" method="POST" class="text-start">
                    <input type="hidden" name="action" value="solicitar_reset">
                    <input type="hidden" name="csrf_password_reset" value="<?= htmlspecialchars($_SESSION['csrf_password_reset']) ?>">

                    <div class="mb-4">
                        <input
                            type="email"
                            name="email"
                            id="email-reset"
                            class="form-control rounded-pill px-4 py-2"
                            placeholder="Tu email registrado"
                            required
                            autocomplete="email"
                            value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>"
                        >
                    </div>

                    <div class="d-grid gap-2 mb-3">
                        <button type="submit" id="btn-enviar-reset" class="poliba-btn-dark py-2 rounded-pill fw-bold text-uppercase fs-6">
                            <i class="bi bi-send-fill me-2"></i>Enviar enlace
                        </button>
                    </div>
                </form>

                <div class="mt-3">
                    <a href="login.php" class="text-decoration-none fw-bold" style="color:rgba(255,255,255,0.8); font-size:0.9rem;">
                        <i class="bi bi-arrow-left me-1"></i>Volver al inicio de sesión
                    </a>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
