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

$error_msg = '';
$success_msg = '';

// Obtener información de atención de la entidad (días y horarios)
$poli_info = null;
$dias_abiertos_ids = [];
$dias = [];
try {
    $stmt_p = $pdo->prepare("
        SELECT p.*, da.orden as orden_apertura, dc.orden as orden_cierre, da.nombre as nombre_apertura, dc.nombre as nombre_cierre
        FROM polideportivos p
        LEFT JOIN dias da ON p.fk_dia_apertura = da.id
        LEFT JOIN dias dc ON p.fk_dia_cierre = dc.id
        WHERE p.id = ?
    ");
    $stmt_p->execute([$poli_id]);
    $poli_info = $stmt_p->fetch();

    $stmt_dias = $pdo->query("SELECT id, nombre, orden FROM dias ORDER BY orden ASC");
    $dias = $stmt_dias->fetchAll();

    if ($poli_info && $poli_info['orden_apertura'] !== null && $poli_info['orden_cierre'] !== null) {
        $ord_ap = intval($poli_info['orden_apertura']);
        $ord_ci = intval($poli_info['orden_cierre']);
        foreach ($dias as $d) {
            $ord = intval($d['orden']);
            $abierto = ($ord_ap <= $ord_ci) ? ($ord >= $ord_ap && $ord <= $ord_ci) : ($ord >= $ord_ap || $ord <= $ord_ci);
            if ($abierto) {
                $dias_abiertos_ids[] = intval($d['id']);
            }
        }
    } else {
        foreach ($dias as $d) {
            $dias_abiertos_ids[] = intval($d['id']);
        }
    }
} catch (PDOException $e) {}

// Procesar Creación / Modificación de Módulos
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion']);
    $horario_inicio = $_POST['horario_inicio'];
    $horario_cierre = $_POST['horario_cierre'];
    $cupo_maximo = intval($_POST['cupo_maximo']);
    $fk_usuario_profesor = !empty($_POST['fk_usuario_profesor']) ? intval($_POST['fk_usuario_profesor']) : null;
    $fk_deporte = intval($_POST['fk_deporte']);
    $fk_canchas = !empty($_POST['fk_canchas']) ? intval($_POST['fk_canchas']) : null;
    $fk_categoria = !empty($_POST['fk_categoria']) ? intval($_POST['fk_categoria']) : null;
    $fk_subcategoria = !empty($_POST['fk_subcategoria']) ? intval($_POST['fk_subcategoria']) : null;
    $dias_seleccionados = isset($_POST['dias']) ? $_POST['dias'] : []; // Array de IDs de días
    
    if (empty($nombre) || empty($horario_inicio) || empty($horario_cierre) || $cupo_maximo <= 0 || (($_POST['action'] ?? '') == 'crear' && $fk_deporte <= 0)) {
        $error_msg = 'Por favor, completa los campos obligatorios.';
    } elseif ($horario_inicio >= $horario_cierre) {
        $error_msg = 'La hora de cierre debe ser posterior a la hora de inicio del módulo.';
    } elseif ($poli_info && !empty($poli_info['horario_apertura']) && !empty($poli_info['horario_cierre']) && (
        date('H:i', strtotime($horario_inicio)) < date('H:i', strtotime($poli_info['horario_apertura'])) ||
        date('H:i', strtotime($horario_inicio)) > date('H:i', strtotime($poli_info['horario_cierre']))
    )) {
        $ap_f = date('H:i', strtotime($poli_info['horario_apertura']));
        $ci_f = date('H:i', strtotime($poli_info['horario_cierre']));
        $ini_f = date('H:i', strtotime($horario_inicio));
        $error_msg = "La hora de inicio ($ini_f hs) debe estar dentro del horario de atención de la entidad ($ap_f a $ci_f hs).";
    } elseif ($poli_info && !empty($poli_info['horario_apertura']) && !empty($poli_info['horario_cierre']) && (
        date('H:i', strtotime($horario_cierre)) < date('H:i', strtotime($poli_info['horario_apertura'])) ||
        date('H:i', strtotime($horario_cierre)) > date('H:i', strtotime($poli_info['horario_cierre']))
    )) {
        $ap_f = date('H:i', strtotime($poli_info['horario_apertura']));
        $ci_f = date('H:i', strtotime($poli_info['horario_cierre']));
        $fin_f = date('H:i', strtotime($horario_cierre));
        $error_msg = "La hora de cierre ($fin_f hs) debe estar dentro del horario de atención de la entidad ($ap_f a $ci_f hs).";
    } elseif (empty($dias_seleccionados)) {
        $error_msg = 'Debes seleccionar al menos un día de dictado para el módulo.';
    } else {
        // Validar que los días seleccionados pertenezcan a los días de atención de la entidad
        $dias_invalidos = [];
        foreach ($dias_seleccionados as $dia_id) {
            if (!in_array(intval($dia_id), $dias_abiertos_ids)) {
                foreach ($dias as $d) {
                    if (intval($d['id']) == intval($dia_id)) {
                        $dias_invalidos[] = $d['nombre'];
                    }
                }
            }
        }

        if (!empty($dias_invalidos)) {
            $str_invalidos = implode(', ', $dias_invalidos);
            $rango_atencion = ($poli_info && !empty($poli_info['nombre_apertura'])) ? " (Atención: {$poli_info['nombre_apertura']} a {$poli_info['nombre_cierre']})" : "";
            $error_msg = "No es posible asignar el módulo el/los día(s) $str_invalidos ya que la entidad permanece cerrada{$rango_atencion}.";
        } else {
            $sub_valida = true;
            $chk_id = ($_POST['action'] ?? '') == 'editar' ? intval($_POST['id']) : 0;

            if (($_POST['action'] ?? '') == 'editar') {
                $stmt_orig = $pdo->prepare("SELECT cupo_maximo, fk_deporte, fk_categoria, fk_subcategoria FROM clases WHERE id = ? AND fk_polideportivo = ?");
                $stmt_orig->execute([$chk_id, $poli_id]);
                $clase_orig = $stmt_orig->fetch();

                if (!$clase_orig) {
                    $error_msg = 'El módulo a editar no fue encontrado.';
                    $sub_valida = false;
                } elseif ($cupo_maximo < intval($clase_orig['cupo_maximo'])) {
                    $error_msg = "El cupo máximo no se puede reducir. Debe ser mayor o igual al cupo guardado previamente ({$clase_orig['cupo_maximo']}).";
                    $sub_valida = false;
                } else {
                    // Forzar inmutabilidad de actividad, categoría y subcategoría al editar
                    $fk_deporte = intval($clase_orig['fk_deporte']);
                    $fk_categoria = !empty($clase_orig['fk_categoria']) ? intval($clase_orig['fk_categoria']) : null;
                    $fk_subcategoria = !empty($clase_orig['fk_subcategoria']) ? intval($clase_orig['fk_subcategoria']) : null;
                }
            }

            // 1. Validar solapamiento de Espacio (Cancha específica o Entidad General)
            if ($sub_valida && !empty($dias_seleccionados)) {
                $placeholders_dias = implode(',', array_fill(0, count($dias_seleccionados), '?'));
                
                if ($fk_canchas !== null) {
                    $sql_cancha = "
                        SELECT DISTINCT c.nombre, c.horario_inicio, c.horario_cierre, d.nombre as dia_nombre, can.nombre as cancha_nombre
                        FROM clases c
                        JOIN dias_clases dc ON c.id = dc.fk_clase
                        JOIN dias d ON dc.fk_dia = d.id
                        JOIN canchas can ON c.fk_canchas = can.id
                        WHERE c.fk_polideportivo = ?
                          AND c.fk_canchas = ?
                          AND c.estado = TRUE
                          AND c.id != ?
                          AND dc.fk_dia IN ($placeholders_dias)
                          AND c.horario_inicio < ?
                          AND c.horario_cierre > ?
                    ";
                    $params_cancha = array_merge([$poli_id, $fk_canchas, $chk_id], array_map('intval', $dias_seleccionados), [$horario_cierre, $horario_inicio]);
                    $stmt_cancha = $pdo->prepare($sql_cancha);
                    $stmt_cancha->execute($params_cancha);
                    $solapados_cancha = $stmt_cancha->fetchAll();

                    if (!empty($solapados_cancha)) {
                        $mod_conf = $solapados_cancha[0];
                        $dias_conf = implode(', ', array_unique(array_column($solapados_cancha, 'dia_nombre')));
                        $ini_conf = date('H:i', strtotime($mod_conf['horario_inicio']));
                        $fin_conf = date('H:i', strtotime($mod_conf['horario_cierre']));
                        $error_msg = "El espacio \"{$mod_conf['cancha_nombre']}\" ya se encuentra ocupado el/los día(s) $dias_conf de $ini_conf a $fin_conf hs por el módulo \"{$mod_conf['nombre']}\".";
                        $sub_valida = false;
                    }
                } else {
                    $sql_general = "
                        SELECT DISTINCT c.nombre, c.horario_inicio, c.horario_cierre, d.nombre as dia_nombre
                        FROM clases c
                        JOIN dias_clases dc ON c.id = dc.fk_clase
                        JOIN dias d ON dc.fk_dia = d.id
                        WHERE c.fk_polideportivo = ?
                          AND c.fk_canchas IS NULL
                          AND c.estado = TRUE
                          AND c.id != ?
                          AND dc.fk_dia IN ($placeholders_dias)
                          AND c.horario_inicio < ?
                          AND c.horario_cierre > ?
                    ";
                    $params_general = array_merge([$poli_id, $chk_id], array_map('intval', $dias_seleccionados), [$horario_cierre, $horario_inicio]);
                    $stmt_general = $pdo->prepare($sql_general);
                    $stmt_general->execute($params_general);
                    $solapados_gen = $stmt_general->fetchAll();

                    if (!empty($solapados_gen)) {
                        $mod_conf = $solapados_gen[0];
                        $dias_conf = implode(', ', array_unique(array_column($solapados_gen, 'dia_nombre')));
                        $ini_conf = date('H:i', strtotime($mod_conf['horario_inicio']));
                        $fin_conf = date('H:i', strtotime($mod_conf['horario_cierre']));
                        $error_msg = "El espacio (Entidad General) ya se encuentra ocupado el/los día(s) $dias_conf de $ini_conf a $fin_conf hs por el módulo \"{$mod_conf['nombre']}\".";
                        $sub_valida = false;
                    }
                }
            }

            // 2. Validar solapamiento de horario del Profesor en cualquier entidad/polideportivo
            if ($sub_valida && $fk_usuario_profesor !== null && !empty($dias_seleccionados)) {
                $placeholders_dias = implode(',', array_fill(0, count($dias_seleccionados), '?'));
                $sql_prof = "
                    SELECT DISTINCT c.nombre, c.horario_inicio, c.horario_cierre, d.nombre as dia_nombre, 
                                    u.nombre as prof_nombre, u.apellido as prof_apellido, p.nombre as poli_nombre
                    FROM clases c
                    JOIN dias_clases dc ON c.id = dc.fk_clase
                    JOIN dias d ON dc.fk_dia = d.id
                    JOIN usuarios u ON c.fk_usuario_profesor = u.id
                    JOIN polideportivos p ON c.fk_polideportivo = p.id
                    WHERE c.fk_usuario_profesor = ?
                      AND c.estado = TRUE
                      AND c.id != ?
                      AND dc.fk_dia IN ($placeholders_dias)
                      AND c.horario_inicio < ?
                      AND c.horario_cierre > ?
                ";
                $params_prof = array_merge([$fk_usuario_profesor, $chk_id], array_map('intval', $dias_seleccionados), [$horario_cierre, $horario_inicio]);
                $stmt_prof = $pdo->prepare($sql_prof);
                $stmt_prof->execute($params_prof);
                $solapados_prof = $stmt_prof->fetchAll();

                if (!empty($solapados_prof)) {
                    $mod_conf = $solapados_prof[0];
                    $dias_conf = implode(', ', array_unique(array_column($solapados_prof, 'dia_nombre')));
                    $ini_conf = date('H:i', strtotime($mod_conf['horario_inicio']));
                    $fin_conf = date('H:i', strtotime($mod_conf['horario_cierre']));
                    $prof_nombre_completo = trim($mod_conf['prof_nombre'] . ' ' . $mod_conf['prof_apellido']);
                    $error_msg = "El profesor $prof_nombre_completo ya tiene asignado el módulo \"{$mod_conf['nombre']}\" el/los día(s) $dias_conf de $ini_conf a $fin_conf hs en \"{$mod_conf['poli_nombre']}\".";
                    $sub_valida = false;
                }
            }

        if ($sub_valida && $fk_subcategoria) {
            $stmt_sub = $pdo->prepare("SELECT nombre, edad_minima, edad_maxima, fk_categoria, fk_deporte FROM subcategorias WHERE id = ?");
            $stmt_sub->execute([$fk_subcategoria]);
            $sub_info = $stmt_sub->fetch();

            if ($sub_info) {
                if ($sub_info['fk_deporte'] != $fk_deporte) {
                    $error_msg = "La subcategoría \"{$sub_info['nombre']}\" no corresponde a la actividad seleccionada.";
                    $sub_valida = false;
                } elseif ($fk_categoria) {
                    $stmt_cat = $pdo->prepare("SELECT nombre, edad_minima, edad_maxima FROM categoria WHERE id = ?");
                    $stmt_cat->execute([$fk_categoria]);
                    $cat_info = $stmt_cat->fetch();

                    if ($cat_info) {
                        $incompatible = false;
                        if (!empty($sub_info['fk_categoria']) && $sub_info['fk_categoria'] != $fk_categoria) {
                            $incompatible = true;
                        } elseif ($sub_info['edad_minima'] < $cat_info['edad_minima'] || $sub_info['edad_maxima'] > $cat_info['edad_maxima']) {
                            $incompatible = true;
                        }

                        if ($incompatible) {
                            $error_msg = "La subcategoría \"{$sub_info['nombre']}\" ({$sub_info['edad_minima']}-{$sub_info['edad_maxima']} años) no es compatible con el rango de edad de la categoría \"{$cat_info['nombre']}\" ({$cat_info['edad_minima']}-{$cat_info['edad_maxima']} años).";
                            $sub_valida = false;
                        }
                    }
                }
            }
        }

        if ($sub_valida) {
        $pdo->beginTransaction();
        try {
            if ($_POST['action'] == 'crear') {
                if ($db_driver_used === 'postgresql') {
                    $stmt = $pdo->prepare("
                        INSERT INTO clases (nombre, descripcion, horario_inicio, horario_cierre, cupo_maximo, fk_usuario_profesor, fk_deporte, fk_canchas, fk_categoria, fk_subcategoria, fk_polideportivo, estado)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)
                        RETURNING id
                    ");
                    $stmt->execute([$nombre, $descripcion, $horario_inicio, $horario_cierre, $cupo_maximo, $fk_usuario_profesor, $fk_deporte, $fk_canchas, $fk_categoria, $fk_subcategoria, $poli_id]);
                    $clase_id = $stmt->fetchColumn();
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO clases (nombre, descripcion, horario_inicio, horario_cierre, cupo_maximo, fk_usuario_profesor, fk_deporte, fk_canchas, fk_categoria, fk_subcategoria, fk_polideportivo, estado)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)
                    ");
                    $stmt->execute([$nombre, $descripcion, $horario_inicio, $horario_cierre, $cupo_maximo, $fk_usuario_profesor, $fk_deporte, $fk_canchas, $fk_categoria, $fk_subcategoria, $poli_id]);
                    $clase_id = $pdo->lastInsertId();
                }
                
                // Insertar los días
                $stmt_dia = $pdo->prepare("INSERT INTO dias_clases (fk_clase, fk_dia) VALUES (?, ?)");
                foreach ($dias_seleccionados as $dia_id) {
                    $stmt_dia->execute([$clase_id, intval($dia_id)]);
                }
                
                $pdo->commit();
                $success_msg = 'Módulo creado con éxito.';
            } elseif ($_POST['action'] == 'editar') {
                $id = intval($_POST['id']);
                
                $stmt = $pdo->prepare("
                    UPDATE clases 
                    SET nombre = ?, descripcion = ?, horario_inicio = ?, horario_cierre = ?, cupo_maximo = ?, fk_usuario_profesor = ?, fk_deporte = ?, fk_canchas = ?, fk_categoria = ?, fk_subcategoria = ?
                    WHERE id = ? AND fk_polideportivo = ?
                ");
                $stmt->execute([$nombre, $descripcion, $horario_inicio, $horario_cierre, $cupo_maximo, $fk_usuario_profesor, $fk_deporte, $fk_canchas, $fk_categoria, $fk_subcategoria, $id, $poli_id]);
                
                // Limpiar días anteriores y re-insertar
                $stmt_del = $pdo->prepare("DELETE FROM dias_clases WHERE fk_clase = ?");
                $stmt_del->execute([$id]);
                
                $stmt_dia = $pdo->prepare("INSERT INTO dias_clases (fk_clase, fk_dia) VALUES (?, ?)");
                foreach ($dias_seleccionados as $dia_id) {
                    $stmt_dia->execute([$id, intval($dia_id)]);
                }
                
                $pdo->commit();
                $success_msg = 'Módulo modificado con éxito.';
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error_msg = 'Error al registrar los datos en la base de datos: ' . $e->getMessage();
        }
        }
        }
    }
}

// Procesar Alta/Baja Lógica
if (isset($_GET['toggle_estado'])) {
    $id = intval($_GET['toggle_estado']);
    $nuevo_estado = $_GET['estado'] == '1' ? 'FALSE' : 'TRUE';
    try {
        $stmt = $pdo->prepare("UPDATE clases SET estado = $nuevo_estado WHERE id = ? AND fk_polideportivo = ?");
        $stmt->execute([$id, $poli_id]);
        $success_msg = 'Estado del módulo actualizado.';
    } catch (PDOException $e) {
        $error_msg = 'Error al actualizar el estado.';
    }
}

// Cargar Clases de la sede
$clases = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*, d.nombre as deporte_nombre, prof.nombre as prof_nombre, prof.apellido as prof_apellido,
               can.nombre as cancha_nombre, cat.nombre as categoria_nombre, sub.nombre as subcategoria_nombre
        FROM clases c
        JOIN deportes d ON c.fk_deporte = d.id
        LEFT JOIN usuarios prof ON c.fk_usuario_profesor = prof.id
        LEFT JOIN canchas can ON c.fk_canchas = can.id
        LEFT JOIN categoria cat ON c.fk_categoria = cat.id
        LEFT JOIN subcategorias sub ON c.fk_subcategoria = sub.id
        WHERE c.fk_polideportivo = ?
        ORDER BY c.id ASC
    ");
    $stmt->execute([$poli_id]);
    $clases = $stmt->fetchAll();
    
    // Cargar días para cada clase
    foreach ($clases as &$clase) {
        $stmt_d = $pdo->prepare("SELECT fk_dia FROM dias_clases WHERE fk_clase = ?");
        $stmt_d->execute([$clase['id']]);
        $clase['dias'] = $stmt_d->fetchAll(PDO::FETCH_COLUMN);
    }
    unset($clase);
} catch (PDOException $e) {}

