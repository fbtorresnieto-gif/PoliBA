<?php
declare(strict_types=1);

function conexionPrueba(bool $aplicacion = true): PDO
{
    $base = getenv('MULTIPRO_TEST_DB');
    if (!is_string($base) || !preg_match('/^multipro_test_pagos_[0-9]{14}$/D', $base)) {
        throw new RuntimeException('La prueba HTTP sólo admite bases temporales de MultiPro.');
    }
    $pdo = new PDO(
        'pgsql:host=127.0.0.1;port=5433;dbname='.$base.';sslmode=disable',
        (string) getenv('MULTIPRO_TEST_USER'),
        (string) getenv('MULTIPRO_TEST_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    if ($aplicacion) $pdo->exec('SET ROLE multipro_app_local');
    return $pdo;
}

function consulta(PDO $pdo, string $sql, array $parametros = []): PDOStatement
{
    $sentencia = $pdo->prepare($sql);
    $sentencia->execute($parametros);
    return $sentencia;
}

function afirmaVerdadero(bool $condicion, string $mensaje): void
{
    if (!$condicion) throw new RuntimeException('FALLO_POSITIVO: '.$mensaje);
}

function afirmaFalso(bool $condicion, string $mensaje): void
{
    if ($condicion) throw new RuntimeException('FALLO_NEGATIVO: '.$mensaje);
}

function afirmaContiene(string $contenido, string $esperado, string $mensaje): void
{
    afirmaVerdadero(str_contains($contenido, $esperado), $mensaje);
}

function afirmaNoContiene(string $contenido, string $prohibido, string $mensaje): void
{
    afirmaFalso(str_contains($contenido, $prohibido), $mensaje);
}

$root = conexionPrueba(false);
$clave = bin2hex(random_bytes(24));
$hash = password_hash($clave, PASSWORD_DEFAULT);
$entidad = (int) consulta($root, "INSERT INTO entidades(nombre,direccion,horario_apertura,horario_cierre,tipo_entidad) VALUES('Privada HTTP fixture','Dirección ficticia','00:00','23:59:59','privada') RETURNING id")->fetchColumn();
$entidadPublica = (int) consulta($root, "INSERT INTO entidades(nombre,direccion,horario_apertura,horario_cierre,tipo_entidad) VALUES('Pública HTTP fixture','Dirección ficticia','00:00','23:59:59','publica') RETURNING id")->fetchColumn();
$actividadPrivada = (int) consulta($root, "INSERT INTO actividades(nombre,fk_entidad) VALUES('Actividad privada HTTP',?) RETURNING id", [$entidad])->fetchColumn();
$actividadPublica = (int) consulta($root, "INSERT INTO actividades(nombre,fk_entidad) VALUES('Actividad pública HTTP',?) RETURNING id", [$entidadPublica])->fetchColumn();
$espacioPrivado = (int) consulta($root, "INSERT INTO espacios(nombre,fk_entidad) VALUES('Espacio privado HTTP',?) RETURNING id", [$entidad])->fetchColumn();
$espacioPublico = (int) consulta($root, "INSERT INTO espacios(nombre,fk_entidad) VALUES('Espacio público HTTP',?) RETURNING id", [$entidadPublica])->fetchColumn();
$hoy = (new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');
$fin = (new DateTimeImmutable('+30 days', new DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');
consulta($root, "INSERT INTO novedades(nombre,descripcion,fecha_inicio,fecha_fin,fk_entidad,estado) VALUES('Novedad privada HTTP','Fixture',?,?,?,true)", [$hoy, $fin, $entidad]);
consulta($root, "INSERT INTO novedades(nombre,descripcion,fecha_inicio,fecha_fin,fk_entidad,estado) VALUES('Novedad pública HTTP','Fixture',?,?,?,true)", [$hoy, $fin, $entidadPublica]);
$crearUsuario = static function (string $rol, string $sufijo) use ($root, $entidad, $hash): int {
    return (int) consulta($root, "INSERT INTO usuarios(nombre,apellido,dni,email,contrasena,fecha_nacimiento,fk_entidad,fk_rol) VALUES('Fixture','Privada',?,?,?,'2000-01-01',?,(SELECT id FROM roles WHERE lower(nombre)=lower(?) LIMIT 1)) RETURNING id", ['http-'.substr(sha1($sufijo), 0, 12), $sufijo.'@example.invalid', $hash, $entidad, $rol])->fetchColumn();
};
$administrador = $crearUsuario('Administrador', 'admin-http');
$profesor = $crearUsuario('Profesor', 'profesor-http');
$token = bin2hex(random_bytes(32));
consulta($root, "INSERT INTO api_sesiones(fk_usuario,token_hash,vence_en) VALUES(?,?,clock_timestamp()+interval '5 minutes')", [$profesor, hash('sha256', $token)]);

$entorno = array_merge(getenv(), [
    'PGHOST' => '127.0.0.1', 'PGPORT' => '5433', 'PGDATABASE' => getenv('MULTIPRO_TEST_DB'),
    'PGUSER' => 'multipro_app_local', 'PGPASSWORD' => getenv('MULTIPRO_TEST_APP_PASSWORD'),
    'PGSSLMODE' => 'disable', 'MAIL_ENABLED' => 'false',
]);
$socket = @fsockopen('127.0.0.1', 8085, $errno, $error, 0.1);
if ($socket) { fclose($socket); throw new RuntimeException('Puerto HTTP de prueba ocupado.'); }
$log = tempnam(sys_get_temp_dir(), 'multipro-privada-http-');
$pipes = [];
$servidor = proc_open([PHP_BINARY, '-c', getenv('MULTIPRO_TEST_PHP_INI'), '-S', '127.0.0.1:8085', '-t', realpath(__DIR__.'/../../web')], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $entorno);
if (!is_resource($servidor)) throw new RuntimeException('No se pudo iniciar el servidor HTTP temporal.');
fclose($pipes[0]);

try {
    $listo = false;
    for ($intento = 0; $intento < 50; $intento++) {
        $socket = @fsockopen('127.0.0.1', 8085, $errno, $error, 0.1);
        if ($socket) { fclose($socket); $listo = true; break; }
        usleep(50000);
    }
    afirmaVerdadero($listo, 'servidor HTTP temporal disponible');

    $http = static function (string $ruta, ?array $datos, string &$cookie, string $bearer = ''): array {
        $opciones = ['method' => $datos === null ? 'GET' : 'POST', 'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0, 'header' => 'Cookie: '.$cookie."\r\n"];
        if ($bearer !== '') $opciones['header'] .= 'Authorization: Bearer '.$bearer."\r\n";
        if ($datos !== null) {
            $opciones['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $opciones['content'] = http_build_query($datos);
        }
        $contenido = (string) file_get_contents('http://127.0.0.1:8085/'.$ruta, false, stream_context_create(['http' => $opciones]));
        foreach ($http_response_header ?? [] as $encabezado) {
            if (preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i', $encabezado, $coincide)) $cookie = $coincide[1];
        }
        preg_match('/ ([0-9]{3}) /', $http_response_header[0] ?? '', $codigo);
        afirmaNoContiene($contenido, 'Fatal error', 'respuesta sin error fatal en '.$ruta);
        afirmaNoContiene($contenido, 'Warning:', 'respuesta sin advertencias PHP en '.$ruta);
        return [(int) ($codigo[1] ?? 0), $contenido];
    };

    $cookie = '';
    [$codigo, $home] = $http('index.php', null, $cookie);
    afirmaVerdadero($codigo === 200, 'Home público responde HTTP 200');
    afirmaContiene($home, 'Novedad privada HTTP', 'Home muestra novedades privadas');
    afirmaContiene($home, 'Novedad pública HTTP', 'Home muestra novedades públicas');
    [$codigo, $entidades] = $http('entidades.php', null, $cookie);
    afirmaVerdadero($codigo === 200, 'Entidades responde HTTP 200');
    afirmaContiene($entidades, 'Privada HTTP fixture', 'Entidades muestra privadas');
    afirmaContiene($entidades, 'Pública HTTP fixture', 'Entidades muestra públicas');
    [$codigo, $actividades] = $http('actividades.php', null, $cookie);
    afirmaVerdadero($codigo === 200, 'Actividades responde HTTP 200');
    afirmaContiene($actividades, 'Actividad pública HTTP', 'Actividades muestra las públicas');
    afirmaNoContiene($actividades, 'Actividad privada HTTP', 'Actividades oculta las privadas');
    [$codigo, $espacios] = $http('espacios.php', null, $cookie);
    afirmaVerdadero($codigo === 200, 'Espacios responde HTTP 200');
    afirmaContiene($espacios, 'Espacio privado HTTP', 'Espacios muestra privados');
    afirmaContiene($espacios, 'Espacio público HTTP', 'Espacios muestra públicos');

    [$codigo] = $http('login.php', ['action' => 'login', 'email' => 'admin-http@example.invalid', 'contrasena' => $clave], $cookie);
    afirmaVerdadero($codigo === 302 && $cookie !== '', 'autenticación del administrador privado');

    [$codigo, $html] = $http('abm/estadisticas.php', null, $cookie);
    afirmaVerdadero($codigo === 200, 'panel privado responde HTTP 200');
    foreach (['Estadísticas', 'Espacios', 'Novedades', 'Ver reservas', 'Mi perfil', 'Total de reservas', 'Reservas por espacio', 'Las entidades privadas muestran'] as $textoPermitido) {
        afirmaContiene($html, $textoPermitido, 'panel contiene '.$textoPermitido);
    }
    foreach (['abm/actividades.php', 'abm/modulos.php', 'abm/profesores.php', 'abm/subcategorias.php', 'abm/alumnos.php', 'Demografía de Alumnos'] as $textoProhibido) {
        afirmaNoContiene($html, $textoProhibido, 'panel no contiene '.$textoProhibido);
    }

    foreach (['actividades.php', 'modulos.php', 'profesores.php', 'subcategorias.php', 'alumnos.php'] as $rutaBloqueada) {
        [$codigo] = $http('abm/'.$rutaBloqueada, null, $cookie);
        afirmaVerdadero($codigo === 403, 'acceso directo bloqueado a '.$rutaBloqueada);
    }
    [$codigo, $perfil] = $http('perfil.php', null, $cookie);
    afirmaVerdadero($codigo === 200, 'administrador privado accede a su perfil');
    afirmaNoContiene($perfil, 'Esta función no está disponible', 'perfil privado no queda bloqueado');

    $anonimo = '';
    [$codigo, $json] = $http('api/v1/entidades.php', null, $anonimo);
    $datos = json_decode($json, true, 512, JSON_THROW_ON_ERROR)['data'] ?? [];
    afirmaVerdadero($codigo === 200, 'API pública responde correctamente');
    afirmaVerdadero(in_array($entidad, array_column($datos, 'id'), true), 'API pública expone la entidad privada en el catálogo');

    [$codigo, $json] = $http('api/v1/novedades.php', null, $anonimo);
    $novedadesApi = json_decode($json, true, 512, JSON_THROW_ON_ERROR)['data'] ?? [];
    afirmaVerdadero($codigo === 200, 'API pública de novedades responde correctamente');
    afirmaVerdadero(in_array('Novedad privada HTTP', array_column($novedadesApi, 'nombre'), true), 'API pública expone novedades privadas');
    afirmaVerdadero(in_array('Novedad pública HTTP', array_column($novedadesApi, 'nombre'), true), 'API pública expone novedades públicas');

    [$codigo, $json] = $http('api/v1/espacios.php?entidad_id='.$entidad, null, $anonimo);
    $espaciosApi = json_decode($json, true, 512, JSON_THROW_ON_ERROR)['data'] ?? [];
    afirmaVerdadero($codigo === 200 && in_array($espacioPrivado, array_column($espaciosApi, 'id'), true), 'API pública lista espacios privados');
    [$codigo] = $http('api/v1/espacio.php?id='.$espacioPrivado, null, $anonimo);
    afirmaVerdadero($codigo === 200, 'API pública abre el detalle de un espacio privado');
    [$codigo] = $http('api/v1/disponibilidad.php?espacio_id='.$espacioPrivado.'&fecha='.$fin, null, $anonimo);
    afirmaVerdadero($codigo === 200, 'API pública consulta disponibilidad de un espacio privado');

    [$codigo, $json] = $http('api/v1/entidades.php', null, $anonimo, $token);
    $datos = json_decode($json, true, 512, JSON_THROW_ON_ERROR)['data'] ?? [];
    afirmaVerdadero($codigo === 200, 'API autenticada responde correctamente');
    afirmaVerdadero(in_array($entidad, array_column($datos, 'id'), true), 'API autenticada expone la entidad privada vinculada');

    echo "PANEL_PRIVADO_HTTP_OK catalogos_autenticacion_menu_perfil_estadisticas_restricciones_api\n";
} finally {
    proc_terminate($servidor);
    proc_close($servidor);
    if (is_string($log) && file_exists($log)) unlink($log);
}
