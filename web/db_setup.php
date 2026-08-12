<?php
/**
 * db_setup.php - Script de Inicialización de Base de Datos para PoliBA
 * -----------------------------------------------------------------------
 * Ejecutá este script UNA SOLA VEZ desde tu navegador para crear todas las
 * tablas e insertar los datos de prueba en tu base de datos de Neon.tech.
 *
 * URL de acceso: http://localhost/PoliBA/web/db_setup.php
 *
 * IMPORTANTE: Una vez que termine con éxito, podés eliminar este archivo
 * por seguridad, o simplemente dejarlo (no hace nada si las tablas ya existen).
 */

require_once __DIR__ . '/includes/db.php';

// Función para mostrar mensajes en pantalla
function log_msg(string $msg, string $type = 'info'): void {
    $colors = ['info' => '#3b82f6', 'ok' => '#22c55e', 'error' => '#ef4444', 'warn' => '#f59e0b', 'title' => '#c5d852'];
    $color = $colors[$type] ?? '#fff';
    echo "<div style='margin: 4px 0; padding: 8px 14px; background: #1e293b; border-left: 4px solid $color; border-radius: 4px; font-family: monospace; color: #e2e8f0;'>$msg</div>";
    ob_flush();
    flush();
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>PoliBA — Setup de Base de Datos</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #0f172a; color: #e2e8f0; font-family: 'Segoe UI', sans-serif; padding: 40px 20px; }
        .container { max-width: 780px; margin: 0 auto; }
        h1 { color: #c5d852; font-size: 2rem; margin-bottom: 6px; }
        h2 { color: #94a3b8; font-size: 1rem; font-weight: normal; margin-bottom: 30px; }
        .log-wrap { background: #0d1a2e; border-radius: 12px; padding: 20px; margin-top: 20px; }
        .success-box { background: #14532d; border: 1px solid #22c55e; border-radius: 12px; padding: 20px; margin-top: 24px; color: #bbf7d0; }
        .error-box   { background: #450a0a; border: 1px solid #ef4444; border-radius: 12px; padding: 20px; margin-top: 24px; color: #fecaca; }
        .btn { display: inline-block; margin-top: 20px; padding: 12px 28px; background: #c5d852; color: #132644; font-weight: bold; border-radius: 50px; text-decoration: none; }
        .btn:hover { background: #d4e46a; }
    </style>
</head>
<body>
<div class="container">
    <h1>🏟 PoliBA &mdash; Setup de Base de Datos</h1>
    <h2>Inicializando tablas y datos en Neon PostgreSQL...</h2>

    <div class="log-wrap">
<?php

$schema_file = __DIR__ . '/../database/schema.sql';
$seed_file   = __DIR__ . '/../database/seed.sql';

// Verificar archivos SQL
if (!file_exists($schema_file)) {
    log_msg("❌ No se encontró el archivo: database/schema.sql", 'error');
    echo "</div><div class='error-box'>El archivo schema.sql no existe. Verificá que la estructura del proyecto esté correcta.</div></div></body></html>";
    exit;
}

if (!file_exists($seed_file)) {
    log_msg("⚠️ No se encontró: database/seed.sql (se crearán solo las tablas, sin datos de prueba)", 'warn');
}

// Verificar conexión
log_msg("🔌 Motor de base de datos activo: <strong>" . strtoupper($db_driver_used) . "</strong>", 'info');

if ($db_driver_used !== 'postgresql') {
    log_msg("⚠️ No se pudo conectar a Neon. Se está usando SQLite como fallback.", 'warn');
} else {
    log_msg("✅ Conectado a <strong>Neon PostgreSQL</strong> correctamente.", 'ok');
}

$errors = [];
$success_tables = 0;
$success_inserts = 0;

// ---- EJECUTAR SCHEMA.SQL ----
log_msg("📋 Leyendo schema.sql...", 'info');

$schema_sql = file_get_contents($schema_file);

// Separar y ejecutar cada statement
$statements = preg_split('/;\s*\n/', $schema_sql);

foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    if (empty($stmt) || strpos($stmt, '--') === 0) continue;

    // Extraer nombre de tabla para el log
    preg_match('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+"?(\w+)"?/i', $stmt, $matches);
    $table_name = $matches[1] ?? null;

    try {
        $pdo->exec($stmt);
        if ($table_name) {
            log_msg("   ✅ Tabla creada/verificada: <strong>$table_name</strong>", 'ok');
            $success_tables++;
        }
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        // En PostgreSQL, "already exists" no es un error fatal para nuestro setup
        if (stripos($msg, 'already exists') !== false) {
            if ($table_name) log_msg("   ℹ️ Tabla <strong>$table_name</strong> ya existe (sin cambios)", 'warn');
        } else {
            log_msg("   ❌ Error en statement: " . htmlspecialchars(substr($stmt, 0, 120)) . "... <br>&nbsp;&nbsp;&nbsp;&nbsp;→ " . htmlspecialchars($msg), 'error');
            $errors[] = $msg;
        }
    }
}

log_msg("", 'info');
log_msg("🌱 Leyendo seed.sql (datos iniciales)...", 'info');

// ---- EJECUTAR SEED.SQL ----
if (file_exists($seed_file)) {
    $seed_sql = file_get_contents($seed_file);
    $seed_statements = preg_split('/;\s*\n/', $seed_sql);

    foreach ($seed_statements as $stmt) {
        $stmt = trim($stmt);
        if (empty($stmt) || strpos($stmt, '--') === 0) continue;

        try {
            $pdo->exec($stmt);
            $success_inserts++;
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            // Ignorar duplicados (unique constraint violations)
            if (stripos($msg, 'duplicate key') !== false || stripos($msg, 'UNIQUE constraint') !== false) {
                log_msg("   ℹ️ Dato ya existente (ignorado): " . htmlspecialchars(substr($stmt, 0, 80)) . "...", 'warn');
            } else {
                log_msg("   ❌ Error en seed: " . htmlspecialchars($msg), 'error');
                $errors[] = $msg;
            }
        }
    }
    log_msg("   ✅ Datos de prueba insertados: <strong>$success_inserts operaciones</strong>", 'ok');
} else {
    log_msg("   ⚠️ Archivo seed.sql no encontrado. Sin datos de prueba.", 'warn');
}

echo "</div>"; // .log-wrap

// ---- RESULTADO FINAL ----
if (empty($errors)) {
    ?>
    <div class="success-box">
        <h3 style="font-size:1.2rem; margin-bottom:10px;">🎉 ¡Base de datos inicializada con éxito!</h3>
        <p>Se crearon <strong><?= $success_tables; ?> tablas</strong> y se ejecutaron <strong><?= $success_inserts; ?> operaciones de datos</strong>.</p>
        <br>
        <p><strong>Usuarios de prueba disponibles</strong> (contraseña: <code>123456</code>):</p>
        <ul style="margin-top:8px; padding-left:20px; line-height:2;">
            <li>🔵 <strong>Gestor:</strong> gestor@poliba.com</li>
            <li>🟢 <strong>Administrador (Colegiales):</strong> admin.colegiales@poliba.com</li>
            <li>🟡 <strong>Profesor:</strong> juan.perez@poliba.com</li>
            <li>🟠 <strong>Alumno:</strong> gero@gmail.com (o botón Google Auth en login)</li>
        </ul>
        <a href="index.php" class="btn">Ir al Inicio del Sitio →</a>
    </div>
<?php
} else {
    ?>
    <div class="error-box">
        <h3 style="font-size:1.2rem; margin-bottom:10px;">⚠️ Setup completado con <?= count($errors); ?> error(es)</h3>
        <p>Revisá los mensajes en rojo de arriba para ver qué falló. Si los errores son sobre tablas que ya existen, puede ignorarlos y el sitio funcionará igualmente.</p>
        <a href="index.php" class="btn" style="background:#ef4444; color:white;">Ir al Inicio de todas formas →</a>
    </div>
<?php
}
?>

</div>
</body>
</html>
