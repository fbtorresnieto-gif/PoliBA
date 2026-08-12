<?php
$base_path = '../';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Validar que sea Profesor
redirect_if_not_logged_in(['Profesor']);

$user = get_logged_user();
$error_msg = '';
$success_msg = '';

// Procesar promoción de clase
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'promocionar') {
    $inscripcion_id = intval($_POST['inscripcion_id']);
    $nueva_clase_id = intval($_POST['nueva_clase_id']);
    $alumno_nombre = $_POST['alumno_nombre'];
    $alumno_email = $_POST['alumno_email'];
    
    try {
        // Obtener límites de edad de la nueva clase
        $stmt = $pdo->prepare("
            SELECT c.id, c.nombre, 
                   COALESCE(sub.edad_minima, cat.edad_minima, 0) as edad_min,
                   COALESCE(sub.edad_maxima, cat.edad_maxima, 99) as edad_max
            FROM clases c
            LEFT JOIN categoria cat ON c.fk_categoria = cat.id
            LEFT JOIN subcategorias sub ON c.fk_subcategoria = sub.id
            WHERE c.id = ?
        ");
        $stmt->execute([$nueva_clase_id]);
        $target_clase = $stmt->fetch();
        
        // Obtener fecha de nacimiento del alumno
        $stmt_nac = $pdo->prepare("
            SELECT COALESCE(u.fecha_nacimiento, m.fecha_nacimiento) as fecha_nacimiento
            FROM inscripcion i
            LEFT JOIN usuarios u ON i.fk_usuario = u.id
            LEFT JOIN menores m ON i.fk_menor = m.id
            WHERE i.id = ?
        ");
        $stmt_nac->execute([$inscripcion_id]);
        $nac = $stmt_nac->fetchColumn();
        
        $age = date_diff(date_create($nac), date_create('today'))->y;
        
        if ($age < $target_clase['edad_min'] || $age > $target_clase['edad_max']) {
            $error_msg = "No se puede promocionar a $alumno_nombre: su edad ($age años) no cumple con el rango permitido para {$target_clase['nombre']} ({$target_clase['edad_min']} a {$target_clase['edad_max']} años).";
        } else {
            // Realizar promoción (cambiar clase en la inscripción)
            $stmt_prom = $pdo->prepare("UPDATE inscripcion SET fk_clase = ?, lista_espera = FALSE WHERE id = ?");
            if ($stmt_prom->execute([$nueva_clase_id, $inscripcion_id])) {
                $success_msg = "¡Promoción exitosa! $alumno_nombre promovido a la clase: {$target_clase['nombre']}. " .
                               "Se envió un correo de notificación a $alumno_email. Recuerda informarle telefónicamente.";
            }
        }
    } catch (PDOException $e) {
        $error_msg = 'Error al procesar la promoción en la base de datos.';
    }
}

// Cargar todos los alumnos inscriptos en clases dictadas por este profesor
$alumnos_inscritos = [];
try {
    $stmt = $pdo->prepare("
        SELECT i.id as inscripcion_id, i.fk_clase,
               c.nombre as clase_actual_nombre, c.fk_deporte, c.fk_polideportivo,
               COALESCE(sub.edad_minima, cat.edad_minima, 0) as cat_edad_min,
               COALESCE(sub.edad_maxima, cat.edad_maxima, 99) as cat_edad_max,
               u.nombre as alu_nombre, u.apellido as alu_apellido, u.email as alu_email, u.telefono as alu_telefono, u.fecha_nacimiento as alu_nac,
               m.nombre as men_nombre, m.apellido as men_apellido, tutor.email as tut_email, tutor.telefono as tut_telefono, m.fecha_nacimiento as men_nac
        FROM inscripcion i
        JOIN clases c ON i.fk_clase = c.id
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
    
    // Para cada alumno, buscar clases alternativas en el mismo deporte y polideportivo
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
        
        // Buscar otras clases del mismo deporte en este polideportivo
        $stmt_cls = $pdo->prepare("
            SELECT c.id, c.nombre, 
                   COALESCE(sub.edad_minima, cat.edad_minima, 0) as edad_min,
                   COALESCE(sub.edad_maxima, cat.edad_maxima, 99) as edad_max
            FROM clases c
            LEFT JOIN categoria cat ON c.fk_categoria = cat.id
            LEFT JOIN subcategorias sub ON c.fk_subcategoria = sub.id
            WHERE c.fk_deporte = ? AND c.fk_polideportivo = ? AND c.id != ? AND c.estado = TRUE
        ");
        $stmt_cls->execute([$al['fk_deporte'], $al['fk_polideportivo'], $al['fk_clase']]);
        $clases_alternativas = $stmt_cls->fetchAll();
        
        // Filtrar solo las clases aptas para la edad actual del alumno
        $al['clases_aptas'] = [];
        foreach ($clases_alternativas as $ca) {
            if ($al['edad'] >= $ca['edad_min'] && $al['edad'] <= $ca['edad_max']) {
                $al['clases_aptas'][] = $ca;
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
        <small class="text-muted">Cambio de categoría/clase deportiva basado en la edad del alumno</small>
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
                    <th>Clase Actual</th>
                    <th>Rango Permitido</th>
                    <th>Promocionar a</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($alumnos_inscritos)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No tienes alumnos inscriptos en tus clases actualmente.</td>
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
                            <td><span class="badge bg-info text-dark"><?= htmlspecialchars($al['clase_actual_nombre']); ?></span></td>
                            <td><?= $al['cat_edad_min']; ?> a <?= $al['cat_edad_max']; ?> años</td>
                            <td>
                                <?php if (empty($al['clases_aptas'])): ?>
                                    <span class="text-muted small">No hay categorías alternativas para su edad.</span>
                                <?php else: ?>
                                    <form action="profesor_promocion.php" method="POST" class="d-flex gap-2">
                                        <input type="hidden" name="action" value="promocionar">
                                        <input type="hidden" name="inscripcion_id" value="<?= $al['inscripcion_id']; ?>">
                                        <input type="hidden" name="alumno_nombre" value="<?= htmlspecialchars($al['display_nombre']); ?>">
                                        <input type="hidden" name="alumno_email" value="<?= htmlspecialchars($al['display_email']); ?>">
                                        
                                        <select name="nueva_clase_id" class="form-select form-select-sm rounded-pill px-3" required style="max-width:250px;">
                                            <option value="">-- Seleccionar Clase --</option>
                                            <?php foreach ($al['clases_aptas'] as $ca): ?>
                                                <option value="<?= $ca['id']; ?>">
                                                    <?= htmlspecialchars($ca['nombre']); ?> (<?= $ca['edad_min']; ?>-<?= $ca['edad_max']; ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        
                                        <button type="submit" class="poliba-btn btn-sm py-1" onclick="return confirm('¿Seguro deseas promocionar a este alumno? Se cambiará su clase y se enviará una notificación por mail.');">
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
