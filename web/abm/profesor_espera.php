<?php
$base_path = '../';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Validar que sea Profesor
redirect_if_not_logged_in(['Profesor']);

$user = get_logged_user();
$error_msg = '';
$success_msg = '';

// Procesar traspaso de Lista de Espera a Cupo Activo
if (isset($_GET['activar_inscripcion'])) {
    $ins_id = intval($_GET['activar_inscripcion']);
    $cl_id = intval($_GET['clase_id']);
    
    // Validar que la clase pertenezca a este profesor
    try {
        $stmt = $pdo->prepare("SELECT cupo_maximo FROM clases WHERE id = ? AND fk_usuario_profesor = ?");
        $stmt->execute([$cl_id, $user['id']]);
        $clase_info = $stmt->fetch();
        
        if ($clase_info) {
            // Contar inscriptos activos actuales
            $stmt_cnt = $pdo->prepare("SELECT COUNT(*) FROM inscripcion WHERE fk_clase = ? AND lista_espera = FALSE AND estado = 'activo'");
            $stmt_cnt->execute([$cl_id]);
            $inscriptos = $stmt_cnt->fetchColumn();
            
            if ($inscriptos < $clase_info['cupo_maximo']) {
                $stmt_act = $pdo->prepare("UPDATE inscripcion SET lista_espera = FALSE WHERE id = ?");
                if ($stmt_act->execute([$ins_id])) {
                    $success_msg = 'Alumno incorporado a la clase con éxito. Recuerda contactarlo por teléfono para avisarle.';
                }
            } else {
                $error_msg = 'No se puede incorporar al alumno: el cupo de la clase está completo.';
            }
        } else {
            $error_msg = 'Acceso no autorizado a la clase.';
        }
    } catch (PDOException $e) {
        $error_msg = 'Error al actualizar base de datos.';
    }
}

// Cargar alumnos en Lista de Espera para las clases dictadas por este profesor
$lista_espera = [];
try {
    $stmt = $pdo->prepare("
        SELECT i.id as inscripcion_id, i.fecha, i.fk_clase,
               c.nombre as clase_nombre, c.cupo_maximo,
               u.nombre as alu_nombre, u.apellido as alu_apellido, u.dni as alu_dni, u.telefono as alu_telefono, u.email as alu_email,
               m.nombre as men_nombre, m.apellido as men_apellido, m.dni as men_dni,
               tutor.telefono as tut_telefono, tutor.email as tut_email
        FROM inscripcion i
        JOIN clases c ON i.fk_clase = c.id
        LEFT JOIN usuarios u ON i.fk_usuario = u.id
        LEFT JOIN menores m ON i.fk_menor = m.id
        LEFT JOIN usuarios tutor ON m.fk_usuario = tutor.id
        WHERE c.fk_usuario_profesor = ? AND i.lista_espera = TRUE AND i.estado = 'activo'
        ORDER BY i.fecha ASC
    ");
    $stmt->execute([$user['id']]);
    $lista_espera = $stmt->fetchAll();
    
    // Calcular vacantes para cada clase de este profesor en lista
    $vacantes_por_clase = [];
    foreach ($lista_espera as &$item) {
        $cl_id = $item['fk_clase'];
        if (!isset($vacantes_por_clase[$cl_id])) {
            $stmt_cnt = $pdo->prepare("SELECT COUNT(*) FROM inscripcion WHERE fk_clase = ? AND lista_espera = FALSE AND estado = 'activo'");
            $stmt_cnt->execute([$cl_id]);
            $activos = $stmt_cnt->fetchColumn();
            $vacantes_por_clase[$cl_id] = max(0, $item['cupo_maximo'] - $activos);
        }
        $item['vacantes_disponibles'] = $vacantes_por_clase[$cl_id];
    }
    unset($item);
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="mb-4">
        <h2 class="fw-bold text-dark m-0">Gestión de Lista de Espera</h2>
        <small class="text-muted">Administración de vacantes para tus clases</small>
    </div>

    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>
    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-poliba table-striped align-middle">
            <thead>
                <tr>
                    <th>Fecha Registro</th>
                    <th>Clase</th>
                    <th>Alumno</th>
                    <th>DNI</th>
                    <th>Teléfono de Contacto</th>
                    <th>Vacantes Libres</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($lista_espera)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No hay alumnos en lista de espera para tus clases actualmente.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($lista_espera as $item): 
                        $es_menor = !empty($item['men_nombre']);
                        $nombre = $es_menor ? $item['men_nombre'] : $item['alu_nombre'];
                        $apellido = $es_menor ? $item['men_apellido'] : $item['alu_apellido'];
                        $dni = $es_menor ? $item['men_dni'] : $item['alu_dni'];
                        $telefono = $es_menor ? $item['tut_telefono'] : $item['alu_telefono'];
                        $email = $es_menor ? $item['tut_email'] : $item['alu_email'];
                        $has_vacante = $item['vacantes_disponibles'] > 0;
                    ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($item['fecha'])); ?> hs</td>
                            <td class="fw-bold"><?= htmlspecialchars($item['clase_nombre']); ?></td>
                            <td>
                                <div><?= htmlspecialchars($nombre . ' ' . $apellido); ?></div>
                                <?php if ($es_menor): ?>
                                    <small class="text-muted">[Menor a Cargo - Tutor: <?= htmlspecialchars($item['alu_nombre'] . ' ' . $item['alu_apellido']); ?>]</small>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($dni); ?></td>
                            <td>
                                <div class="fw-bold text-dark"><i class="bi bi-telephone-fill me-1 small"></i> <?= htmlspecialchars($telefono ?? 'No cargado'); ?></div>
                                <div class="small text-muted"><i class="bi bi-envelope-fill me-1 small"></i> <?= htmlspecialchars($email); ?></div>
                            </td>
                            <td class="text-center">
                                <span class="badge rounded-pill <?= $has_vacante ? 'bg-success' : 'bg-secondary'; ?>">
                                    <?= $item['vacantes_disponibles']; ?> libres
                                </span>
                            </td>
                            <td>
                                <?php if ($has_vacante): ?>
                                    <a href="profesor_espera.php?activar_inscripcion=<?= $item['inscripcion_id']; ?>&clase_id=<?= $item['fk_clase']; ?>" 
                                       class="poliba-btn btn-sm text-decoration-none px-3"
                                       onclick="return confirm('¿Seguro deseas incorporar a este alumno al cupo activo? Se liberará una vacante.');">
                                        Pasar a Activo
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" disabled title="No hay vacantes disponibles en la clase.">
                                        Sin Vacante
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
