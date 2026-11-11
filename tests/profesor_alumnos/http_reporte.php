<?php
declare(strict_types=1);

function conexionReporte(bool $aplicacion = true): PDO
{
    $base = getenv('MULTIPRO_TEST_DB');
    if (!is_string($base) || !preg_match('/^multipro_test_pagos_[0-9]{14}$/D', $base)) {
        throw new RuntimeException('La prueba sólo admite bases temporales de MultiPro.');
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

function ejecutarReporte(PDO $pdo, string $sql, array $parametros = []): PDOStatement
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);
    return $stmt;
}

function verificaReporte(bool $condicion, string $mensaje): void
{
    if (!$condicion) throw new RuntimeException('FALLO_REPORTE_PROFESOR: '.$mensaje);
}

$root = conexionReporte(false);
$clave = bin2hex(random_bytes(20));
$hash = password_hash($clave, PASSWORD_DEFAULT);
$entidad = (int) ejecutarReporte($root, "INSERT INTO entidades(nombre,direccion,horario_apertura,horario_cierre,tipo_entidad) VALUES('Entidad reporte profesor','Dirección de prueba','00:00','23:59:59','publica') RETURNING id")->fetchColumn();
$actividad = (int) ejecutarReporte($root, "INSERT INTO actividades(nombre,fk_entidad) VALUES('Actividad reporte profesor',?) RETURNING id", [$entidad])->fetchColumn();

$crearUsuario = static function (string $rol, string $nombre, string $apellido, string $dni, string $email, string $telefono) use ($root, $entidad, $hash): int {
    return (int) ejecutarReporte($root, "INSERT INTO usuarios(nombre,apellido,dni,direccion,email,contrasena,telefono,fecha_nacimiento,fk_entidad,fk_rol) VALUES(?,?,?,'Dirección alumno',?,?,?,'2000-02-03',?,(SELECT id FROM roles WHERE lower(nombre)=lower(?) LIMIT 1)) RETURNING id", [$nombre, $apellido, $dni, $email, $hash, $telefono, $entidad, $rol])->fetchColumn();
};

$profesor = $crearUsuario('Profesor', 'Paula', 'Docente', '99000101', 'profesor-reporte@example.invalid', '11110001');
$profesorAjeno = $crearUsuario('Profesor', 'Omar', 'Ajeno', '99000102', 'profesor-ajeno@example.invalid', '11110002');
$alumno = $crearUsuario('Alumno', 'Ana', 'Adulta', '99000103', 'alumna-adulta@example.invalid', '11110003');
$responsable = $crearUsuario('Alumno', 'Rita', 'Responsable', '99000104', 'responsable@example.invalid', '11110004');
$menor = (int) ejecutarReporte($root, "INSERT INTO menores(nombre,apellido,dni,direccion,fecha_nacimiento,relacion,fk_usuario) VALUES('Milo','Menor','99000105','Dirección menor','2015-04-05','Hijo/a',?) RETURNING id", [$responsable])->fetchColumn();
$modulo = (int) ejecutarReporte($root, "INSERT INTO modulos(nombre,descripcion,horario_inicio,horario_cierre,cupo_maximo,fk_usuario_profesor,fk_actividad,fk_entidad,estado) VALUES('Módulo reporte profesor','Fixture','10:00','11:00',20,?,?,?,TRUE) RETURNING id", [$profesor, $actividad, $entidad])->fetchColumn();
$moduloSoloAdultos = (int) ejecutarReporte($root, "INSERT INTO modulos(nombre,descripcion,horario_inicio,horario_cierre,cupo_maximo,fk_usuario_profesor,fk_actividad,fk_entidad,estado) VALUES('Módulo sólo adultos','Fixture','12:00','13:00',20,?,?,?,TRUE) RETURNING id", [$profesor, $actividad, $entidad])->fetchColumn();
$inscripcionAdulto = (int) ejecutarReporte($root, 'INSERT INTO inscripcion(fk_modulo,fk_usuario,lista_espera,estado) VALUES(?,?,FALSE,\'activo\') RETURNING id', [$modulo, $alumno])->fetchColumn();
$inscripcionMenor = (int) ejecutarReporte($root, 'INSERT INTO inscripcion(fk_modulo,fk_menor,lista_espera,estado) VALUES(?,?,FALSE,\'activo\') RETURNING id', [$modulo, $menor])->fetchColumn();
ejecutarReporte($root, 'INSERT INTO inscripcion(fk_modulo,fk_usuario,lista_espera,estado) VALUES(?,?,FALSE,\'activo\')', [$moduloSoloAdultos, $alumno]);

