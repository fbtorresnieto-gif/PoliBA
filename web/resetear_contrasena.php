<?php
// Página: Resetear contraseña — MultiPro
// Paso 2: El usuario hace clic en el link del email y establece su nueva contraseña.

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/RecuperacionContrasena.php';

if (is_logged_in()) {
    header("Location: perfil.php");
    exit;
}

$token     = trim($_GET['token'] ?? '');
$error_msg = '';
$success   = false;
$token_valido = false;
$user_email   = '';
$_SESSION['csrf_password_reset'] ??= bin2hex(random_bytes(32));
$servicio = new RecuperacionContrasena($pdo, static fn (): bool => false);

// --- Validar token ---
if (empty($token)) {
    $error_msg = 'El enlace de restablecimiento no es válido.';
} else {
    global $pdo;
    try {
        if ($servicio->tokenValido($token)) {
            $token_valido = true;
        } else {
            $error_msg = 'El enlace expiró o ya fue utilizado. Solicitá uno nuevo.';
        }
    } catch (PDOException $e) {
        $error_msg = 'Ocurrió un error al verificar el enlace. Intentá de nuevo más tarde.';
        error_log('Error al verificar token de reset: ' . $e->getMessage());
    }
}

// --- Procesar nueva contraseña ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cambiar_contrasena' && $token_valido) {
    $nueva     = $_POST['nueva_contrasena'] ?? '';
    $confirmar = $_POST['confirmar_contrasena'] ?? '';
    $csrf = $_POST['csrf_password_reset'] ?? '';

    if (!is_string($csrf) || !hash_equals($_SESSION['csrf_password_reset'], $csrf)) {
        $error_msg = 'Recargá la página antes de continuar.';
    } elseif (strlen($nueva) < 8) {
        $error_msg = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif ($nueva !== $confirmar) {
        $error_msg = 'Las contraseñas no coinciden.';
    } else {
        try {
            $success = $servicio->restablecer($token, $nueva, $confirmar);
            if (!$success) {
                $token_valido = false;
                $error_msg = 'El enlace expiró o ya fue utilizado. Solicitá uno nuevo.';
            }
        } catch (Throwable $e) {
            $error_msg = 'Ocurrió un error al guardar la nueva contraseña. Intentá de nuevo.';
            error_log('Error al resetear contraseña: ' . $e->getMessage());
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="poliba-container-card text-center" style="background-color: var(--poliba-olive); border-radius: 16px;">

            <?php if ($success): ?>
            <!-- ✅ Contraseña cambiada con éxito -->
            <div class="mb-4">
                <div style="width:64px; height:64px; background:rgba(197,216,82,0.2); border-radius:50%; display:inline-flex; align-items:center; justify-content:center; margin-bottom:16px;">
                    <i class="bi bi-check-circle-fill" style="font-size:30px; color:#c5d852;"></i>
                </div>
                <h2 class="fw-bold mb-2" style="color:#ffffff;">¡Contraseña actualizada!</h2>
                <p style="color:rgba(255,255,255,0.8); font-size:0.95rem;">
                    Tu contraseña fue restablecida correctamente. Ya podés iniciar sesión con tu nueva contraseña.
                </p>
            </div>
            <div class="d-grid">
                <a href="login.php" id="btn-ir-login" class="poliba-btn-dark py-2 rounded-pill fw-bold text-decoration-none">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Ir a Iniciar sesión
                </a>
            </div>

            <?php elseif (!$token_valido): ?>
            <!-- ❌ Token inválido o expirado -->
            <div class="mb-4">
                <div style="width:64px; height:64px; background:rgba(239,68,68,0.2); border-radius:50%; display:inline-flex; align-items:center; justify-content:center; margin-bottom:16px;">
                    <i class="bi bi-exclamation-triangle-fill" style="font-size:28px; color:#f87171;"></i>
                </div>
                <h2 class="fw-bold mb-2" style="color:#ffffff;">Enlace no válido</h2>
                <p style="color:rgba(255,255,255,0.8); font-size:0.95rem; margin-bottom:0;">
                    <?= htmlspecialchars($error_msg) ?>
                </p>
            </div>
            <div class="d-grid gap-2">
                <a href="olvide_contrasena.php" class="poliba-btn-dark py-2 rounded-pill fw-bold text-decoration-none">
                    <i class="bi bi-arrow-repeat me-2"></i>Solicitar nuevo enlace
                </a>
                <a href="login.php" class="text-decoration-none fw-bold mt-2" style="color:rgba(255,255,255,0.7); font-size:0.9rem;">
                    <i class="bi bi-arrow-left me-1"></i>Volver al inicio de sesión
                </a>
            </div>

            <?php else: ?>
            <!-- 🔑 Formulario de nueva contraseña -->
            <div class="mb-4">
                <div style="width:64px; height:64px; background:rgba(255,255,255,0.15); border-radius:50%; display:inline-flex; align-items:center; justify-content:center; margin-bottom:16px;">
                    <i class="bi bi-key-fill" style="font-size:28px; color:#ffffff;"></i>
                </div>
                <h2 class="fw-bold mb-1" style="color:#ffffff;">Nueva contraseña</h2>
                <p style="color:rgba(255,255,255,0.75); font-size:0.92rem; margin:0;">
                    Ingresá tu nueva contraseña. Debe tener al menos 8 caracteres.
                </p>
            </div>

            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger text-start py-2 mb-3" role="alert" style="font-size:0.92rem;">
                    <i class="bi bi-exclamation-circle-fill me-2"></i><?= htmlspecialchars($error_msg) ?>
                </div>
            <?php endif; ?>

            <form action="resetear_contrasena.php?token=<?= htmlspecialchars($token) ?>" method="POST" class="text-start" id="form-nueva-contrasena">
                <input type="hidden" name="action" value="cambiar_contrasena">
                <input type="hidden" name="csrf_password_reset" value="<?= htmlspecialchars($_SESSION['csrf_password_reset']) ?>">

                <!-- Nueva contraseña -->
                <div class="mb-3">
                    <div class="position-relative">
                        <input
                            type="password"
                            name="nueva_contrasena"
                            id="nueva_contrasena"
                            class="form-control rounded-pill px-4 py-2 pe-5"
                            placeholder="Nueva contraseña (mín. 8 caracteres)"
                            required
                            minlength="8"
                            autocomplete="new-password"
                        >
                        <button type="button" class="btn btn-link position-absolute end-0 top-50 translate-middle-y pe-3 py-0"
                            onclick="togglePassword('nueva_contrasena', this)" aria-label="Ver contraseña"
                            style="color:rgba(19,38,68,0.5); border:none; background:none;">
                            <i class="bi bi-eye-fill"></i>
                        </button>
                    </div>
                </div>

                <!-- Confirmar contraseña -->
                <div class="mb-4">
                    <div class="position-relative">
                        <input
                            type="password"
                            name="confirmar_contrasena"
                            id="confirmar_contrasena"
                            class="form-control rounded-pill px-4 py-2 pe-5"
                            placeholder="Repetí la nueva contraseña"
                            required
                            minlength="8"
                            autocomplete="new-password"
                        >
                        <button type="button" class="btn btn-link position-absolute end-0 top-50 translate-middle-y pe-3 py-0"
                            onclick="togglePassword('confirmar_contrasena', this)" aria-label="Ver contraseña"
                            style="color:rgba(19,38,68,0.5); border:none; background:none;">
                            <i class="bi bi-eye-fill"></i>
                        </button>
                    </div>
                    <!-- Indicador de coincidencia en tiempo real -->
                    <div id="match-indicator" class="mt-2 ps-2" style="font-size:0.82rem; min-height:18px;"></div>
                </div>

                <div class="d-grid">
                    <button type="submit" id="btn-cambiar" class="poliba-btn-dark py-2 rounded-pill fw-bold text-uppercase fs-6">
                        <i class="bi bi-floppy-fill me-2"></i>Guardar nueva contraseña
                    </button>
                </div>
            </form>

            <div class="mt-3">
                <a href="login.php" class="text-decoration-none fw-bold" style="color:rgba(255,255,255,0.8); font-size:0.9rem;">
                    <i class="bi bi-arrow-left me-1"></i>Volver al inicio de sesión
                </a>
            </div>

            <script>
            // Mostrar/ocultar contraseña
            function togglePassword(inputId, btn) {
                const input = document.getElementById(inputId);
                const icon  = btn.querySelector('i');
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.className = 'bi bi-eye-slash-fill';
                } else {
                    input.type = 'password';
                    icon.className = 'bi bi-eye-fill';
                }
            }

            // Indicador de coincidencia en tiempo real
            const inputNueva     = document.getElementById('nueva_contrasena');
            const inputConfirmar = document.getElementById('confirmar_contrasena');
            const indicator      = document.getElementById('match-indicator');

            function checkMatch() {
                const v1 = inputNueva.value;
                const v2 = inputConfirmar.value;
                if (!v2) { indicator.innerHTML = ''; return; }
                if (v1 === v2) {
                    indicator.innerHTML = '<span style="color:#c5d852;"><i class="bi bi-check-circle-fill me-1"></i>Las contraseñas coinciden</span>';
                } else {
                    indicator.innerHTML = '<span style="color:#f87171;"><i class="bi bi-x-circle-fill me-1"></i>Las contraseñas no coinciden</span>';
                }
            }

            inputNueva.addEventListener('input', checkMatch);
            inputConfirmar.addEventListener('input', checkMatch);
            </script>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
