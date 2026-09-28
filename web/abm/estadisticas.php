<?php
$base_path = '../';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Validar que sea Administrador
redirect_if_not_logged_in(['Administrador']);

$user = get_logged_user();
$poli_id = $user['fk_polideportivo']; // Sede administrada

if (!$poli_id) {
    die("Error: El administrador no tiene una entidad asignada.");
}

// 1. Datos de la Entidad
$entidad = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM polideportivos WHERE id = ?");
    $stmt->execute([$poli_id]);
    $entidad = $stmt->fetch();
} catch (PDOException $e) {}

// 2. KPIs Generales de la Entidad
$total_alumnos = 0;
$total_profesores = 0;
$total_actividades = 0;
$total_espacios = 0;
$total_modulos = 0;
$total_reservas = 0;
$total_inscriptos_activos = 0;
$total_lista_espera = 0;

try {
    // Alumnos de la entidad
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE fk_polideportivo = ? AND fk_rol = (SELECT id FROM roles WHERE nombre = 'Alumno')");
    $stmt->execute([$poli_id]);
    $total_alumnos = $stmt->fetchColumn() ?: 0;

    // Profesores
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE fk_polideportivo = ? AND fk_rol = (SELECT id FROM roles WHERE nombre = 'Profesor')");
    $stmt->execute([$poli_id]);
    $total_profesores = $stmt->fetchColumn() ?: 0;

    // Actividades activas
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM deportes WHERE fk_polideportivo = ? AND estado = TRUE");
    $stmt->execute([$poli_id]);
    $total_actividades = $stmt->fetchColumn() ?: 0;

    // Espacios activos
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM canchas WHERE fk_polideportivo = ? AND estado = TRUE");
    $stmt->execute([$poli_id]);
    $total_espacios = $stmt->fetchColumn() ?: 0;

    // Módulos activos
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clases WHERE fk_polideportivo = ? AND estado = TRUE");
    $stmt->execute([$poli_id]);
    $total_modulos = $stmt->fetchColumn() ?: 0;

    // Reservas de espacios de la entidad
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM reservas r
        JOIN canchas c ON r.fk_cancha = c.id
        WHERE c.fk_polideportivo = ? AND r.estado = 'reservado'
    ");
    $stmt->execute([$poli_id]);
    $total_reservas = $stmt->fetchColumn() ?: 0;

    // Inscripciones a módulos
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN i.lista_espera = FALSE AND i.estado = 'activo' THEN 1 ELSE 0 END) as activos,
            SUM(CASE WHEN i.lista_espera = TRUE AND i.estado = 'activo' THEN 1 ELSE 0 END) as espera
        FROM inscripcion i
        JOIN clases c ON i.fk_clase = c.id
        WHERE c.fk_polideportivo = ?
    ");
    $stmt->execute([$poli_id]);
    $insc_res = $stmt->fetch();
    $total_inscriptos_activos = $insc_res['activos'] ?? 0;
    $total_lista_espera = $insc_res['espera'] ?? 0;
} catch (PDOException $e) {}

// 3. Estadísticas Detalladas por Espacio (Canchas)
$stats_espacios = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            c.id, c.nombre, c.techado, c.estado,
            (SELECT COUNT(*) FROM reservas r WHERE r.fk_cancha = c.id AND r.estado = 'reservado') as reservas_activas,
            (SELECT COUNT(*) FROM reservas r WHERE r.fk_cancha = c.id AND r.estado = 'cancelado') as reservas_canceladas,
            (SELECT COUNT(*) FROM clases cl WHERE cl.fk_canchas = c.id AND cl.estado = TRUE) as modulos_asignados
        FROM canchas c
        WHERE c.fk_polideportivo = ?
        ORDER BY reservas_activas DESC, c.nombre ASC
    ");
    $stmt->execute([$poli_id]);
    $stats_espacios = $stmt->fetchAll();
} catch (PDOException $e) {}