// Cargar catálogo de soporte (Profesores, Deportes, Canchas, Categorías, Subcategorías, Días)
$profesores = [];
$deportes = [];
$canchas = [];
$categorias = [];
$subcategorias = [];
$dias = [];

try {
    $stmt = $pdo->prepare("SELECT id, nombre, apellido FROM usuarios WHERE fk_polideportivo = ? AND fk_rol = (SELECT id FROM roles WHERE nombre = 'Profesor') ORDER BY nombre ASC");
    $stmt->execute([$poli_id]);
    $profesores = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT id, nombre FROM deportes WHERE fk_polideportivo = ? AND estado = TRUE ORDER BY nombre ASC");
    $stmt->execute([$poli_id]);
    $deportes = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT id, nombre FROM canchas WHERE fk_polideportivo = ? AND estado = TRUE ORDER BY nombre ASC");
    $stmt->execute([$poli_id]);
    $canchas = $stmt->fetchAll();
    
    $stmt = $pdo->query("SELECT id, nombre, edad_minima, edad_maxima FROM categoria ORDER BY id ASC");
    $categorias = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT id, nombre, edad_minima, edad_maxima, fk_categoria, fk_deporte FROM subcategorias WHERE fk_polideportivo = ? AND estado = TRUE ORDER BY nombre ASC");
    $stmt->execute([$poli_id]);
    $subcategorias = $stmt->fetchAll();
    
    $stmt = $pdo->query("SELECT id, nombre FROM dias ORDER BY orden ASC");
    $dias = $stmt->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark m-0">ABM Módulos</h2>
            <small class="text-muted">Administrando Entidad: <strong><?= htmlspecialchars($user['fk_polideportivo_nombre'] ?? 'Mi Entidad'); ?></strong></small>
        </div>
        <button class="poliba-btn" data-bs-toggle="modal" data-bs-target="#crearClaseModal">Agregar Módulo</button>
    </div>

    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>
    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-poliba table-striped">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Actividad</th>
                    <th>Profesor</th>
                    <th>Horario y Días</th>
                    <th>Cupo</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clases)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted">No hay módulos registrados para esta entidad.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($clases as $clase): 
                        // Mapear nombres de días
                        $dias_nombres = [];
                        foreach ($dias as $d) {
                            if (in_array($d['id'], $clase['dias'])) {
                                $dias_nombres[] = substr($d['nombre'], 0, 2);
                            }
                        }
                    ?>
                        <tr>
                            <td class="fw-bold">
                                <?= htmlspecialchars($clase['nombre']); ?>
                                <div class="small text-muted font-normal">
                                    Cat: <?= htmlspecialchars($clase['categoria_nombre'] ?? 'General'); ?> 
                                    <?= !empty($clase['subcategoria_nombre']) ? ' (' . htmlspecialchars($clase['subcategoria_nombre']) . ')' : ''; ?>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($clase['deporte_nombre']); ?></td>
                            <td><?= !empty($clase['prof_nombre']) ? htmlspecialchars($clase['prof_nombre'] . ' ' . $clase['prof_apellido']) : 'Sin asignar'; ?></td>
                            <td>
                                <strong><?= date('H:i', strtotime($clase['horario_inicio'])); ?> - <?= date('H:i', strtotime($clase['horario_cierre'])); ?></strong>
                                <div class="small text-muted">[<?= implode(', ', $dias_nombres); ?>]</div>
                            </td>
                            <td><?= $clase['cupo_maximo']; ?></td>
                            <td>
                                <span class="badge rounded-pill <?= $clase['estado'] ? 'bg-success' : 'bg-danger'; ?>">
                                    <?= $clase['estado'] ? 'Activa' : 'Inactiva'; ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-dark rounded-pill px-3 mb-1" data-bs-toggle="modal" data-bs-target="#editClaseModal<?= $clase['id']; ?>">Editar</button>
                                <a href="clases.php?toggle_estado=<?= $clase['id']; ?>&estado=<?= $clase['estado'] ? '1' : '0'; ?>" 
                                   class="btn btn-sm <?= $clase['estado'] ? 'btn-danger' : 'btn-success'; ?> rounded-pill px-3"
                                   onclick="return confirm('¿Seguro deseas cambiar el estado de este módulo?');">
                                    <?= $clase['estado'] ? 'Desactivar' : 'Activar'; ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modales Editar Clase (Fuera de la tabla para Bootstrap 5) -->
