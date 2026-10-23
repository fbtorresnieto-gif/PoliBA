<?php
// Página: Olvidé mi contraseña — MultiPro
// Paso 1: El usuario ingresa su email y se le envía un link de reseteo.

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

// Si ya está logueado, no tiene sentido estar acá
if (is_logged_in()) {
    header("Location: perfil.php");
    exit;
}

$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'solicitar_reset') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = 'Por favor ingresá un email válido.';
    } else {
        global $pdo;

        // Verificar si el email existe en la BD
        $stmt = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE email = ? AND estado = TRUE LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Siempre mostramos el mismo mensaje para no revelar si el email existe o no (seguridad)
        $success_msg = 'Si ese email está registrado en MultiPro, vas a recibir un enlace para restablecer tu contraseña en los próximos minutos.';

        if ($user) {
            // Invalidar tokens anteriores del mismo email
            $pdo->prepare("UPDATE password_resets SET usado = TRUE WHERE email = ? AND usado = FALSE")->execute([$email]);

            // Generar token seguro de 64 caracteres hexadecimales
            $token = bin2hex(random_bytes(32));

            // Guardar en la BD
            $stmt = $pdo->prepare("INSERT INTO password_resets (email, token, created_at, usado) VALUES (?, ?, NOW(), FALSE)");
            $stmt->execute([$email, $token]);

            // Construir link de reseteo (str_replace corrige barras invertidas en Windows)
            $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host      = $_SERVER['HTTP_HOST'];
            $path      = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
            $base_url  = rtrim($protocol . '://' . $host . $path, '/');
            $reset_link = $base_url . '/resetear_contrasena.php?token=' . $token;

            // Armar y enviar el email
            $html = build_reset_email(htmlspecialchars($user['nombre']), $reset_link);
            $sent = send_email($email, $user['nombre'], 'Restablecé tu contraseña — MultiPro', $html);

            if (!$sent) {
                error_log("Error al enviar email de reseteo a: $email");
            }
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
