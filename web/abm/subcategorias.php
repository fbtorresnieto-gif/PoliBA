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

if (!function_exists('normalizar_subcategoria')) {
    function normalizar_subcategoria($texto) {
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        $utf8 = [
            '/[áàâäã]/u' => 'a',
            '/[éèêë]/u' => 'e',
            '/[íìîï]/u' => 'i',
            '/[óòôöõ]/u' => 'o',
            '/[úùûü]/u' => 'u',
            '/[ñ]/u' => 'n',
            '/[^a-z0-9]/u' => ''
        ];
        return preg_replace(array_keys($utf8), array_values($utf8), $texto);
    }
}

// Procesar Creación / Modificación de Subcategorías
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $nombre = trim($_POST['nombre']);
    $raw_min = $_POST['edad_minima'] ?? '';
    $raw_max = $_POST['edad_maxima'] ?? '';
    $fk_deporte = intval($_POST['fk_deporte']);
    $fk_categoria = !empty($_POST['fk_categoria']) ? intval($_POST['fk_categoria']) : null;
    
    if (!is_numeric($raw_min) || !is_numeric($raw_max)) {
        $error_msg = 'Los campos de edad deben contener únicamente números.';
    } else {
        $edad_minima = intval($raw_min);
        $edad_maxima = intval($raw_max);
        
        if (empty($nombre) || $edad_minima < 0 || $edad_maxima <= 0 || $fk_deporte <= 0 || empty($fk_categoria)) {
        $error_msg = 'Por favor, completa todos los campos obligatorios (*).';
    } elseif ($edad_minima > $edad_maxima) {
        $error_msg = 'La edad mínima no puede ser mayor que la edad máxima.';
    } else {
        // Validar compatibilidad estricta con la Categoría seleccionada
        $compatible = true;
        if ($fk_categoria) {
            $stmt_cat = $pdo->prepare("SELECT nombre, edad_minima, edad_maxima FROM categoria WHERE id = ?");
            $stmt_cat->execute([$fk_categoria]);
            $cat_info = $stmt_cat->fetch();
            if ($cat_info) {
                if ($edad_minima < $cat_info['edad_minima']) {
                    $error_msg = "La edad mínima ingresada ({$edad_minima} años) no puede ser menor a la permitida por la categoría {$cat_info['nombre']} ({$cat_info['edad_minima']} años).";
                    $compatible = false;
                } elseif ($edad_maxima > $cat_info['edad_maxima']) {
                    $error_msg = "La edad máxima ingresada ({$edad_maxima} años) no puede ser mayor a la permitida por la categoría {$cat_info['nombre']} ({$cat_info['edad_maxima']} años).";
                    $compatible = false;
                }
            }
        }

        if ($compatible) {
            $chk_id = ($_POST['action'] == 'editar') ? intval($_POST['id']) : 0;
            
            // Cargar todas las subcategorías de la entidad (excluyendo la actual en edición)
            $stmt_all = $pdo->prepare("SELECT id, nombre, fk_deporte, fk_categoria, edad_minima, edad_maxima FROM subcategorias WHERE fk_polideportivo = ? AND id != ?");
            $stmt_all->execute([$poli_id, $chk_id]);
            $subcategorias_existentes = $stmt_all->fetchAll();

            $norm_nuevo = normalizar_subcategoria($nombre);
            $num_nuevo = preg_replace('/\D/', '', $norm_nuevo);

            $error_duplicado = null;

            foreach ($subcategorias_existentes as $sub_ex) {
                $norm_ex = normalizar_subcategoria($sub_ex['nombre']);
                $num_ex = preg_replace('/\D/', '', $norm_ex);

                // 1. Validar similitud/duplicado de nombre en la entidad
                $mismo_numero = (empty($num_nuevo) && empty($num_ex)) || ($num_nuevo === $num_ex);
                if ($norm_nuevo === $norm_ex && $mismo_numero) {
                    $error_duplicado = "Ya existe la subcategoría \"{$sub_ex['nombre']}\" registrada en esta entidad.";
                    break;
                }
                similar_text($norm_nuevo, $norm_ex, $percent);
                $lev = (strlen($norm_nuevo) < 255 && strlen($norm_ex) < 255) ? levenshtein($norm_nuevo, $norm_ex) : 999;
                if (($percent >= 88 || $lev <= 1) && $mismo_numero && strlen($norm_nuevo) >= 4) {
                    $error_duplicado = "El nombre de la subcategoría es muy similar a la subcategoría existente \"{$sub_ex['nombre']}\". Por favor, usa un nombre más descriptivo.";
                    break;
                }

                // 2. Validar mismo rango de edad para la misma actividad y misma categoría general
                $misma_actividad = (intval($sub_ex['fk_deporte']) === $fk_deporte);
                $misma_cat = (empty($sub_ex['fk_categoria']) && empty($fk_categoria)) || (intval($sub_ex['fk_categoria'] ?? 0) === intval($fk_categoria ?? 0));
                $mismo_rango = (intval($sub_ex['edad_minima']) === $edad_minima && intval($sub_ex['edad_maxima']) === $edad_maxima);

                if ($misma_actividad && $misma_cat && $mismo_rango) {
                    $error_duplicado = "Ya existe la subcategoría \"{$sub_ex['nombre']}\" con el mismo rango de edad ({$edad_minima} a {$edad_maxima} años) para la actividad y categoría seleccionadas.";
                    break;
                }
            }

            if ($error_duplicado) {
                $error_msg = $error_duplicado;
            } else {
                if ($_POST['action'] == 'crear') {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO subcategorias (nombre, edad_minima, edad_maxima, fk_deporte, fk_categoria, fk_polideportivo, estado)
                            VALUES (?, ?, ?, ?, ?, ?, TRUE)
                        ");
                        $stmt->execute([$nombre, $edad_minima, $edad_maxima, $fk_deporte, $fk_categoria, $poli_id]);
                        $success_msg = 'Subcategoría creada con éxito.';
                    } catch (PDOException $e) {
                        $error_msg = 'Error al crear la subcategoría.';
                    }
                } elseif ($_POST['action'] == 'editar') {
                    $id = intval($_POST['id']);
                    try {
                        $stmt = $pdo->prepare("
                            UPDATE subcategorias 
                            SET nombre = ?, edad_minima = ?, edad_maxima = ?, fk_deporte = ?, fk_categoria = ? 
                            WHERE id = ? AND fk_polideportivo = ?
                        ");
                        $stmt->execute([$nombre, $edad_minima, $edad_maxima, $fk_deporte, $fk_categoria, $id, $poli_id]);
                        $success_msg = 'Subcategoría modificada con éxito.';
                    } catch (PDOException $e) {
                        $error_msg = 'Error al modificar la subcategoría.';
                    }
                }
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
        $stmt = $pdo->prepare("UPDATE subcategorias SET estado = $nuevo_estado WHERE id = ? AND fk_polideportivo = ?");
        $stmt->execute([$id, $poli_id]);
        $success_msg = 'Estado de la subcategoría actualizado.';
    } catch (PDOException $e) {
        $error_msg = 'Error al actualizar el estado.';
    }
}

// Cargar Subcategorías de la sede
$subcategorias = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.*, d.nombre as deporte_nombre, c.nombre as categoria_nombre 
        FROM subcategorias s
        JOIN deportes d ON s.fk_deporte = d.id
        LEFT JOIN categoria c ON s.fk_categoria = c.id
        WHERE s.fk_polideportivo = ?
        ORDER BY s.id ASC
    ");
    $stmt->execute([$poli_id]);
    $subcategorias = $stmt->fetchAll();
} catch (PDOException $e) {}

// Cargar Deportes para selects
$deportes = [];
try {
    $stmt = $pdo->prepare("SELECT id, nombre FROM deportes WHERE fk_polideportivo = ? AND estado = TRUE ORDER BY nombre ASC");
    $stmt->execute([$poli_id]);
    $deportes = $stmt->fetchAll();
} catch (PDOException $e) {}

// Cargar Categorías generales para selects con límites de edad
$categorias = [];
try {
    $stmt = $pdo->query("SELECT id, nombre, edad_minima, edad_maxima FROM categoria ORDER BY id ASC");
    $categorias = $stmt->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark m-0">ABM Subcategorías</h2>
            <small class="text-muted">Administrando Entidad: <strong><?= htmlspecialchars($user['fk_polideportivo_nombre'] ?? 'Mi Entidad'); ?></strong></small>
        </div>
        <button class="poliba-btn" data-bs-toggle="modal" data-bs-target="#crearSubModal">Agregar Subcategoría</button>
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
                    <th>Categoría General</th>
                    <th>Rango Edad</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($subcategorias)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">No hay subcategorías registradas para esta entidad.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($subcategorias as $sub): ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($sub['nombre']); ?></td>
                            <td><?= htmlspecialchars($sub['deporte_nombre']); ?></td>
                            <td><?= htmlspecialchars($sub['categoria_nombre'] ?? 'General'); ?></td>
                            <td><?= $sub['edad_minima']; ?> a <?= $sub['edad_maxima']; ?> años</td>
                            <td>
                                <span class="badge rounded-pill <?= $sub['estado'] ? 'bg-success' : 'bg-danger'; ?>">
                                    <?= $sub['estado'] ? 'Activa' : 'Inactiva'; ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-dark rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#editSubModal<?= $sub['id']; ?>">Editar</button>
                                <a href="subcategorias.php?toggle_estado=<?= $sub['id']; ?>&estado=<?= $sub['estado'] ? '1' : '0'; ?>" 
                                   class="btn btn-sm <?= $sub['estado'] ? 'btn-danger' : 'btn-success'; ?> rounded-pill px-3"
                                   onclick="return confirm('¿Seguro deseas cambiar el estado de esta subcategoría?');">
                                    <?= $sub['estado'] ? 'Desactivar' : 'Activar'; ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modales Editar Subcategoría (Fuera de la tabla para Bootstrap 5) -->
<?php foreach ($subcategorias as $sub): ?>
    <div class="modal fade" id="editSubModal<?= $sub['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form action="subcategorias.php" method="POST" class="modal-content border-0 shadow">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="id" value="<?= $sub['id']; ?>">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold">Editar Subcategoría #<?= $sub['id']; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre *</label>
                        <input type="text" name="nombre" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($sub['nombre']); ?>">
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Actividad *</label>
                            <select name="fk_deporte" class="form-select rounded-pill px-3" required>
                                <?php foreach ($deportes as $dep): ?>
                                    <option value="<?= $dep['id']; ?>" <?= $sub['fk_deporte'] == $dep['id'] ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($dep['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Categoría General *</label>
                            <select name="fk_categoria" class="form-select rounded-pill px-3" required>
                                <option value="">-- Seleccionar --</option>
                                <?php foreach ($categorias as $cat): ?>
                                    <option value="<?= $cat['id']; ?>" 
                                            data-min="<?= $cat['edad_minima']; ?>" 
                                            data-max="<?= $cat['edad_maxima']; ?>" 
                                            <?= $sub['fk_categoria'] == $cat['id'] ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($cat['nombre']); ?> (<?= $cat['edad_minima']; ?>-<?= $cat['edad_maxima']; ?> años)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Edad Mínima *</label>
                            <input type="number" name="edad_minima" class="form-control rounded-pill px-3" required min="0" oninput="this.value = this.value.replace(/[^0-9]/g, '')" value="<?= $sub['edad_minima']; ?>">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Edad Máxima *</label>
                            <input type="number" name="edad_maxima" class="form-control rounded-pill px-3" required min="0" oninput="this.value = this.value.replace(/[^0-9]/g, '')" value="<?= $sub['edad_maxima']; ?>">
                        </div>
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

<!-- Modal Crear Subcategoría -->
<div class="modal fade" id="crearSubModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="subcategorias.php" method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="crear">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">Agregar Subcategoría</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">Nombre *</label>
                    <input type="text" name="nombre" class="form-control rounded-pill px-3" required placeholder="Vóley Cadetes">
                </div>

                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Actividad *</label>
                        <select name="fk_deporte" class="form-select rounded-pill px-3" required>
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($deportes as $dep): ?>
                                <option value="<?= $dep['id']; ?>"><?= htmlspecialchars($dep['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Categoría General *</label>
                        <select name="fk_categoria" class="form-select rounded-pill px-3" required>
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?= $cat['id']; ?>" 
                                        data-min="<?= $cat['edad_minima']; ?>" 
                                        data-max="<?= $cat['edad_maxima']; ?>">
                                    <?= htmlspecialchars($cat['nombre']); ?> (<?= $cat['edad_minima']; ?>-<?= $cat['edad_maxima']; ?> años)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Edad Mínima *</label>
                        <input type="number" name="edad_minima" class="form-control rounded-pill px-3" required placeholder="13" min="0" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Edad Máxima *</label>
                        <input type="number" name="edad_maxima" class="form-control rounded-pill px-3" required placeholder="17" min="0" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                <button type="submit" class="poliba-btn py-2">Crear Subcategoría</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectsCat = document.querySelectorAll('select[name="fk_categoria"]');
    
    selectsCat.forEach(select => {
        const form = select.closest('form');
        if (!form) return;
        
        const inputMin = form.querySelector('input[name="edad_minima"]');
        const inputMax = form.querySelector('input[name="edad_maxima"]');
        
        function applyLimits(autoFillValues = false) {
            const selectedOpt = select.options[select.selectedIndex];
            const minCat = selectedOpt ? selectedOpt.getAttribute('data-min') : null;
            const maxCat = selectedOpt ? selectedOpt.getAttribute('data-max') : null;
            
            if (minCat !== null && maxCat !== null && minCat !== "" && maxCat !== "") {
                const minVal = parseInt(minCat, 10);
                const maxVal = parseInt(maxCat, 10);
                
                if (inputMin) {
                    inputMin.min = minVal;
                    inputMin.max = maxVal;
                    if (autoFillValues) {
                        inputMin.value = minVal;
                    }
                }
                if (inputMax) {
                    inputMax.min = minVal;
                    inputMax.max = maxVal;
                    if (autoFillValues) {
                        inputMax.value = maxVal;
                    }
                }
            } else {
                if (inputMin) {
                    inputMin.min = 0;
                    inputMin.removeAttribute('max');
                }
                if (inputMax) {
                    inputMax.min = 0;
                    inputMax.removeAttribute('max');
                }
            }
            validateCurrentInputs();
        }
        
        function validateCurrentInputs() {
            const selectedOpt = select.options[select.selectedIndex];
            const minCat = selectedOpt ? selectedOpt.getAttribute('data-min') : null;
            const maxCat = selectedOpt ? selectedOpt.getAttribute('data-max') : null;
            
            if (minCat === null || maxCat === null || minCat === "" || maxCat === "") return;
            
            const minAllowed = parseInt(minCat, 10);
            const maxAllowed = parseInt(maxCat, 10);
            
            if (inputMin && inputMin.value !== '') {
                let val = parseInt(inputMin.value, 10);
                if (!isNaN(val)) {
                    if (val < minAllowed) inputMin.value = minAllowed;
                    if (val > maxAllowed) inputMin.value = maxAllowed;
                }
            }
            
            if (inputMax && inputMax.value !== '') {
                let val = parseInt(inputMax.value, 10);
                if (!isNaN(val)) {
                    if (val > maxAllowed) inputMax.value = maxAllowed;
                    if (val < minAllowed) inputMax.value = minAllowed;
                }
            }
        }
        
        select.addEventListener('change', function() {
            applyLimits(true);
        });
        
        if (inputMin) {
            inputMin.addEventListener('change', validateCurrentInputs);
            inputMin.addEventListener('blur', validateCurrentInputs);
            inputMin.addEventListener('input', validateCurrentInputs);
        }
        if (inputMax) {
            inputMax.addEventListener('change', validateCurrentInputs);
            inputMax.addEventListener('blur', validateCurrentInputs);
            inputMax.addEventListener('input', validateCurrentInputs);
        }
        
        applyLimits(false);
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
