<?php
$base_path = '../';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Validar que sea Administrador
redirect_if_not_logged_in(['Administrador']);

$user = get_logged_user();
$poli_id = $user['fk_polideportivo']; // Sede administrada

if (!$poli_id) {
    die("Error: El administrador no tiene una sede polideportiva asignada.");
}

$error_msg = '';
$success_msg = '';

// Procesar Creación / Modificación de Clases
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
    
    if (empty($nombre) || empty($horario_inicio) || empty($horario_cierre) || $cupo_maximo <= 0 || $fk_deporte <= 0) {
        $error_msg = 'Por favor, completa los campos obligatorios.';
    } elseif (empty($dias_seleccionados)) {
        $error_msg = 'Debes seleccionar al menos un día de dictado para la clase.';
    } else {
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
                $success_msg = 'Clase creada con éxito.';
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
                $success_msg = 'Clase modificada con éxito.';
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error_msg = 'Error al registrar los datos en la base de datos: ' . $e->getMessage();
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
        $success_msg = 'Estado de la clase actualizado.';
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
    
    $stmt = $pdo->query("SELECT id, nombre FROM categoria ORDER BY id ASC");
    $categorias = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT id, nombre FROM subcategorias WHERE fk_polideportivo = ? AND estado = TRUE ORDER BY nombre ASC");
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
            <h2 class="fw-bold text-dark m-0">ABM Clases</h2>
            <small class="text-muted">Administrando Sede: <strong><?= htmlspecialchars($user['fk_polideportivo_nombre'] ?? 'Mi Polideportivo'); ?></strong></small>
        </div>
        <button class="poliba-btn" data-bs-toggle="modal" data-bs-target="#crearClaseModal">Agregar Clase</button>
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
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>Deporte</th>
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
                        <td colspan="8" class="text-center text-muted">No hay clases registradas para esta sede.</td>
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
                            <td>#<?= $clase['id']; ?></td>
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
                                   onclick="return confirm('¿Seguro deseas cambiar el estado de esta clase?');">
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
                    <h5 class="modal-title fw-bold">Editar Clase #<?= $clase['id']; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Nombre de la Clase *</label>
                            <input type="text" name="nombre" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($clase['nombre']); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Cupo Máximo *</label>
                            <input type="number" name="cupo_maximo" class="form-control rounded-pill px-3" required value="<?= $clase['cupo_maximo']; ?>" min="1">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Hora Inicio *</label>
                            <input type="time" name="horario_inicio" class="form-control rounded-pill px-3" required value="<?= $clase['horario_inicio']; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Hora Cierre *</label>
                            <input type="time" name="horario_cierre" class="form-control rounded-pill px-3" required value="<?= $clase['horario_cierre']; ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Deporte *</label>
                            <select name="fk_deporte" class="form-select rounded-pill px-3" required>
                                <?php foreach ($deportes as $dep): ?>
                                    <option value="<?= $dep['id']; ?>" <?= $clase['fk_deporte'] == $dep['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($dep['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
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
                            <label class="form-label fw-bold">Cancha</label>
                            <select name="fk_canchas" class="form-select rounded-pill px-3">
                                <option value="">-- Sede General --</option>
                                <?php foreach ($canchas as $can): ?>
                                    <option value="<?= $can['id']; ?>" <?= $clase['fk_canchas'] == $can['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($can['nombre']); ?></option>
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
                                    <option value="<?= $cat['id']; ?>" <?= $clase['fk_categoria'] == $cat['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($cat['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Subcategoría Especial</label>
                            <select name="fk_subcategoria" class="form-select rounded-pill px-3">
                                <option value="">-- Ninguna --</option>
                                <?php foreach ($subcategorias as $sub): ?>
                                    <option value="<?= $sub['id']; ?>" <?= $clase['fk_subcategoria'] == $sub['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($sub['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold d-block">Días de Dictado *</label>
                        <div class="d-flex flex-wrap gap-3">
                            <?php foreach ($dias as $dia): ?>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="dias[]" 
                                           id="editDia_<?= $clase['id']; ?>_<?= $dia['id']; ?>" 
                                           value="<?= $dia['id']; ?>" 
                                           <?= in_array($dia['id'], $clase['dias']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="editDia_<?= $clase['id']; ?>_<?= $dia['id']; ?>"><?= htmlspecialchars($dia['nombre']); ?></label>
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

<!-- Modal Crear Clase -->
<div class="modal fade" id="crearClaseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form action="clases.php" method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="crear">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">Agregar Clase</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Nombre de la Clase *</label>
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
                        <input type="time" name="horario_inicio" class="form-control rounded-pill px-3" required value="17:00">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Hora Cierre *</label>
                        <input type="time" name="horario_cierre" class="form-control rounded-pill px-3" required value="19:00">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">Deporte *</label>
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
                        <label class="form-label fw-bold">Cancha</label>
                        <select name="fk_canchas" class="form-select rounded-pill px-3">
                            <option value="">-- Sede General --</option>
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
                                <option value="<?= $cat['id']; ?>"><?= htmlspecialchars($cat['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Subcategoría Especial</label>
                        <select name="fk_subcategoria" class="form-select rounded-pill px-3">
                            <option value="">-- Ninguna --</option>
                            <?php foreach ($subcategorias as $sub): ?>
                                <option value="<?= $sub['id']; ?>"><?= htmlspecialchars($sub['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold d-block">Días de Dictado *</label>
                    <div class="d-flex flex-wrap gap-3">
                        <?php foreach ($dias as $dia): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="dias[]" id="crearDia_<?= $dia['id']; ?>" value="<?= $dia['id']; ?>">
                                <label class="form-check-label" for="crearDia_<?= $dia['id']; ?>"><?= htmlspecialchars($dia['nombre']); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Descripción / Requisitos</label>
                    <textarea name="descripcion" class="form-control px-3" rows="3" style="border-radius:15px;" placeholder="Descripción de la clase, nivel, requisitos de indumentaria..."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                <button type="submit" class="poliba-btn py-2">Crear Clase</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
