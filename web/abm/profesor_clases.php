<?php
$base_path = '../';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Validar que sea Profesor
redirect_if_not_logged_in(['Profesor']);

$user = get_logged_user();
$error_msg = '';
$success_msg = '';

$clase_id = isset($_GET['clase_id']) ? intval($_GET['clase_id']) : 0;
$clase = null;

// Validar que la clase pertenezca a este profesor
if ($clase_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT c.*, p.nombre as polideportivo_nombre 
            FROM clases c
            JOIN polideportivos p ON c.fk_polideportivo = p.id
            WHERE c.id = ? AND c.fk_usuario_profesor = ? AND c.estado = TRUE
        ");
        $stmt->execute([$clase_id, $user['id']]);
        $clase = $stmt->fetch();
    } catch (PDOException $e) {}
    
    if (!$clase) {
        header("Location: profesor_clases.php");
        exit;
    }
}

// Fecha para tomar asistencia (default: hoy)
$selected_date = isset($_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d');

// Procesar carga de asistencia individual
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'cargar_asistencia') {
    $inscripcion_id = intval($_POST['inscripcion_id']);
    $estado_asistencia = $_POST['estado_asistencia']; // 'presente', 'ausente', 'lluvia'
    $fecha = $_POST['fecha'];
    
    if (in_array($estado_asistencia, ['presente', 'ausente', 'lluvia'])) {
        try {
            // Verificar si ya existe asistencia para esa fecha
            $stmt = $pdo->prepare("SELECT id FROM asistencia WHERE fk_inscripcion = ? AND fecha = ?");
            $stmt->execute([$inscripcion_id, $fecha]);
            $exist = $stmt->fetch();
            
            if ($exist) {
                // Actualizar
                $stmt = $pdo->prepare("UPDATE asistencia SET asistencia = ? WHERE id = ?");
                $stmt->execute([$estado_asistencia, $exist['id']]);
            } else {
                // Insertar
                $stmt = $pdo->prepare("INSERT INTO asistencia (fk_inscripcion, fk_clase, asistencia, fecha) VALUES (?, ?, ?, ?)");
                $stmt->execute([$inscripcion_id, $clase_id, $estado_asistencia, $fecha]);
            }
            $success_msg = 'Asistencia cargada con éxito.';
        } catch (PDOException $e) {
            $error_msg = 'Error al registrar la asistencia en la base de datos.';
        }
    }
}

