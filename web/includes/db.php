<?php
// Conexión centralizada a PostgreSQL en Railway.
// Las credenciales deben configurarse como variables del servicio de la aplicación.

$db_host = getenv('PGHOST') ?: '';
$db_port = getenv('PGPORT') ?: '5432';
$db_name = getenv('PGDATABASE') ?: (getenv('POSTGRES_DB') ?: '');
$db_user = getenv('PGUSER') ?: '';
$db_pass = getenv('PGPASSWORD') ?: '';
$db_sslmode = getenv('PGSSLMODE') ?: 'require';

$pdo = null;
$db_driver_used = 'postgresql';

// Evitar que un valor inesperado modifique el DSN de conexión.
$allowed_ssl_modes = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];
if (!in_array($db_sslmode, $allowed_ssl_modes, true)) {
    $db_sslmode = 'require';
}

$missing_variables = [];

if ($db_host === '') {
    $missing_variables[] = 'PGHOST';
}
if ($db_name === '') {
    $missing_variables[] = 'PGDATABASE o POSTGRES_DB';
}
if ($db_user === '') {
    $missing_variables[] = 'PGUSER';
}
if ($db_pass === '') {
    $missing_variables[] = 'PGPASSWORD';
}

if ($missing_variables !== []) {
    error_log(
        'Configuración PostgreSQL incompleta. Faltan variables: '
        . implode(', ', $missing_variables)
    );

    http_response_code(500);
    exit('La plataforma no pudo conectarse a la base de datos.');
}

if (!extension_loaded('pdo_pgsql')) {
    error_log('La extensión PHP pdo_pgsql no está instalada o habilitada.');

    http_response_code(500);
    exit('La plataforma no pudo conectarse a la base de datos.');
}

$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
    $db_host,
    $db_port,
    $db_name,
    $db_sslmode
);

try {
    $pdo = new PDO(
        $dsn,
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // El detalle queda solamente en los logs de Railway; no se exponen credenciales al usuario.
    error_log('Error de conexión a PostgreSQL: ' . $e->getMessage());

    http_response_code(500);
    exit('La plataforma no pudo conectarse a la base de datos.');
}