<?php
$base_path = '../';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ServicioPromociones.php';

// Validar que sea Profesor
redirect_if_not_logged_in(['Profesor']);

$user = get_logged_user();
$error_msg = '';
$success_msg = '';
$_SESSION['csrf_promocion'] ??= bin2hex(random_bytes(32));

// Procesar promoción de modulo
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'promocionar') {
    $inscripcion_id = filter_var($_POST['inscripcion_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $nueva_modulo_id = filter_var($_POST['nueva_modulo_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    try {
        $csrf=$_POST['csrf_promocion']??'';
        if(!is_string($csrf)||!hash_equals($_SESSION['csrf_promocion'],$csrf))throw new RuntimeException('Recargá la página antes de continuar.');
        if(!$inscripcion_id||!$nueva_modulo_id)throw new RuntimeException('Seleccioná una inscripción y un módulo válidos.');
        $resultado=(new ServicioPromociones($pdo))->promocionar($inscripcion_id,$nueva_modulo_id,(int)$user['id']);
        notificacionesDespacharSilencioso($pdo);
        $success_msg='¡Promoción exitosa! '.(string)$resultado['alumno_nombre'].' fue promovido al módulo '.(string)$resultado['modulo_nuevo'].'. La notificación quedó registrada para envío.';
    } catch (PDOException $e) {
        $error_msg = 'Error al procesar la promoción en la base de datos.';
    } catch (RuntimeException $e) {
        $error_msg = $e->getMessage();
    }
}

// Cargar todos los alumnos inscriptos en modulos dictadas por este profesor
$alumnos_inscritos = [];
try {
    $stmt = $pdo->prepare("
        SELECT i.id as inscripcion_id, i.fk_modulo,
               c.nombre as modulo_actual_nombre, c.fk_actividad, c.fk_entidad,
               COALESCE(sub.edad_minima, cat.edad_minima, 0) as cat_edad_min,
               COALESCE(sub.edad_maxima, cat.edad_maxima, 99) as cat_edad_max,
               u.nombre as alu_nombre, u.apellido as alu_apellido, u.email as alu_email, u.telefono as alu_telefono, u.fecha_nacimiento as alu_nac,
               m.nombre as men_nombre, m.apellido as men_apellido, tutor.email as tut_email, tutor.telefono as tut_telefono, m.fecha_nacimiento as men_nac
        FROM inscripcion i
        JOIN modulos c ON i.fk_modulo = c.id
        LEFT JOIN categoria cat ON c.fk_categoria = cat.id
        LEFT JOIN subcategorias sub ON c.fk_subcategoria = sub.id
        LEFT JOIN usuarios u ON i.fk_usuario = u.id
        LEFT JOIN menores m ON i.fk_menor = m.id
        LEFT JOIN usuarios tutor ON m.fk_usuario = tutor.id
        WHERE c.fk_usuario_profesor = ? AND i.estado = 'activo'
        ORDER BY c.nombre, u.apellido, u.nombre, m.apellido, m.nombre ASC
    ");
    $stmt->execute([$user['id']]);
    $alumnos_inscritos = $stmt->fetchAll();
    
    // Para cada alumno, buscar modulos alternativas en la misma actividad y entidad
    foreach ($alumnos_inscritos as &$al) {
        $es_menor = !empty($al['men_nombre']);
        $nombre = $es_menor ? $al['men_nombre'] : $al['alu_nombre'];
        $apellido = $es_menor ? $al['men_apellido'] : $al['alu_apellido'];
        $email = $es_menor ? $al['tut_email'] : $al['alu_email'];
        $telefono = $es_menor ? $al['tut_telefono'] : $al['alu_telefono'];
        $nac = $es_menor ? $al['men_nac'] : $al['alu_nac'];
        
        $al['display_nombre'] = "$nombre $apellido";
        $al['display_email'] = $email;
        $al['display_telefono'] = $telefono;
        $al['edad'] = date_diff(date_create($nac), date_create('today'))->y;
        
        // Buscar otras modulos de la misma actividad en esta entidad
        $stmt_cls = $pdo->prepare("
            SELECT c.id, c.nombre, 
                   COALESCE(sub.edad_minima, cat.edad_minima, 0) as edad_min,
                   COALESCE(sub.edad_maxima, cat.edad_maxima, 99) as edad_max
            FROM modulos c
            LEFT JOIN categoria cat ON c.fk_categoria = cat.id
            LEFT JOIN subcategorias sub ON c.fk_subcategoria = sub.id
            WHERE c.fk_actividad = ? AND c.fk_entidad = ? AND c.id != ? AND c.estado = TRUE
        ");
        $stmt_cls->execute([$al['fk_actividad'], $al['fk_entidad'], $al['fk_modulo']]);
        $modulos_alternativas = $stmt_cls->fetchAll();
        
        // Filtrar solo las modulos aptas para la edad actual del alumno
        $al['modulos_aptas'] = [];
        foreach ($modulos_alternativas as $ca) {
            if ($al['edad'] >= $ca['edad_min'] && $al['edad'] <= $ca['edad_max']) {
                $al['modulos_aptas'][] = $ca;
            }
        }
    }
    unset($al);
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="mb-4">
        <h2 class="fw-bold text-dark m-0">Promoción de Alumnos</h2>
        <small class="text-muted">Cambio de categoría/módulo de actividad basado en la edad del alumno</small>
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
                    <th>Alumno</th>
                    <th>Edad</th>
                    <th>Módulo Actual</th>
                    <th>Rango Permitido</th>
                    <th>Promocionar a</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($alumnos_inscritos)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No tienes alumnos inscriptos en tus módulos actualmente.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($alumnos_inscritos as $al): ?>
                        <tr>
                            <td class="fw-bold">
                                <?= htmlspecialchars($al['display_nombre']); ?>
                                <?php if (!empty($al['men_nombre'])): ?>
                                    <span class="badge bg-secondary ms-1" style="font-size:0.7rem;">Menor</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $al['edad']; ?> años</td>
                            <td><span class="badge bg-info text-dark"><?= htmlspecialchars($al['modulo_actual_nombre']); ?></span></td>
                            <td><?= $al['cat_edad_min']; ?> a <?= $al['cat_edad_max']; ?> años</td>
                            <td>
                                <?php if (empty($al['modulos_aptas'])): ?>
                                    <span class="text-muted small">No hay categorías alternativas para su edad.</span>
                                <?php else: ?>
                                    <form action="profesor_promocion.php" method="POST" class="d-flex gap-2">
                                        <input type="hidden" name="action" value="promocionar">
                                        <input type="hidden" name="csrf_promocion" value="<?= htmlspecialchars($_SESSION['csrf_promocion']); ?>">
                                        <input type="hidden" name="inscripcion_id" value="<?= $al['inscripcion_id']; ?>">
                                        
                                        <select name="nueva_modulo_id" class="form-select form-select-sm rounded-pill px-3" required style="max-width:250px;">
                                            <option value="">-- Seleccionar Módulo --</option>
                                            <?php foreach ($al['modulos_aptas'] as $ca): ?>
                                                <option value="<?= $ca['id']; ?>">
                                                    <?= htmlspecialchars($ca['nombre']); ?> (<?= $ca['edad_min']; ?>-<?= $ca['edad_max']; ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        
                                        <button type="submit" class="poliba-btn btn-sm py-1" onclick="return confirm('¿Seguro deseas promocionar a este alumno? Se cambiará su módulo y se enviará una notificación por mail.');">
                                            Promocionar
                                        </button>
                                    </form>
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
