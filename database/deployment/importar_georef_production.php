<?php
declare(strict_types=1);

// Ejecutor explícito para la ventana productiva. No realiza llamadas de red ni acepta rutas externas.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$modo = (string) getenv('MULTIPRO_GEOREF_TARGET');
$host = (string) getenv('PGHOST');
$baseEsperada = (string) getenv('MULTIPRO_EXPECTED_DATABASE');
$confirmacion = (string) getenv('MULTIPRO_GEOREF_CONFIRM');
$entornoRailway = (string) getenv('RAILWAY_ENVIRONMENT_NAME');

if ($baseEsperada === '' || !preg_match('/^[A-Za-z0-9_-]{1,63}$/D', $baseEsperada)) {
    throw new RuntimeException('Falta identificar de forma válida la base esperada.');
}
if ($confirmacion !== 'IMPORTAR_CATALOGO_GEOREF_MULTIPRO') {
    throw new RuntimeException('Falta la confirmación explícita de importación.');
}
if ($modo === 'production') {
    if ($entornoRailway !== 'production' || $host === '' || in_array($host, ['127.0.0.1','localhost'], true)) {
        throw new RuntimeException('El entorno no coincide con la ventana productiva declarada.');
    }
} elseif ($modo === 'rehearsal') {
    if ($host !== '127.0.0.1' || !preg_match('/^multipro_test_pagos_[0-9]{14}$/D', $baseEsperada)) {
        throw new RuntimeException('El ensayo solo admite una base temporal local controlada.');
    }
} else {
    throw new RuntimeException('MULTIPRO_GEOREF_TARGET debe ser production o rehearsal.');
}

require dirname(__DIR__, 2).'/web/includes/db.php';
require dirname(__DIR__, 2).'/web/includes/CatalogoGeoref.php';

$baseActual = (string) $pdo->query('SELECT current_database()')->fetchColumn();
if (!hash_equals($baseEsperada, $baseActual)) {
    throw new RuntimeException('La conexión no apunta a la base esperada.');
}
foreach (['codigo_georef','departamento_id','departamento','categoria'] as $columna) {
    $s=$pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='localidades' AND column_name=?");
    $s->execute([$columna]);
    if (!$s->fetchColumn()) throw new RuntimeException('La migración 010 no está completa.');
}

$conteosAntes = [
    'entidades'=>(int)$pdo->query('SELECT count(*) FROM entidades')->fetchColumn(),
    'reservas'=>(int)$pdo->query('SELECT count(*) FROM reservas')->fetchColumn(),
    'inscripciones'=>(int)$pdo->query('SELECT count(*) FROM inscripcion')->fetchColumn(),
];
$resultado = CatalogoGeoref::importar($pdo, dirname(__DIR__).'/catalogos/georef');
$conteosDespues = [
    'entidades'=>(int)$pdo->query('SELECT count(*) FROM entidades')->fetchColumn(),
    'reservas'=>(int)$pdo->query('SELECT count(*) FROM reservas')->fetchColumn(),
    'inscripciones'=>(int)$pdo->query('SELECT count(*) FROM inscripcion')->fetchColumn(),
];
if ($conteosAntes !== $conteosDespues) throw new RuntimeException('La importación alteró conteos funcionales protegidos.');

$oficiales = [
    'provincias'=>(int)$pdo->query('SELECT count(*) FROM provincias WHERE codigo_georef IS NOT NULL')->fetchColumn(),
    'localidades'=>(int)$pdo->query('SELECT count(*) FROM localidades WHERE codigo_georef IS NOT NULL')->fetchColumn(),
];
if ($oficiales !== ['provincias'=>24,'localidades'=>4028] || $resultado !== $oficiales) {
    throw new RuntimeException('Los conteos finales del catálogo oficial no son los esperados.');
}

echo json_encode(['estado'=>'GEOREF_IMPORT_OK']+$oficiales+$conteosDespues, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