// 4. Estadísticas Detalladas por Actividad (Deportes)
$stats_actividades = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            d.id, d.nombre, d.estado,
            COUNT(DISTINCT c.id) as total_modulos,
            COALESCE(SUM(c.cupo_maximo), 0) as cupo_total,
            (
                SELECT COUNT(*)
                FROM inscripcion i
                JOIN clases cl ON i.fk_clase = cl.id
                WHERE cl.fk_deporte = d.id AND i.lista_espera = FALSE AND i.estado = 'activo'
            ) as alumnos_inscriptos,
            (
                SELECT COUNT(*)
                FROM inscripcion i
                JOIN clases cl ON i.fk_clase = cl.id
                WHERE cl.fk_deporte = d.id AND i.lista_espera = TRUE AND i.estado = 'activo'
            ) as alumnos_espera
        FROM deportes d
        LEFT JOIN clases c ON c.fk_deporte = d.id AND c.estado = TRUE
        WHERE d.fk_polideportivo = ?
        GROUP BY d.id, d.nombre, d.estado
        ORDER BY alumnos_inscriptos DESC, d.nombre ASC
    ");
    $stmt->execute([$poli_id]);
    $stats_actividades = $stmt->fetchAll();
} catch (PDOException $e) {}

// 5. Demografía de Alumnos por Categoría de Edad
$demografia = [
    'Infantiles (6 a 12)' => 0,
    'Juveniles (13 a 17)' => 0,
    'Mayores (18 a 59)' => 0,
    'Adultos Mayores (60+)' => 0
];
try {
    // Alumnos mayores registrados en esta sede
    $stmt = $pdo->prepare("SELECT fecha_nacimiento FROM usuarios WHERE fk_polideportivo = ? AND fk_rol = (SELECT id FROM roles WHERE nombre = 'Alumno') AND fecha_nacimiento IS NOT NULL");
    $stmt->execute([$poli_id]);
    $fechas_alumnos = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Menores asociados a tutores de esta sede
    $stmt = $pdo->prepare("
        SELECT m.fecha_nacimiento 
        FROM menores m
        JOIN usuarios u ON m.fk_usuario = u.id
        WHERE u.fk_polideportivo = ? AND m.fecha_nacimiento IS NOT NULL
    ");
    $stmt->execute([$poli_id]);
    $fechas_menores = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $todas_fechas = array_merge($fechas_alumnos, $fechas_menores);
    $today = new DateTime();
    foreach ($todas_fechas as $f) {
        $bdate = new DateTime($f);
        $edad = $today->diff($bdate)->y;
        if ($edad >= 6 && $edad <= 12) {
            $demografia['Infantiles (6 a 12)']++;
        } elseif ($edad >= 13 && $edad <= 17) {
            $demografia['Juveniles (13 a 17)']++;
        } elseif ($edad >= 18 && $edad <= 59) {
            $demografia['Mayores (18 a 59)']++;
        } elseif ($edad >= 60) {
            $demografia['Adultos Mayores (60+)']++;
        }
    }
} catch (PDOException $e) {}

// 6. Horarios Más Solicitados en Reservas
$horarios_populares = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.horario, COUNT(*) as cantidad
        FROM reservas r
        JOIN canchas c ON r.fk_cancha = c.id
        WHERE c.fk_polideportivo = ? AND r.estado = 'reservado'
        GROUP BY r.horario
        ORDER BY cantidad DESC, r.horario ASC
        LIMIT 5
    ");
    $stmt->execute([$poli_id]);
    $horarios_populares = $stmt->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <!-- Encabezado de la página -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-2 border-bottom">
        <div>
            <h2 class="fw-bold text-dark m-0"><i class="bi bi-graph-up-arrow me-2" style="color: var(--poliba-olive);"></i>Panel de Estadísticas</h2>
            <p class="text-muted mb-0">Entidad Administrada: <strong><?= htmlspecialchars($entidad['nombre'] ?? $user['fk_polideportivo_nombre'] ?? 'Mi Entidad'); ?></strong></p>
        </div>
        <div class="mt-2 mt-md-0">
            <span class="badge rounded-pill px-3 py-2 text-white" style="background-color: var(--poliba-dark-blue);">
                <i class="bi bi-clock-history me-1"></i> Actualizado al <?= date('d/m/Y'); ?>
            </span>
        </div>
    </div>

    <!-- 1. Tarjetas de KPIs Generales -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="poliba-card p-3 text-center border-start border-4" style="border-color: var(--poliba-olive) !important;">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-bold text-uppercase">Alumnos</span>
                    <i class="bi bi-people-fill fs-3" style="color: var(--poliba-olive);"></i>
                </div>
                <h3 class="fw-bold text-dark m-0"><?= number_format($total_alumnos); ?></h3>
                <small class="text-muted">Inscriptos en la entidad</small>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="poliba-card p-3 text-center border-start border-4" style="border-color: var(--poliba-dark-blue) !important;">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-bold text-uppercase">Espacios</span>
                    <i class="bi bi-grid-3x3-gap-fill fs-3" style="color: var(--poliba-dark-blue);"></i>
                </div>
                <h3 class="fw-bold text-dark m-0"><?= number_format($total_espacios); ?></h3>
                <small class="text-muted">Disponibles para reserva</small>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="poliba-card p-3 text-center border-start border-4" style="border-color: #28a745 !important;">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-bold text-uppercase">Módulos Activos</span>
                    <i class="bi bi-calendar3 fs-3 text-success"></i>
                </div>
                <h3 class="fw-bold text-dark m-0"><?= number_format($total_modulos); ?></h3>
                <small class="text-muted"><?= number_format($total_inscriptos_activos); ?> plazas ocupadas</small>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="poliba-card p-3 text-center border-start border-4" style="border-color: #ff9800 !important;">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-bold text-uppercase">Reservas Activas</span>
                    <i class="bi bi-ticket-perforated-fill fs-3 text-warning"></i>
                </div>
                <h3 class="fw-bold text-dark m-0"><?= number_format($total_reservas); ?></h3>
                <small class="text-muted">Horarios reservados</small>
            </div>
        </div>
    </div>

    <!-- 2. Estadísticas por Espacio (Canchas) -->
    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="poliba-container-card mt-0 h-100 p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="fw-bold text-dark m-0"><i class="bi bi-grid-3x3-gap me-2" style="color: var(--poliba-olive);"></i>Rendimiento por Espacio</h4>
                    <span class="badge bg-light text-dark border"><?= count($stats_espacios); ?> espacios registrados</span>
                </div>
                <p class="text-muted small mb-3">Métricas de ocupación, reservas acumuladas y módulos vinculados a cada espacio de la entidad.</p>

                <?php if (empty($stats_espacios)): ?>
                    <div class="alert alert-info text-center py-3">No hay espacios registrados en esta entidad.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Espacio</th>
                                    <th>Tipo</th>
                                    <th class="text-center">Reservas Activas</th>
                                    <th class="text-center">Reservas Canceladas</th>
                                    <th class="text-center">Módulos Asignados</th>
                                    <th class="text-center">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats_espacios as $esp): ?>
                                    <tr>
                                        <td class="fw-bold text-dark">
                                            <i class="bi bi-geo-alt-fill me-1 text-muted small"></i>
                                            <?= htmlspecialchars($esp['nombre']); ?>
                                        </td>
                                        <td>
                                            <span class="badge rounded-pill <?= $esp['techado'] ? 'bg-dark' : 'bg-secondary'; ?>">
                                                <?= $esp['techado'] ? 'Techado' : 'Descubierto'; ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="fw-bold text-success fs-6"><?= $esp['reservas_activas']; ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="text-muted"><?= $esp['reservas_canceladas']; ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-info text-dark rounded-pill px-3"><?= $esp['modulos_asignados']; ?> módulos</span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge rounded-pill <?= $esp['estado'] ? 'bg-success' : 'bg-danger'; ?>">
                                                <?= $esp['estado'] ? 'Activo' : 'Inactivo'; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Horarios Más Solicitados -->
        <div class="col-lg-4">
            <div class="poliba-container-card mt-0 h-100 p-4">
                <h4 class="fw-bold text-dark mb-3"><i class="bi bi-clock-fill me-2 text-primary"></i>Horarios Más Concurridos</h4>
                <p class="text-muted small mb-3">Franjas horarias con mayor cantidad de reservas confirmadas en la entidad.</p>

                <?php if (empty($horarios_populares)): ?>
                    <p class="text-muted text-center py-4">Aún no se registran reservas confirmadas.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($horarios_populares as $index => $hp): 
                            $hora_formateada = date('H:i', strtotime($hp['horario'])) . ' hs';
                        ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-bottom">
                                <div>
                                    <span class="badge rounded-circle text-white me-2" style="background-color: var(--poliba-dark-blue); width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center;">
                                        <?= $index + 1; ?>
                                    </span>
                                    <strong class="text-dark"><?= $hora_formateada; ?></strong>
                                </div>
                                <span class="badge bg-success rounded-pill px-3 py-1"><?= $hp['cantidad']; ?> reservas</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="mt-4 p-3 rounded" style="background: rgba(107, 163, 175, 0.12); border-left: 4px solid var(--poliba-olive);">
                    <small class="text-dark fw-bold d-block"><i class="bi bi-info-circle-fill me-1"></i>Lista de Espera en Módulos</small>
                    <span class="fs-5 fw-bold text-dark"><?= $total_lista_espera; ?></span>
                    <small class="text-muted">alumnos esperando vacante en total.</small>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Demografía y Ocupación por Actividad -->
    <div class="row g-4 mb-4">
        <!-- Demografía por Categoría de Edad -->
        <div class="col-lg-5">
            <div class="poliba-container-card mt-0 h-100 p-4">
                <h4 class="fw-bold text-dark mb-3"><i class="bi bi-pie-chart-fill me-2" style="color: var(--poliba-dark-blue);"></i>Demografía de Alumnos</h4>
                <p class="text-muted small mb-4">Distribución de edades de alumnos y menores registrados en esta entidad.</p>

                <?php 
                $total_demo = array_sum($demografia);
                foreach ($demografia as $categoria_label => $cantidad): 
                    $porcentaje = $total_demo > 0 ? round(($cantidad / $total_demo) * 100) : 0;
                ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold small text-dark"><?= $categoria_label; ?></span>
                            <span class="small text-muted"><strong><?= $cantidad; ?></strong> (<?= $porcentaje; ?>%)</span>
                        </div>
                        <div class="progress" style="height: 10px; border-radius: 5px;">
                            <div class="progress-bar" role="progressbar" 
                                 style="width: <?= $porcentaje; ?>%; background-color: var(--poliba-olive);" 
                                 aria-valuenow="<?= $porcentaje; ?>" aria-valuemin="0" aria-valuemax="100">
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Desglose por Actividades -->
        <div class="col-lg-7">
            <div class="poliba-container-card mt-0 h-100 p-4">
                <h4 class="fw-bold text-dark mb-3"><i class="bi bi-trophy-fill me-2 text-warning"></i>Ocupación por Actividad</h4>
                <p class="text-muted small mb-3">Módulos, capacidad de cupo disponible y alumnos inscriptos por actividad.</p>

                <?php if (empty($stats_actividades)): ?>
                    <div class="alert alert-info text-center py-3">No hay actividades registradas en esta entidad.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Actividad</th>
                                    <th class="text-center">Módulos</th>
                                    <th class="text-center">Inscriptos</th>
                                    <th class="text-center">Cupo Total</th>
                                    <th class="text-center">Ocupación</th>
                                    <th class="text-center">Lista Espera</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats_actividades as $act): 
                                    $ocup_pct = $act['cupo_total'] > 0 ? min(100, round(($act['alumnos_inscriptos'] / $act['cupo_total']) * 100)) : 0;
                                    $badge_class = 'bg-success';
                                    if ($ocup_pct >= 90) $badge_class = 'bg-danger';
                                    elseif ($ocup_pct >= 70) $badge_class = 'bg-warning text-dark';
                                ?>
                                    <tr>
                                        <td class="fw-bold text-dark">
                                            <?= htmlspecialchars($act['nombre']); ?>
                                            <?php if (!$act['estado']): ?>
                                                <span class="badge bg-secondary ms-1" style="font-size:0.65rem;">Inactiva</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><?= $act['total_modulos']; ?></td>
                                        <td class="text-center fw-bold text-primary"><?= $act['alumnos_inscriptos']; ?></td>
                                        <td class="text-center text-muted"><?= $act['cupo_total']; ?></td>
                                        <td class="text-center">
                                            <span class="badge <?= $badge_class; ?> rounded-pill px-2">
                                                <?= $ocup_pct; ?>%
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($act['alumnos_espera'] > 0): ?>
                                                <span class="badge bg-warning text-dark rounded-pill"><?= $act['alumnos_espera']; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted small">0</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
