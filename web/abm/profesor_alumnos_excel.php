<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ReporteAlumnosModulo.php';

redirect_if_not_logged_in(['Profesor']);
$user = get_logged_user();
$moduloId = filter_input(INPUT_GET, 'modulo_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$reporte = $moduloId > 0 ? (new ReporteAlumnosModulo($pdo))->obtenerParaProfesor($moduloId, (int) $user['id']) : null;

if ($reporte === null) {
    header('Location: profesor_modulos.php?error=' . urlencode('El módulo solicitado no existe o no está asignado a tu usuario.'));
    exit;
}

function valorSeguroExcel(mixed $valor): string
{
    $texto = $valor === null || $valor === '' ? '-' : (string) $valor;
    return preg_match('/^[=+\-@]/', $texto) ? "'".$texto : $texto;
}

function escribirFilaCsv($salida, array $campos): void
{
    fputcsv($salida, $campos, ';', '"', '');
}

$nombreArchivo = 'alumnos-modulo-'.$moduloId.'-'.date('Ymd').'.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$nombreArchivo.'"');
header('Cache-Control: no-store, private');

$salida = fopen('php://output', 'wb');
fwrite($salida, "\xEF\xBB\xBF");
escribirFilaCsv($salida, ['Módulo', 'Actividad', 'Entidad']);
escribirFilaCsv($salida, [$reporte['modulo']['nombre'], $reporte['modulo']['actividad_nombre'], $reporte['modulo']['entidad_nombre']]);
escribirFilaCsv($salida, []);
$encabezados = ['Tipo', 'Apellido', 'Nombre', 'DNI', 'Fecha de nacimiento', 'Dirección', 'Correo', 'Teléfono', 'Fecha de inscripción'];
if ($reporte['tiene_menores']) {
    array_push($encabezados, 'Relación', 'Apellido del mayor', 'Nombre del mayor', 'DNI del mayor', 'Teléfono del mayor');
}
escribirFilaCsv($salida, $encabezados);

foreach ($reporte['alumnos'] as $alumno) {
    $esMenor = filter_var($alumno['es_menor'], FILTER_VALIDATE_BOOL);
    $fila = [
        $esMenor ? 'Menor' : 'Mayor',
        $alumno['alumno_apellido'], $alumno['alumno_nombre'], $alumno['alumno_dni'],
        $alumno['alumno_fecha_nacimiento'], $alumno['alumno_direccion'],
        $alumno['alumno_email'], $alumno['alumno_telefono'], $alumno['fecha_inscripcion'],
    ];
    if ($reporte['tiene_menores']) {
        array_push(
            $fila,
            $alumno['responsable_relacion'],
            $alumno['responsable_apellido'],
            $alumno['responsable_nombre'],
            $alumno['responsable_dni'],
            $alumno['responsable_telefono']
        );
    }
    escribirFilaCsv($salida, array_map('valorSeguroExcel', $fila));
}

fclose($salida);
