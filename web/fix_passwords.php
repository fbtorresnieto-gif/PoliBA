<?php
/**
 * fix_passwords.php - Corrige los hashes de contraseñas en la base de datos
 * Ejecutar UNA SOLA VEZ desde el navegador: http://localhost:8080/fix_passwords.php
 * Luego eliminar este archivo por seguridad.
 */
require_once __DIR__ . '/includes/db.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>PoliBA — Fix Contraseñas</title>
    <style>
        body { background:#0f172a; color:#e2e8f0; font-family:monospace; padding:40px; }
        .ok  { color:#22c55e; } .err { color:#ef4444; }
        .box { background:#1e293b; padding:20px; border-radius:12px; margin-top:20px; }
        a    { color:#c5d852; }
    </style>
</head>
<body>
<h2 style="color:#c5d852;">🔑 Fix de Contraseñas — PoliBA</h2>
<div class="box">
<?php
$hash = '$2y$10$FJ68oTWA59zgN.BXCRZsdu5wsDuq.jc/tK0.ecAjFUFpq63a/ZUbq'; // hash de '123456'

$emails = [
    'gestor@poliba.com',
    'admin.colegiales@poliba.com',
    'admin.sarmiento@poliba.com',
    'juan.perez@poliba.com',
    'maria.lopez@poliba.com',
    'gero@gmail.com',
    'flor@gmail.com',
];

$ok = 0;
foreach ($emails as $email) {
    try {
        $stmt = $pdo->prepare("UPDATE usuarios SET contrasena = ? WHERE email = ?");
        $stmt->execute([$hash, $email]);
        $rows = $stmt->rowCount();
        if ($rows > 0) {
            echo "<p class='ok'>✅ Contraseña actualizada: <b>$email</b></p>";
            $ok++;
        } else {
            echo "<p style='color:#f59e0b;'>⚠️ Usuario no encontrado: $email (¿corriste db_setup.php?)</p>";
        }
    } catch (PDOException $e) {
        echo "<p class='err'>❌ Error con $email: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

echo "<hr style='border-color:#334155; margin:20px 0;'>";
echo "<p class='ok'><b>$ok usuarios actualizados.</b> Ahora podés ingresar con contraseña <code>123456</code>.</p>";
echo "<p style='margin-top:16px;'><a href='login.php'>→ Ir al Login</a></p>";
?>
</div>
</body>
</html>
