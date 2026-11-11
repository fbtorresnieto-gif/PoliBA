<?php
$base_path = '../';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ReporteAlumnosModulo.php';

redirect_if_not_logged_in(['Profesor']);
$user = get_logged_user();
$_SESSION['csrf_profesor_alumnos'] ??= bin2hex(random_bytes(32));
$origenModulo = $_SERVER['REQUEST_METHOD'] === 'POST' ? INPUT_POST : INPUT_GET;
$moduloId = filter_input($origenModulo, 'modulo_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$servicioReporte = new ReporteAlumnosModulo($pdo);
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['baja_alumno', 'baja_alumnos'], true)) {
    $csrf = $_POST['csrf_profesor_alumnos'] ?? null;
    if (!is_string($csrf) || !hash_equals($_SESSION['csrf_profesor_alumnos'], $csrf)) {
        http_response_code(403);
        exit('Recargá el listado antes de confirmar la baja.');
    }
    $ids = ($_POST['action'] ?? '') === 'baja_alumno'
        ? [filter_input(INPUT_POST, 'inscripcion_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0]
        : (is_array($_POST['inscripciones'] ?? null) ? $_POST['inscripciones'] : []);
    $cantidad = $moduloId > 0 ? $servicioReporte->darBajaVarias($ids, $moduloId, (int) $user['id']) : 0;
    if ($cantidad < 1) {
        $error_msg = 'No se pudo completar la baja. Seleccioná alumnos activos que pertenezcan a este módulo.';
    } else {
        $_SESSION['csrf_profesor_alumnos'] = bin2hex(random_bytes(32));
        header('Location: profesor_alumnos.php?modulo_id='.$moduloId.'&bajas='.$cantidad);
        exit;
    }
}

$reporte = $moduloId > 0 ? $servicioReporte->obtenerParaProfesor($moduloId, (int) $user['id']) : null;

if ($reporte === null) {
    header('Location: profesor_modulos.php?error=' . urlencode('El módulo solicitado no existe o no está asignado a tu usuario.'));
    exit;
}

header('Cache-Control: no-store, private');
$modulo = $reporte['modulo'];
$alumnos = $reporte['alumnos'];
$tieneMenores = $reporte['tiene_menores'];
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .reporte-alumnos-scroll {
        overflow-x: scroll;
        padding-bottom: .5rem;
        scrollbar-gutter: stable;
    }
    .reporte-alumnos-table { min-width: <?= $tieneMenores ? '2200px' : '1450px'; ?>; }
    .reporte-alumnos-table th,
    .reporte-alumnos-table td { white-space: nowrap; }
    .reporte-alumnos-table .acciones-col { position: sticky; left: 0; z-index: 2; }
    .reporte-alumnos-table thead .acciones-col { z-index: 3; }
    @media print {
        header, footer, .poliba-sidebar, #sidebar-toggle, .reporte-acciones, .alert { display: none !important; }
        .container { max-width: none !important; width: 100% !important; margin: 0 !important; }
        .reporte-alumnos-scroll { overflow: visible !important; }
        .reporte-alumnos-table { min-width: 0 !important; font-size: 8px; }
        .reporte-alumnos-table th, .reporte-alumnos-table td { white-space: normal; padding: 4px !important; }
        @page { size: landscape; margin: 8mm; }
    }
</style>

<div class="container my-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h2 class="fw-bold text-dark m-0">Listado de alumnos</h2>
            <div class="text-muted"><?= htmlspecialchars($modulo['nombre']); ?> · <?= htmlspecialchars($modulo['actividad_nombre']); ?></div>
            <small class="text-muted">Entidad: <?= htmlspecialchars($modulo['entidad_nombre']); ?> · <?= date('H:i', strtotime($modulo['horario_inicio'])); ?> a <?= date('H:i', strtotime($modulo['horario_cierre'])); ?> hs</small>
        </div>
        <div class="d-flex flex-wrap gap-2 reporte-acciones">
            <a href="profesor_alumnos_excel.php?modulo_id=<?= (int) $modulo['id']; ?>" class="btn btn-success rounded-pill px-4"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Exportar Excel</a>
            <button type="button" class="btn btn-danger rounded-pill px-4" onclick="window.print()"><i class="bi bi-file-earmark-pdf me-2"></i>Exportar PDF</button>
            <a href="profesor_modulos.php" class="poliba-btn-dark py-2 px-4 rounded-pill text-decoration-none">Volver a mis módulos</a>
        </div>
    </div>

    <div class="alert alert-info">
        Este listado contiene datos personales y debe utilizarse únicamente para la gestión del módulo.
    </div>
    <?php if (($bajasRealizadas = filter_input(INPUT_GET, 'bajas', FILTER_VALIDATE_INT))): ?>
        <div class="alert alert-success"><?= $bajasRealizadas === 1 ? 'El alumno fue dado de baja del módulo.' : $bajasRealizadas.' alumnos fueron dados de baja del módulo.'; ?></div>
    <?php elseif ($error_msg !== ''): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <?php if ($tieneMenores): ?>
        <div class="small text-muted mb-2 reporte-acciones"><i class="bi bi-arrow-left-right me-2"></i>Desplazá horizontalmente para consultar los datos del mayor a cargo.</div>
    <?php endif; ?>
    <form method="POST" onsubmit="return confirm(this.dataset.confirmacion || '¿Confirmás la baja?');">
        <input type="hidden" name="modulo_id" value="<?= (int) $modulo['id']; ?>">
        <input type="hidden" name="csrf_profesor_alumnos" value="<?= htmlspecialchars($_SESSION['csrf_profesor_alumnos']); ?>">
        <?php if (!empty($alumnos)): ?>
            <div class="reporte-acciones d-flex align-items-center gap-3 mb-2">
                <button type="submit" name="action" value="baja_alumnos" class="btn btn-outline-danger rounded-pill px-4" onclick="this.form.dataset.confirmacion='¿Confirmás la baja de los alumnos seleccionados? Para volver deberán inscribirse nuevamente.';">Baja seleccionados</button>
                <span class="small text-muted">Marcá uno o más alumnos.</span>
            </div>
        <?php endif; ?>
        <div class="reporte-alumnos-scroll">
        <table class="table table-poliba table-striped align-middle reporte-alumnos-table">
            <thead>
                <tr>
                    <th class="reporte-acciones acciones-col">
                        <div class="d-flex align-items-center gap-2">
                            <input type="checkbox" class="form-check-input m-0" id="seleccionar-todos" aria-label="Seleccionar todos los alumnos" onclick="document.querySelectorAll('.seleccion-alumno').forEach(c => c.checked = this.checked);">
                            <span>Acciones</span>
                        </div>
                    </th>
                    <th>Tipo</th>
                    <th>Apellido</th>
                    <th>Nombre</th>
                    <th>DNI</th>
                    <th>Fecha de nacimiento</th>
                    <th>Dirección</th>
                    <th>Correo</th>
                    <th>Teléfono</th>
                    <th>Fecha de inscripción</th>
                    <?php if ($tieneMenores): ?>
                        <th>Relación</th>
                        <th>Apellido del mayor</th>
                        <th>Nombre del mayor</th>
                        <th>DNI del mayor</th>
                        <th>Teléfono del mayor</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($alumnos)): ?>
                    <tr><td colspan="<?= $tieneMenores ? 15 : 10; ?>" class="text-center text-muted py-4">No hay alumnos activos inscriptos en este módulo.</td></tr>
                <?php else: ?>
                    <?php foreach ($alumnos as $alumno): ?>
                        <?php $esMenor = filter_var($alumno['es_menor'], FILTER_VALIDATE_BOOL); ?>
                        <tr>
                            <td class="reporte-acciones acciones-col bg-white">
                                <div class="d-flex align-items-center gap-2">
                                    <input type="checkbox" class="form-check-input m-0 seleccion-alumno" name="inscripciones[]" value="<?= (int) $alumno['inscripcion_id']; ?>" aria-label="Seleccionar a <?= htmlspecialchars($alumno['alumno_nombre'].' '.$alumno['alumno_apellido']); ?>">
                                    <button type="submit" name="action" value="baja_alumno" class="btn btn-sm btn-outline-danger rounded-pill text-nowrap" onclick="this.form.dataset.confirmacion='¿Confirmás la baja del alumno en este módulo? Para volver deberá inscribirse nuevamente.'; this.form.querySelector('#inscripcion-individual').value='<?= (int) $alumno['inscripcion_id']; ?>';">Baja Alumno</button>
                                </div>
                            </td>
                            <td><span class="badge <?= $esMenor ? 'bg-secondary' : 'bg-info text-dark'; ?> rounded-pill"><?= $esMenor ? 'Menor' : 'Mayor'; ?></span></td>
                            <td class="fw-bold"><?= htmlspecialchars($alumno['alumno_apellido']); ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($alumno['alumno_nombre']); ?></td>
                            <td><?= htmlspecialchars($alumno['alumno_dni']); ?></td>
                            <td><?= !empty($alumno['alumno_fecha_nacimiento']) ? date('d/m/Y', strtotime($alumno['alumno_fecha_nacimiento'])) : '-'; ?></td>
                            <td><?= htmlspecialchars($alumno['alumno_direccion'] ?: '-'); ?></td>
                            <td><?= htmlspecialchars($alumno['alumno_email'] ?: '-'); ?></td>
                            <td><?= htmlspecialchars($alumno['alumno_telefono'] ?: '-'); ?></td>
                            <td><?= !empty($alumno['fecha_inscripcion']) ? date('d/m/Y H:i', strtotime($alumno['fecha_inscripcion'])) : '-'; ?></td>
                            <?php if ($tieneMenores): ?>
                                <td><?= htmlspecialchars($alumno['responsable_relacion'] ?: '-'); ?></td>
                                <td><?= htmlspecialchars($alumno['responsable_apellido'] ?: '-'); ?></td>
                                <td><?= htmlspecialchars($alumno['responsable_nombre'] ?: '-'); ?></td>
                                <td><?= htmlspecialchars($alumno['responsable_dni'] ?: '-'); ?></td>
                                <td><?= htmlspecialchars($alumno['responsable_telefono'] ?: '-'); ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
        <input type="hidden" name="inscripcion_id" id="inscripcion-individual" value="">
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