// Cargar Alumnos de la clase (Inscripciones activas, tanto mayores como menores)
$alumnos = [];
if ($clase) {
    try {
        $stmt = $pdo->prepare("
            SELECT i.id as inscripcion_id, i.lista_espera,
                   u.nombre as alu_nombre, u.apellido as alu_apellido, u.dni as alu_dni,
                   m.nombre as men_nombre, m.apellido as men_apellido, m.dni as men_dni
            FROM inscripcion i
            LEFT JOIN usuarios u ON i.fk_usuario = u.id
            LEFT JOIN menores m ON i.fk_menor = m.id
            WHERE i.fk_clase = ? AND i.estado = 'activo' AND i.lista_espera = FALSE
            ORDER BY u.apellido, u.nombre, m.apellido, m.nombre ASC
        ");
        $stmt->execute([$clase_id]);
        $alumnos = $stmt->fetchAll();
        
        // Cargar asistencia de cada alumno para la fecha seleccionada
        foreach ($alumnos as &$al) {
            $stmt_as = $pdo->prepare("SELECT asistencia FROM asistencia WHERE fk_inscripcion = ? AND fecha = ?");
            $stmt_as->execute([$al['inscripcion_id'], $selected_date]);
            $al['asistencia'] = $stmt_as->fetchColumn() ?: null;
        }
        unset($al);
    } catch (PDOException $e) {}
}

// Cargar Clases de este Profesor (Para la vista general)
$clases_profesor = [];
if ($clase_id == 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT c.*, d.nombre as deporte_nombre, p.nombre as polideportivo_nombre 
            FROM clases c
            JOIN deportes d ON c.fk_deporte = d.id
            JOIN polideportivos p ON c.fk_polideportivo = p.id
            WHERE c.fk_usuario_profesor = ? AND c.estado = TRUE
            ORDER BY c.nombre ASC
        ");
        $stmt->execute([$user['id']]);
        $clases_profesor = $stmt->fetchAll();
    } catch (PDOException $e) {}
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <?php if ($clase_id == 0): ?>
        <!-- VISTA GENERAL: Listado de Clases -->
        <h2 class="section-title text-dark">Mis Clases Asignadas</h2>
        <p class="text-center text-muted mb-4">Selecciona una clase para gestionar tus alumnos y tomar asistencia.</p>
        
        <?php if (empty($clases_profesor)): ?>
            <div class="alert alert-info text-center">No tienes clases de deportes asignadas actualmente.</div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($clases_profesor as $cl): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="poliba-card">
                            <div class="poliba-card-body p-4">
                                <h4 class="fw-bold text-dark mb-2"><?= htmlspecialchars($cl['nombre']); ?></h4>
                                <div class="text-muted small mb-2"><i class="bi bi-trophy"></i> <?= htmlspecialchars($cl['deporte_nombre']); ?></div>
                                <div class="text-muted small mb-3"><i class="bi bi-building"></i> Sede: <?= htmlspecialchars($cl['polideportivo_nombre']); ?></div>
                                <div class="text-dark small mb-3"><i class="bi bi-clock me-2"></i><?= date('H:i', strtotime($cl['horario_inicio'])); ?> - <?= date('H:i', strtotime($cl['horario_cierre'])); ?> hs</div>
                                <div class="d-grid mt-auto">
                                    <a href="profesor_clases.php?clase_id=<?= $cl['id']; ?>" class="poliba-btn text-center text-decoration-none">Tomar Asistencia</a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- VISTA DE ASISTENCIA: Tomar lista en una clase -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold text-dark m-0"><?= htmlspecialchars($clase['nombre']); ?></h2>
                <small class="text-muted">Cargar Asistencia - Sede: <?= htmlspecialchars($clase['polideportivo_nombre']); ?></small>
            </div>
            <a href="profesor_clases.php" class="poliba-btn-dark py-2 px-4 rounded-pill text-decoration-none">Volver</a>
        </div>

        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>
        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>

        <!-- Selector de fecha -->
        <div class="poliba-container-card mt-0 p-4 mb-4">
            <form action="profesor_clases.php" method="GET" class="row align-items-end g-3">
                <input type="hidden" name="clase_id" value="<?= $clase_id; ?>">
                <div class="col-md-5">
                    <label class="form-label fw-bold text-dark">Fecha de Asistencia</label>
                    <input type="date" name="fecha" class="form-control rounded-pill px-4" 
                           value="<?= htmlspecialchars($selected_date); ?>" 
                           onchange="this.form.submit()">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="poliba-btn-dark w-100 rounded-pill py-2">Actualizar Fecha</button>
                </div>
            </form>
        </div>

        <!-- Tabla de Alumnos para Asistencia -->
        <h4 class="fw-bold text-dark mb-3"><i class="bi bi-people me-2"></i>Alumnos Inscriptos</h4>
        <div class="table-responsive">
            <table class="table table-poliba table-striped align-middle">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Apellido</th>
                        <th>DNI</th>
                        <th class="text-center" style="width: 350px;">Asistencia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($alumnos)): ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">No hay alumnos activos inscriptos en esta clase.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($alumnos as $al): 
                            $es_menor = !empty($al['men_nombre']);
                            $nombre = $es_menor ? $al['men_nombre'] : $al['alu_nombre'];
                            $apellido = $es_menor ? $al['men_apellido'] : $al['alu_apellido'];
                            $dni = $es_menor ? $al['men_dni'] : $al['alu_dni'];
                        ?>
                            <tr>
                                <td class="fw-bold"><?= htmlspecialchars($nombre); ?> <?= $es_menor ? '<span class="badge bg-secondary rounded-pill ms-1" style="font-size:0.7rem;">Menor</span>' : ''; ?></td>
                                <td class="fw-bold"><?= htmlspecialchars($apellido); ?></td>
                                <td><?= htmlspecialchars($dni); ?></td>
                                <td>
                                    <!-- Botones de Acción de Asistencia -->
                                    <div class="d-flex justify-content-center gap-2">
                                        <!-- Presente -->
                                        <form action="profesor_clases.php?clase_id=<?= $clase_id; ?>&fecha=<?= $selected_date; ?>" method="POST">
                                            <input type="hidden" name="action" value="cargar_asistencia">
                                            <input type="hidden" name="inscripcion_id" value="<?= $al['inscripcion_id']; ?>">
                                            <input type="hidden" name="estado_asistencia" value="presente">
                                            <input type="hidden" name="fecha" value="<?= htmlspecialchars($selected_date); ?>">
                                            <button type="submit" class="btn btn-sm <?= $al['asistencia'] == 'presente' ? 'btn-success' : 'btn-outline-success'; ?> rounded-pill px-3 py-1 fw-bold">
                                                <i class="bi bi-check-circle-fill me-1"></i> Presente
                                            </button>
                                        </form>

                                        <!-- Ausente -->
                                        <form action="profesor_clases.php?clase_id=<?= $clase_id; ?>&fecha=<?= $selected_date; ?>" method="POST">
                                            <input type="hidden" name="action" value="cargar_asistencia">
                                            <input type="hidden" name="inscripcion_id" value="<?= $al['inscripcion_id']; ?>">
                                            <input type="hidden" name="estado_asistencia" value="ausente">
                                            <input type="hidden" name="fecha" value="<?= htmlspecialchars($selected_date); ?>">
                                            <button type="submit" class="btn btn-sm <?= $al['asistencia'] == 'ausente' ? 'btn-danger' : 'btn-outline-danger'; ?> rounded-pill px-3 py-1 fw-bold">
                                                <i class="bi bi-x-circle-fill me-1"></i> Ausente
                                            </button>
                                        </form>

                                        <!-- Lluvia -->
                                        <form action="profesor_clases.php?clase_id=<?= $clase_id; ?>&fecha=<?= $selected_date; ?>" method="POST">
                                            <input type="hidden" name="action" value="cargar_asistencia">
                                            <input type="hidden" name="inscripcion_id" value="<?= $al['inscripcion_id']; ?>">
                                            <input type="hidden" name="estado_asistencia" value="lluvia">
                                            <input type="hidden" name="fecha" value="<?= htmlspecialchars($selected_date); ?>">
                                            <button type="submit" class="btn btn-sm <?= $al['asistencia'] == 'lluvia' ? 'btn-warning text-dark' : 'btn-outline-warning text-dark'; ?> rounded-pill px-3 py-1 fw-bold">
                                                <i class="bi bi-cloud-rain-fill me-1"></i> Lluvia
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