<?php foreach ($clases as $clase): ?>
    <div class="modal fade" id="editClaseModal<?= $clase['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form action="clases.php" method="POST" class="modal-content border-0 shadow">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="id" value="<?= $clase['id']; ?>">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold">Editar Módulo #<?= $clase['id']; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Nombre del Módulo *</label>
                            <input type="text" name="nombre" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($clase['nombre']); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Cupo Máximo *</label>
                            <input type="number" name="cupo_maximo" class="form-control rounded-pill px-3" required value="<?= $clase['cupo_maximo']; ?>" min="<?= $clase['cupo_maximo']; ?>">
                            <small class="text-muted d-block ms-2 mt-1">Mínimo: <?= $clase['cupo_maximo']; ?> (no se puede reducir)</small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Hora Inicio *</label>
                            <input type="time" name="horario_inicio" class="form-control rounded-pill px-3" required 
                                   value="<?= $clase['horario_inicio']; ?>"
                                   <?= ($poli_info && !empty($poli_info['horario_apertura'])) ? 'min="' . date('H:i', strtotime($poli_info['horario_apertura'])) . '"' : ''; ?>
                                   <?= ($poli_info && !empty($poli_info['horario_cierre'])) ? 'max="' . date('H:i', strtotime($poli_info['horario_cierre'])) . '"' : ''; ?>>
                            <?php if ($poli_info && !empty($poli_info['horario_apertura'])): ?>
                                <small class="text-muted d-block ms-2 mt-1">Apertura entidad: <?= date('H:i', strtotime($poli_info['horario_apertura'])); ?> hs</small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Hora Cierre *</label>
                            <input type="time" name="horario_cierre" class="form-control rounded-pill px-3" required 
                                   value="<?= $clase['horario_cierre']; ?>"
                                   <?= ($poli_info && !empty($poli_info['horario_apertura'])) ? 'min="' . date('H:i', strtotime($poli_info['horario_apertura'])) . '"' : ''; ?>
                                   <?= ($poli_info && !empty($poli_info['horario_cierre'])) ? 'max="' . date('H:i', strtotime($poli_info['horario_cierre'])) . '"' : ''; ?>>
                            <?php if ($poli_info && !empty($poli_info['horario_cierre'])): ?>
                                <small class="text-muted d-block ms-2 mt-1">Cierre entidad: <?= date('H:i', strtotime($poli_info['horario_cierre'])); ?> hs</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Actividad * <small class="text-muted">(Fija)</small></label>
                            <select class="form-select rounded-pill px-3" disabled>
                                <?php foreach ($deportes as $dep): ?>
                                    <option value="<?= $dep['id']; ?>" <?= $clase['fk_deporte'] == $dep['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($dep['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="fk_deporte" value="<?= $clase['fk_deporte']; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Profesor</label>
                            <select name="fk_usuario_profesor" class="form-select rounded-pill px-3">
                                <option value="">-- Sin asignar --</option>
                                <?php foreach ($profesores as $prof): ?>
                                    <option value="<?= $prof['id']; ?>" <?= $clase['fk_usuario_profesor'] == $prof['id'] ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($prof['nombre'] . ' ' . $prof['apellido']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Espacio</label>
                            <select name="fk_canchas" class="form-select rounded-pill px-3">
                                <option value="">-- Entidad General --</option>
                                <?php foreach ($canchas as $can): ?>
                                    <option value="<?= $can['id']; ?>" <?= $clase['fk_canchas'] == $can['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($can['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Categoría de Edad <small class="text-muted">(Fija)</small></label>
                            <select class="form-select rounded-pill px-3" disabled>
                                <option value="">-- General --</option>
                                <?php foreach ($categorias as $cat): ?>
                                    <option value="<?= $cat['id']; ?>" <?= $clase['fk_categoria'] == $cat['id'] ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($cat['nombre']); ?> (<?= $cat['edad_minima']; ?>-<?= $cat['edad_maxima']; ?> años)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="fk_categoria" value="<?= $clase['fk_categoria']; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Subcategoría Especial <small class="text-muted">(Fija)</small></label>
                            <select class="form-select rounded-pill px-3" disabled>
                                <option value="">-- Ninguna --</option>
                                <?php foreach ($subcategorias as $sub): ?>
                                    <option value="<?= $sub['id']; ?>" <?= $clase['fk_subcategoria'] == $sub['id'] ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($sub['nombre']); ?> (<?= $sub['edad_minima']; ?>-<?= $sub['edad_maxima']; ?> años)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="fk_subcategoria" value="<?= $clase['fk_subcategoria']; ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold d-block">
                            Días de Dictado * 
                            <?php if ($poli_info && !empty($poli_info['nombre_apertura'])): ?>
                                <small class="text-muted fw-normal ms-2">(Atención de la entidad: <?= htmlspecialchars($poli_info['nombre_apertura']); ?> a <?= htmlspecialchars($poli_info['nombre_cierre']); ?>)</small>
                            <?php endif; ?>
                        </label>
                        <div class="d-flex flex-wrap gap-3">
                            <?php foreach ($dias as $dia): ?>
                                <?php $es_abierto = in_array(intval($dia['id']), $dias_abiertos_ids); ?>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="dias[]" 
                                           id="editDia_<?= $clase['id']; ?>_<?= $dia['id']; ?>" 
                                           value="<?= $dia['id']; ?>" 
                                           <?= in_array($dia['id'], $clase['dias']) ? 'checked' : ''; ?>
                                           <?= !$es_abierto ? 'disabled' : ''; ?>>
                                    <label class="form-check-label <?= !$es_abierto ? 'text-muted text-decoration-line-through' : ''; ?>" for="editDia_<?= $clase['id']; ?>_<?= $dia['id']; ?>">
                                        <?= htmlspecialchars($dia['nombre']); ?>
                                        <?= !$es_abierto ? '<small class="text-danger ps-1">(Cerrado)</small>' : ''; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Descripción / Requisitos</label>
                        <textarea name="descripcion" class="form-control px-3" rows="3" style="border-radius:15px;"><?= htmlspecialchars($clase['descripcion'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                    <button type="submit" class="poliba-btn py-2">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<!-- Modal Crear Módulo -->
<div class="modal fade" id="crearClaseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form action="clases.php" method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="crear">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">Agregar Módulo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Nombre del Módulo *</label>
                        <input type="text" name="nombre" class="form-control rounded-pill px-3" required placeholder="Vóley Femenino Sub-18">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Cupo Máximo *</label>
                        <input type="number" name="cupo_maximo" class="form-control rounded-pill px-3" required value="20" min="1">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Hora Inicio *</label>
                        <input type="time" name="horario_inicio" class="form-control rounded-pill px-3" required 
                               value="17:00"
                               <?= ($poli_info && !empty($poli_info['horario_apertura'])) ? 'min="' . date('H:i', strtotime($poli_info['horario_apertura'])) . '"' : ''; ?>
                               <?= ($poli_info && !empty($poli_info['horario_cierre'])) ? 'max="' . date('H:i', strtotime($poli_info['horario_cierre'])) . '"' : ''; ?>>
                        <?php if ($poli_info && !empty($poli_info['horario_apertura'])): ?>
                            <small class="text-muted d-block ms-2 mt-1">Apertura entidad: <?= date('H:i', strtotime($poli_info['horario_apertura'])); ?> hs</small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Hora Cierre *</label>
                        <input type="time" name="horario_cierre" class="form-control rounded-pill px-3" required 
                               value="19:00"
                               <?= ($poli_info && !empty($poli_info['horario_apertura'])) ? 'min="' . date('H:i', strtotime($poli_info['horario_apertura'])) . '"' : ''; ?>
                               <?= ($poli_info && !empty($poli_info['horario_cierre'])) ? 'max="' . date('H:i', strtotime($poli_info['horario_cierre'])) . '"' : ''; ?>>
                        <?php if ($poli_info && !empty($poli_info['horario_cierre'])): ?>
                            <small class="text-muted d-block ms-2 mt-1">Cierre entidad: <?= date('H:i', strtotime($poli_info['horario_cierre'])); ?> hs</small>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">Actividad *</label>
                        <select name="fk_deporte" class="form-select rounded-pill px-3" required>
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($deportes as $dep): ?>
                                <option value="<?= $dep['id']; ?>"><?= htmlspecialchars($dep['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">Profesor</label>
                        <select name="fk_usuario_profesor" class="form-select rounded-pill px-3">
                            <option value="">-- Sin asignar --</option>
                            <?php foreach ($profesores as $prof): ?>
                                <option value="<?= $prof['id']; ?>"><?= htmlspecialchars($prof['nombre'] . ' ' . $prof['apellido']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">Espacio</label>
                        <select name="fk_canchas" class="form-select rounded-pill px-3">
                            <option value="">-- Entidad General --</option>
                            <?php foreach ($canchas as $can): ?>
                                <option value="<?= $can['id']; ?>"><?= htmlspecialchars($can['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Categoría de Edad</label>
                        <select name="fk_categoria" class="form-select rounded-pill px-3">
                            <option value="">-- General --</option>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?= $cat['id']; ?>" 
                                        data-min="<?= $cat['edad_minima']; ?>" 
                                        data-max="<?= $cat['edad_maxima']; ?>">
                                    <?= htmlspecialchars($cat['nombre']); ?> (<?= $cat['edad_minima']; ?>-<?= $cat['edad_maxima']; ?> años)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Subcategoría Especial</label>
                        <select name="fk_subcategoria" class="form-select rounded-pill px-3">
                            <option value="">-- Ninguna --</option>
                            <?php foreach ($subcategorias as $sub): ?>
                                <option value="<?= $sub['id']; ?>" 
                                        data-deporte="<?= $sub['fk_deporte']; ?>"
                                        data-categoria="<?= $sub['fk_categoria'] ?? ''; ?>"
                                        data-min="<?= $sub['edad_minima']; ?>"
                                        data-max="<?= $sub['edad_maxima']; ?>">
                                    <?= htmlspecialchars($sub['nombre']); ?> (<?= $sub['edad_minima']; ?>-<?= $sub['edad_maxima']; ?> años)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold d-block">
                        Días de Dictado * 
                        <?php if ($poli_info && !empty($poli_info['nombre_apertura'])): ?>
                            <small class="text-muted fw-normal ms-2">(Atención de la entidad: <?= htmlspecialchars($poli_info['nombre_apertura']); ?> a <?= htmlspecialchars($poli_info['nombre_cierre']); ?>)</small>
                        <?php endif; ?>
                    </label>
                    <div class="d-flex flex-wrap gap-3">
                        <?php foreach ($dias as $dia): ?>
                            <?php $es_abierto = in_array(intval($dia['id']), $dias_abiertos_ids); ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="dias[]" id="crearDia_<?= $dia['id']; ?>" value="<?= $dia['id']; ?>" <?= !$es_abierto ? 'disabled' : ''; ?>>
                                <label class="form-check-label <?= !$es_abierto ? 'text-muted text-decoration-line-through' : ''; ?>" for="crearDia_<?= $dia['id']; ?>">
                                    <?= htmlspecialchars($dia['nombre']); ?>
                                    <?= !$es_abierto ? '<small class="text-danger ps-1">(Cerrado)</small>' : ''; ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Descripción / Requisitos</label>
                    <textarea name="descripcion" class="form-control px-3" rows="3" style="border-radius:15px;" placeholder="Descripción del módulo, nivel, requisitos de indumentaria..."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                <button type="submit" class="poliba-btn py-2">Crear Módulo</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('form[action="clases.php"]');
    forms.forEach(form => {
        const selectDeporte = form.querySelector('select[name="fk_deporte"]');
        const selectCat = form.querySelector('select[name="fk_categoria"]');
        const selectSub = form.querySelector('select[name="fk_subcategoria"]');

        if (!selectSub) return;

        function filtrarSubcategorias() {
            const deporteId = selectDeporte ? selectDeporte.value : '';
            const catId = selectCat ? selectCat.value : '';
            const selectedCatOpt = selectCat && selectCat.selectedIndex >= 0 ? selectCat.options[selectCat.selectedIndex] : null;
            const catMin = selectedCatOpt && selectedCatOpt.dataset.min ? parseInt(selectedCatOpt.dataset.min) : null;
            const catMax = selectedCatOpt && selectedCatOpt.dataset.max ? parseInt(selectedCatOpt.dataset.max) : null;

            Array.from(selectSub.options).forEach(option => {
                if (!option.value) {
                    option.hidden = false;
                    option.disabled = false;
                    return;
                }

                const subDeporte = option.dataset.deporte;
                const subCat = option.dataset.categoria;
                const subMin = option.dataset.min ? parseInt(option.dataset.min) : null;
                const subMax = option.dataset.max ? parseInt(option.dataset.max) : null;

                let visible = true;

                if (deporteId && subDeporte && subDeporte !== deporteId) {
                    visible = false;
                }

                if (visible && catId) {
                    if (subCat && subCat !== catId) {
                        visible = false;
                    } else if (catMin !== null && catMax !== null && subMin !== null && subMax !== null) {
                        if (subMin < catMin || subMax > catMax) {
                            visible = false;
                        }
                    }
                }

                option.hidden = !visible;
                option.disabled = !visible;
            });

            const currentSubOpt = selectSub.selectedIndex >= 0 ? selectSub.options[selectSub.selectedIndex] : null;
            if (currentSubOpt && currentSubOpt.disabled) {
                selectSub.value = '';
            }
        }

        if (selectDeporte) selectDeporte.addEventListener('change', filtrarSubcategorias);
        if (selectCat) selectCat.addEventListener('change', filtrarSubcategorias);

        filtrarSubcategorias();
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
