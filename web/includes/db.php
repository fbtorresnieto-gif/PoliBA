<?php
// Configuración de Conexión a la Base de Datos para PoliBA
// ----------------------------------------------------------
// Conexión principal: PostgreSQL en Neon.tech (base de datos en la nube)

$db_host = 'ep-billowing-hill-aycuwnf6.c-5.us-east-2.aws.neon.tech';
$db_port = '5432';
$db_name = 'neondb';
$db_user = 'neondb_owner';
$db_pass = 'npg_m6TU1orRAjQF';

$pdo = null;
$db_driver_used = 'postgresql';

try {
    // Conectar a PostgreSQL en Neon (requiere sslmode=require)
    $dsn = "pgsql:host=$db_host;port=$db_port;dbname=$db_name;sslmode=require";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    // FALLBACK A SQLITE (solo si Neon no está disponible temporalmente):
    // Permite que el sitio funcione localmente sin conexión a internet.
    $sqlite_dir = __DIR__ . '/../../database';
    if (!is_dir($sqlite_dir)) {
        mkdir($sqlite_dir, 0777, true);
    }

    $sqlite_path = $sqlite_dir . '/poliba.db';
    $db_driver_used = 'sqlite';

    try {
        $pdo = new PDO("sqlite:" . $sqlite_path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);

        // Verificar si la base de datos SQLite está vacía
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='usuarios'");
        $table_exists = $stmt->fetch();

        if (!$table_exists) {
            $schema_file = __DIR__ . '/../../database/schema.sql';
            $seed_file   = __DIR__ . '/../../database/seed.sql';

            if (file_exists($schema_file)) {
                $schema_sql = file_get_contents($schema_file);
                $schema_sql = str_ireplace('SERIAL PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $schema_sql);
                $schema_sql = preg_replace('/--.*/', '', $schema_sql);

                foreach (explode(';', $schema_sql) as $query) {
                    $query = trim($query);
                    if (!empty($query)) $pdo->exec($query);
                }
            }

            if (file_exists($seed_file)) {
                $seed_sql = file_get_contents($seed_file);
                $seed_sql = preg_replace('/--.*/', '', $seed_sql);

                foreach (explode(';', $seed_sql) as $query) {
                    $query = trim($query);
                    if (!empty($query)) $pdo->exec($query);
                }
            }
        }
    } catch (PDOException $sqlite_err) {
        die("<b>Error crítico de base de datos:</b><br>" .
            "Neon PostgreSQL: " . $e->getMessage() . "<br>" .
            "SQLite Fallback: " . $sqlite_err->getMessage());
    }
}
