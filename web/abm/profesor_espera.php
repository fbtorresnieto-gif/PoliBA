<?php
$base_path = '../';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Validar que sea Profesor
redirect_if_not_logged_in(['Profesor']);

$user = get_logged_user();
$error_msg = '';
$success_msg = '';
$modulos_profesor = [];
$modulo_seleccionado_id = filter_input(INPUT_GET, 'modulo_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

try {
    $stmt_modulos = $pdo->prepare("SELECT id, nombre FROM modulos WHERE fk_usuario_profesor = ? AND multipro_modulo_disponible(id) ORDER BY nombre, id");
    $stmt_modulos->execute([$user['id']]);
    $modulos_profesor = $stmt_modulos->fetchAll();
    $ids_modulos = array_map('intval', array_column($modulos_profesor, 'id'));
    if (!in_array($modulo_seleccionado_id, $ids_modulos, true)) {
        $modulo_seleccionado_id = $ids_modulos[0] ?? 0;
    }
} catch (PDOException $e) {
    $error_msg = 'No se pudieron cargar tus módulos.';
}

// Procesar traspaso de Lista de Espera a Cupo Activo
if (isset($_GET['activar_inscripcion'])) {
    $ins_id = intval($_GET['activar_inscripcion']);
    $cl_id = intval($_GET['modulo_id']);
    
    // Validar que la modulo pertenezca a este profesor
    try {
        $stmt = $pdo->prepare("SELECT cupo_maximo FROM modulos WHERE id = ? AND fk_usuario_profesor = ? AND multipro_modulo_disponible(id)");
        $stmt->execute([$cl_id, $user['id']]);
        $modulo_info = $stmt->fetch();
        
        if ($modulo_info) {
            // Contar inscriptos activos actuales
            $stmt_cnt = $pdo->prepare("SELECT COUNT(*) FROM inscripcion WHERE fk_modulo = ? AND lista_espera = FALSE AND estado = 'activo'");
            $stmt_cnt->execute([$cl_id]);
            $inscriptos = $stmt_cnt->fetchColumn();
            
            if ($inscriptos < $modulo_info['cupo_maximo']) {
                $stmt_act = $pdo->prepare("UPDATE inscripcion SET lista_espera = FALSE WHERE id = ? AND fk_modulo = ? AND estado = 'activo'");
                if ($stmt_act->execute([$ins_id,$cl_id])) {
                    $success_msg = 'Alumno incorporado al módulo con éxito. Recuerda contactarlo por teléfono para avisarle.';
                }
            } else {
                $error_msg = 'No se puede incorporar al alumno: el cupo del módulo está completo.';
            }
        } else {
            $error_msg = 'Acceso no autorizado al módulo.';
        }
    } catch (PDOException $e) {
        $error_msg = 'Error al actualizar base de datos.';
    }
}

// Cargar alumnos en Lista de Espera para las modulos dictadas por este profesor
$lista_espera = [];
try {
    $stmt = $pdo->prepare("
        SELECT i.id as inscripcion_id, COALESCE(i.fecha_le, i.fecha) AS fecha_lista_espera, i.fk_modulo,
               c.nombre as modulo_nombre, c.cupo_maximo,
               u.nombre as alu_nombre, u.apellido as alu_apellido, u.dni as alu_dni, u.telefono as alu_telefono, u.email as alu_email,
               m.nombre as men_nombre, m.apellido as men_apellido, m.dni as men_dni,
               tutor.telefono as tut_telefono, tutor.email as tut_email
        FROM inscripcion i
        JOIN modulos c ON i.fk_modulo = c.id
        LEFT JOIN usuarios u ON i.fk_usuario = u.id
        LEFT JOIN menores m ON i.fk_menor = m.id
        LEFT JOIN usuarios tutor ON m.fk_usuario = tutor.id
        WHERE c.fk_usuario_profesor = ? AND c.id = ? AND i.lista_espera = TRUE AND i.estado = 'activo' AND multipro_modulo_disponible(c.id)
        ORDER BY COALESCE(i.fecha_le, i.fecha) ASC, i.id ASC
    ");
    $stmt->execute([$user['id'], $modulo_seleccionado_id]);
    $lista_espera = $stmt->fetchAll();
    
    // Calcular vacantes para cada modulo de este profesor en lista
    $vacantes_por_modulo = [];
    foreach ($lista_espera as &$item) {
        $cl_id = $item['fk_modulo'];
        if (!isset($vacantes_por_modulo[$cl_id])) {
            $stmt_cnt = $pdo->prepare("SELECT COUNT(*) FROM inscripcion WHERE fk_modulo = ? AND lista_espera = FALSE AND estado = 'activo'");
            $stmt_cnt->execute([$cl_id]);
            $activos = $stmt_cnt->fetchColumn();
            $vacantes_por_modulo[$cl_id] = max(0, $item['cupo_maximo'] - $activos);
        }
        $item['vacantes_disponibles'] = $vacantes_por_modulo[$cl_id];
    }
    unset($item);
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="mb-4">
        <h2 class="fw-bold text-dark m-0">Gestión de Lista de Espera</h2>
        <small class="text-muted">Administración de vacantes para tus módulos</small>
    </div>

    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>
    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>

    <form method="GET" action="profesor_espera.php" class="row g-2 align-items-end mb-4">
        <div class="col-md-6 col-lg-4">
            <label for="modulo-espera" class="form-label fw-bold">Módulo</label>
            <select id="modulo-espera" name="modulo_id" class="form-select rounded-pill px-3" onchange="this.form.submit()" <?= empty($modulos_profesor) ? 'disabled' : ''; ?>>
                <?php if (empty($modulos_profesor)): ?><option>No tenés módulos activos</option><?php endif; ?>
                <?php foreach ($modulos_profesor as $modulo_opcion): ?>
                    <option value="<?= (int)$modulo_opcion['id']; ?>" <?= (int)$modulo_opcion['id'] === $modulo_seleccionado_id ? 'selected' : ''; ?>><?= htmlspecialchars($modulo_opcion['nombre']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto"><noscript><button type="submit" class="btn btn-dark rounded-pill px-4">Ver módulo</button></noscript></div>
    </form>

    <div class="table-responsive">
        <table class="table table-poliba table-striped align-middle">
            <thead>
                <tr>
                    <th>Ingreso a lista de espera</th>
                    <th>Módulo</th>
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
                        <td colspan="7" class="text-center text-muted py-4">No hay alumnos en lista de espera para el módulo seleccionado.</td>
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
                            <td><?= date('d/m/Y H:i', strtotime($item['fecha_lista_espera'])); ?> hs</td>
                            <td class="fw-bold"><?= htmlspecialchars($item['modulo_nombre']); ?></td>
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
                                    <a href="profesor_espera.php?activar_inscripcion=<?= $item['inscripcion_id']; ?>&modulo_id=<?= $item['fk_modulo']; ?>"
                                       class="poliba-btn btn-sm text-decoration-none px-3"
                                       onclick="return confirm('¿Seguro deseas incorporar a este alumno al cupo activo? Se liberará una vacante.');">
                                        Pasar a Activo
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" disabled title="No hay vacantes disponibles en el módulo.">
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