$entorno = array_merge(getenv(), [
    'PGHOST' => '127.0.0.1', 'PGPORT' => '5433', 'PGDATABASE' => getenv('MULTIPRO_TEST_DB'),
    'PGUSER' => 'multipro_app_local', 'PGPASSWORD' => getenv('MULTIPRO_TEST_APP_PASSWORD'),
    'PGSSLMODE' => 'disable', 'MAIL_ENABLED' => 'false',
]);
$socket = @fsockopen('127.0.0.1', 8086, $errno, $error, 0.1);
if ($socket) { fclose($socket); throw new RuntimeException('Puerto HTTP de prueba ocupado.'); }
$log = tempnam(sys_get_temp_dir(), 'multipro-profesor-alumnos-');
$pipes = [];
$servidor = proc_open([PHP_BINARY, '-c', getenv('MULTIPRO_TEST_PHP_INI'), '-S', '127.0.0.1:8086', '-t', realpath(__DIR__.'/../../web')], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $entorno);
if (!is_resource($servidor)) throw new RuntimeException('No se pudo iniciar el servidor HTTP temporal.');
fclose($pipes[0]);

try {
    $listo = false;
    for ($intento = 0; $intento < 50; $intento++) {
        $socket = @fsockopen('127.0.0.1', 8086, $errno, $error, 0.1);
        if ($socket) { fclose($socket); $listo = true; break; }
        usleep(50000);
    }
    verificaReporte($listo, 'servidor temporal disponible');

    $http = static function (string $ruta, ?array $datos, string &$cookie): array {
        $opciones = ['method' => $datos === null ? 'GET' : 'POST', 'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0, 'header' => 'Cookie: '.$cookie."\r\n"];
        if ($datos !== null) {
            $opciones['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $opciones['content'] = http_build_query($datos);
        }
        $contenido = (string) file_get_contents('http://127.0.0.1:8086/'.$ruta, false, stream_context_create(['http' => $opciones]));
        foreach ($http_response_header ?? [] as $encabezado) {
            if (preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i', $encabezado, $coincide)) $cookie = $coincide[1];
        }
        preg_match('/ ([0-9]{3}) /', $http_response_header[0] ?? '', $codigo);
        verificaReporte(!str_contains($contenido, 'Fatal error') && !str_contains($contenido, 'Warning:'), 'respuesta PHP válida para '.$ruta);
        return [(int) ($codigo[1] ?? 0), $contenido];
    };

    $cookie = '';
    [$codigo] = $http('login.php', ['action' => 'login', 'email' => 'profesor-reporte@example.invalid', 'contrasena' => $clave], $cookie);
    verificaReporte($codigo === 302 && $cookie !== '', 'autenticación del profesor');
    [$codigo, $modulos] = $http('abm/profesor_modulos.php', null, $cookie);
    verificaReporte($codigo === 200 && str_contains($modulos, 'Ver Alumnos'), 'tarjeta del módulo muestra Ver Alumnos');
    verificaReporte(str_contains($modulos, 'profesor_alumnos.php?modulo_id='.$modulo), 'botón enlaza al módulo correcto');

    [$codigo, $reporte] = $http('abm/profesor_alumnos.php?modulo_id='.$modulo, null, $cookie);
    verificaReporte($codigo === 200, 'reporte autorizado responde HTTP 200');
    foreach (['Ana', 'Adulta', '99000103', 'alumna-adulta@example.invalid', '11110003', 'Milo', 'Menor', '99000105', 'Hijo/a', 'Rita', 'Responsable', '99000104', '11110004'] as $dato) {
        verificaReporte(str_contains($reporte, $dato), 'reporte contiene '.$dato);
    }
    verificaReporte(str_contains($reporte, 'Apellido del mayor'), 'reporte presenta las columnas del responsable');
    verificaReporte(str_contains($reporte, 'Exportar Excel') && str_contains($reporte, 'Exportar PDF'), 'reporte ofrece ambas exportaciones');
    verificaReporte(str_contains($reporte, 'Baja Alumno'), 'reporte ofrece baja por cada inscripción activa');
    verificaReporte(str_contains($reporte, 'Baja seleccionados') && str_contains($reporte, 'inscripciones[]'), 'reporte permite seleccionar y dar de baja varios alumnos');
    verificaReporte(strpos($reporte, 'Acciones') < strpos($reporte, 'Tipo'), 'acciones aparecen al inicio de cada fila');
    verificaReporte(str_contains($reporte, 'reporte-alumnos-scroll'), 'reporte ofrece desplazamiento horizontal');
    verificaReporte(!str_contains($reporte, $hash), 'reporte no expone la contraseña cifrada');

    [$codigo, $excel] = $http('abm/profesor_alumnos_excel.php?modulo_id='.$modulo, null, $cookie);
    verificaReporte($codigo === 200, 'exportación Excel autorizada responde HTTP 200');
    verificaReporte(str_starts_with($excel, "\xEF\xBB\xBFMódulo;Actividad;Entidad"), 'Excel comienza con BOM UTF-8 y encabezado CSV');
    verificaReporte(!str_contains($excel, 'Deprecated') && !str_contains($excel, '<br'), 'Excel no contiene advertencias ni HTML');
    verificaReporte(!str_contains($excel, 'Baja Alumno') && !str_contains($excel, 'Baja seleccionados') && !str_contains($excel, 'Acciones'), 'Excel no incluye acciones de baja');
    foreach (['Apellido del mayor', 'Milo', 'Rita', '99000104', '11110004'] as $datoExcel) {
        verificaReporte(str_contains($excel, $datoExcel), 'Excel contiene '.$datoExcel);
    }

    [$codigo, $reporteSoloAdultos] = $http('abm/profesor_alumnos.php?modulo_id='.$moduloSoloAdultos, null, $cookie);
    verificaReporte($codigo === 200 && str_contains($reporteSoloAdultos, 'Ana'), 'reporte de adultos autorizado');
    verificaReporte(!str_contains($reporteSoloAdultos, 'Apellido del mayor') && !str_contains($reporteSoloAdultos, 'datos del mayor a cargo'), 'reporte sin menores omite columnas y ayuda del responsable');
    [$codigo, $excelSoloAdultos] = $http('abm/profesor_alumnos_excel.php?modulo_id='.$moduloSoloAdultos, null, $cookie);
    verificaReporte($codigo === 200 && str_contains($excelSoloAdultos, 'Ana'), 'Excel de adultos autorizado');
    verificaReporte(!str_contains($excelSoloAdultos, 'Apellido del mayor') && !str_contains($excelSoloAdultos, 'Relación'), 'Excel sin menores omite columnas del responsable');

    verificaReporte((bool) preg_match('/name="csrf_profesor_alumnos" value="([^"]+)"/', $reporte, $csrfBaja), 'reporte incluye protección CSRF');
    [$codigo] = $http('abm/profesor_alumnos.php', [
        'action' => 'baja_alumno', 'modulo_id' => $modulo,
        'inscripcion_id' => $inscripcionAdulto, 'csrf_profesor_alumnos' => 'token-invalido',
    ], $cookie);
    verificaReporte($codigo === 403, 'baja rechaza token CSRF inválido');
    verificaReporte(ejecutarReporte($root, 'SELECT estado FROM inscripcion WHERE id=?', [$inscripcionAdulto])->fetchColumn() === 'activo', 'CSRF inválido no modifica la inscripción');
    [$codigo] = $http('abm/profesor_alumnos.php', [
        'action' => 'baja_alumno', 'modulo_id' => $modulo,
        'inscripcion_id' => $inscripcionAdulto, 'csrf_profesor_alumnos' => $csrfBaja[1],
    ], $cookie);
    verificaReporte($codigo === 302, 'baja válida redirige después de aplicar');
    verificaReporte(ejecutarReporte($root, 'SELECT estado FROM inscripcion WHERE id=?', [$inscripcionAdulto])->fetchColumn() === 'baja', 'inscripción queda en estado baja');
    [$codigo, $reporteTrasBaja] = $http('abm/profesor_alumnos.php?modulo_id='.$modulo, null, $cookie);
    verificaReporte($codigo === 200 && !str_contains($reporteTrasBaja, 'Ana'), 'alumno dado de baja desaparece del módulo del profesor');

    $cookieAlumno = '';
    [$codigo] = $http('login.php', ['action' => 'login', 'email' => 'alumna-adulta@example.invalid', 'contrasena' => $clave], $cookieAlumno);
    verificaReporte($codigo === 302 && $cookieAlumno !== '', 'autenticación del alumno dado de baja');
    [$codigo, $perfilAlumno] = $http('perfil.php', null, $cookieAlumno);
    verificaReporte($codigo === 200 && str_contains($perfilAlumno, 'Inactivo x Baja'), 'perfil del alumno informa Inactivo x Baja');

    [$codigo, $formularioInscripcion] = $http('abm/alumno_inscripcion.php?modulo_id='.$modulo, null, $cookieAlumno);
    verificaReporte($codigo === 200 && (bool) preg_match('/name="csrf_inscripcion" value="([^"]+)"/', $formularioInscripcion, $csrfInscripcion), 'formulario permite iniciar una nueva inscripción');
    [$codigo, $respuestaInscripcion] = $http('abm/alumno_inscripcion.php', [
        'action' => 'inscribirse', 'csrf_inscripcion' => $csrfInscripcion[1],
        'modulo_id' => $modulo, 'inscribir_a' => 'tutor',
    ], $cookieAlumno);
    verificaReporte($codigo === 200 && str_contains($respuestaInscripcion, 'Inscripción completada con éxito'), 'alumno dado de baja puede reinscribirse');
    verificaReporte((int) ejecutarReporte($root, "SELECT count(*) FROM inscripcion WHERE fk_modulo=? AND fk_usuario=? AND estado='activo'", [$modulo, $alumno])->fetchColumn() === 1, 'reinscripción crea una única inscripción activa');
    verificaReporte((int) ejecutarReporte($root, "SELECT count(*) FROM inscripcion WHERE fk_modulo=? AND fk_usuario=? AND estado='baja'", [$modulo, $alumno])->fetchColumn() === 1, 'reinscripción conserva el historial de la baja');

    $inscripcionAdultoNueva = (int) ejecutarReporte($root, "SELECT id FROM inscripcion WHERE fk_modulo=? AND fk_usuario=? AND estado='activo'", [$modulo, $alumno])->fetchColumn();
    [$codigo, $reporteParaLote] = $http('abm/profesor_alumnos.php?modulo_id='.$modulo, null, $cookie);
    verificaReporte($codigo === 200 && (bool) preg_match('/name="csrf_profesor_alumnos" value="([^"]+)"/', $reporteParaLote, $csrfLote), 'reporte prepara baja múltiple protegida');
    [$codigo] = $http('abm/profesor_alumnos.php', [
        'action' => 'baja_alumnos', 'modulo_id' => $modulo,
        'inscripciones' => [$inscripcionAdultoNueva, $inscripcionMenor],
        'csrf_profesor_alumnos' => $csrfLote[1],
    ], $cookie);
    verificaReporte($codigo === 302, 'baja múltiple válida redirige después de aplicar');
    verificaReporte((int) ejecutarReporte($root, "SELECT count(*) FROM inscripcion WHERE id IN (?,?) AND estado='baja'", [$inscripcionAdultoNueva, $inscripcionMenor])->fetchColumn() === 2, 'baja múltiple actualiza todas las inscripciones seleccionadas');

    $cookieAjena = '';
    [$codigo] = $http('login.php', ['action' => 'login', 'email' => 'profesor-ajeno@example.invalid', 'contrasena' => $clave], $cookieAjena);
    verificaReporte($codigo === 302 && $cookieAjena !== '', 'autenticación del profesor ajeno');
    [$codigo, $denegado] = $http('abm/profesor_alumnos.php?modulo_id='.$modulo, null, $cookieAjena);
    verificaReporte($codigo === 302 && !str_contains($denegado, 'Ana'), 'profesor ajeno no accede al reporte');
    [$codigo] = $http('abm/profesor_alumnos_excel.php?modulo_id='.$modulo, null, $cookieAjena);
    verificaReporte($codigo === 302, 'profesor ajeno no exporta el reporte');

    $anonimo = '';
    [$codigo] = $http('abm/profesor_alumnos.php?modulo_id='.$modulo, null, $anonimo);
    verificaReporte($codigo === 302, 'usuario anónimo es redirigido');

    echo "REPORTE_PROFESOR_ALUMNOS_HTTP_OK datos_mayor_menor_responsable_autorizacion\n";
} finally {
    proc_terminate($servidor);
    proc_close($servidor);
    if (is_string($log) && file_exists($log)) unlink($log);
}
