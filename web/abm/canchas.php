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

if (!function_exists('normalizar_espacio')) {
    function normalizar_espacio($texto) {
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

// Procesar Creación / Modificación de Canchas
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion']);
    $imagenURL = trim($_POST['imagenURL']);
    $techado = isset($_POST['techado']) ? 1 : 0;
    
    if (empty($nombre)) {
        $error_msg = 'El nombre del espacio es obligatorio.';
    } elseif (mb_strlen($nombre) < 2) {
        $error_msg = 'El nombre del espacio debe tener al menos 2 caracteres.';
    } elseif (!empty($imagenURL) && (!filter_var($imagenURL, FILTER_VALIDATE_URL) && !preg_match('/^https?:\/\//i', $imagenURL))) {
        $error_msg = 'La URL de la imagen debe ser un enlace válido (ejemplo: https://ejemplo.com/imagen.jpg).';
    } else {
        // Verificar que la entidad esté activa
        $stmt_p = $pdo->prepare("SELECT estado, nombre FROM polideportivos WHERE id = ?");
        $stmt_p->execute([$poli_id]);
        $poli_row = $stmt_p->fetch();
        if (!$poli_row || !$poli_row['estado']) {
            $error_msg = 'No es posible gestionar espacios porque la entidad se encuentra desactivada.';
        } else {
            $chk_id = ($_POST['action'] == 'editar') ? intval($_POST['id']) : 0;
            
            // Validar unicidad y similitud inteligente dentro de la entidad
            $stmt_all = $pdo->prepare("SELECT id, nombre FROM canchas WHERE fk_polideportivo = ? AND id != ?");
            $stmt_all->execute([$poli_id, $chk_id]);
            $canchas_existentes = $stmt_all->fetchAll();

            $norm_nuevo = normalizar_espacio($nombre);
            $num_nuevo = preg_replace('/\D/', '', $norm_nuevo);

            $nombre_duplicado = null;
            $es_muy_similar = false;

            foreach ($canchas_existentes as $can_ex) {
                $norm_existente = normalizar_espacio($can_ex['nombre']);
                $num_existente = preg_replace('/\D/', '', $norm_existente);

                // Si ambos contienen números y los números son diferentes (ej. "canchavoley1" vs "canchavoley2"), son espacios distintos
                if (!empty($num_nuevo) && !empty($num_existente) && intval($num_nuevo) !== intval($num_existente)) {
                    continue;
                }

                $base_nuevo = preg_replace('/[0-9]+/', '', $norm_nuevo);
                $base_existente = preg_replace('/[0-9]+/', '', $norm_existente);

                // 1. Coincidencia exacta ignorando tildes, mayúsculas, espacios y ceros a la izquierda
                if ($norm_nuevo === $norm_existente || ($base_nuevo === $base_existente && $num_nuevo !== '' && intval($num_nuevo) === intval($num_existente))) {
                    $nombre_duplicado = $can_ex['nombre'];
                    break;
                }

                // 2. Similitud alta si coinciden en número (o no tienen número) pero varían ligeramente en texto (ej. "cancha voley 1" vs "cancha voleyball 1")
                if (($num_nuevo === '' && $num_existente === '') || (intval($num_nuevo) === intval($num_existente) && $num_nuevo !== '')) {
                    similar_text($base_nuevo, $base_existente, $percent);
                    $lev = (strlen($base_nuevo) < 255 && strlen($base_existente) < 255) ? levenshtein($base_nuevo, $base_existente) : 999;
                    $es_prefijo = (strpos($base_existente, $base_nuevo) === 0 || strpos($base_nuevo, $base_existente) === 0) && abs(strlen($base_nuevo) - strlen($base_existente)) <= 4;

                    if ($percent >= 75 || $lev <= 2 || $es_prefijo) {
                        $nombre_duplicado = $can_ex['nombre'];
                        $es_muy_similar = true;
                        break;
                    }
                }
            }

            if ($nombre_duplicado !== null) {
                if ($es_muy_similar) {
                    $error_msg = "El nombre \"$nombre\" es equivalente o muy similar al espacio \"$nombre_duplicado\" que ya existe en esta entidad.";
                } else {
                    $error_msg = "Ya existe un espacio con el nombre \"$nombre_duplicado\" en esta entidad.";
                }
            } else {
                if ($_POST['action'] == 'crear') {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO canchas (nombre, descripcion, imagenURL, techado, fk_polideportivo, estado)
                            VALUES (?, ?, ?, ?, ?, TRUE)
                        ");
                        $stmt->execute([$nombre, $descripcion, $imagenURL, $techado, $poli_id]);
                        $success_msg = 'Espacio creado con éxito.';
                    } catch (PDOException $e) {
                        $error_msg = 'Error al crear el espacio.';
                    }
                } elseif ($_POST['action'] == 'editar') {
                    $id = intval($_POST['id']);
                    try {
                        $stmt = $pdo->prepare("
                            UPDATE canchas 
                            SET nombre = ?, descripcion = ?, imagenURL = ?, techado = ? 
                            WHERE id = ? AND fk_polideportivo = ?
                        ");
                        $stmt->execute([$nombre, $descripcion, $imagenURL, $techado, $id, $poli_id]);
                        $success_msg = 'Espacio modificado con éxito.';
                    } catch (PDOException $e) {
                        $error_msg = 'Error al modificar el espacio.';
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
        if ($nuevo_estado == 'TRUE') {
            // Verificar que la entidad esté activa
            $stmt_p = $pdo->prepare("SELECT estado, nombre FROM polideportivos WHERE id = ?");
            $stmt_p->execute([$poli_id]);
            $poli_row = $stmt_p->fetch();
            if (!$poli_row || !$poli_row['estado']) {
                $error_msg = 'No se puede activar el espacio porque la entidad se encuentra desactivada.';
            } else {
                $stmt = $pdo->prepare("UPDATE canchas SET estado = TRUE WHERE id = ? AND fk_polideportivo = ?");
                $stmt->execute([$id, $poli_id]);
                $success_msg = 'Espacio activado con éxito.';
            }
        } else {
            // Desactivar espacio
            $stmt = $pdo->prepare("UPDATE canchas SET estado = FALSE WHERE id = ? AND fk_polideportivo = ?");
            $stmt->execute([$id, $poli_id]);
            $success_msg = 'Espacio desactivado con éxito.';
        }
    } catch (PDOException $e) {
        $error_msg = 'Error al actualizar el estado.';
    }
}

// Cargar Canchas de la sede
$canchas = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM canchas WHERE fk_polideportivo = ? ORDER BY id ASC");
    $stmt->execute([$poli_id]);
    $canchas = $stmt->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark m-0">ABM Espacios</h2>
            <small class="text-muted">Administrando Entidad: <strong><?= htmlspecialchars($user['fk_polideportivo_nombre'] ?? 'Mi Entidad'); ?></strong></small>
        </div>
        <button class="poliba-btn" data-bs-toggle="modal" data-bs-target="#crearCanchaModal">Agregar Espacio</button>
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
                    <th>Descripción</th>
                    <th>Tipo</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($canchas)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted">No hay espacios registrados para esta entidad.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($canchas as $can): ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($can['nombre']); ?></td>
                            <td><?= htmlspecialchars($can['descripcion']); ?></td>
                            <td>
                                <span class="badge rounded-pill <?= $can['techado'] ? 'bg-dark' : 'bg-secondary'; ?>">
                                    <?= $can['techado'] ? 'Techado' : 'Descubierto'; ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge rounded-pill <?= $can['estado'] ? 'bg-success' : 'bg-danger'; ?>">
                                    <?= $can['estado'] ? 'Activo' : 'Inactivo'; ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-dark rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#editCanchaModal<?= $can['id']; ?>">Editar</button>
                                <a href="canchas.php?toggle_estado=<?= $can['id']; ?>&estado=<?= $can['estado'] ? '1' : '0'; ?>" 
                                   class="btn btn-sm <?= $can['estado'] ? 'btn-danger' : 'btn-success'; ?> rounded-pill px-3"
                                   onclick="return confirm('¿Seguro deseas cambiar el estado de este espacio?');">
                                    <?= $can['estado'] ? 'Desactivar' : 'Activar'; ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modales Editar Cancha (Fuera de la tabla para Bootstrap 5) -->
<?php foreach ($canchas as $can): ?>
    <div class="modal fade" id="editCanchaModal<?= $can['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form action="canchas.php" method="POST" class="modal-content border-0 shadow">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="id" value="<?= $can['id']; ?>">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold">Editar Espacio: <?= htmlspecialchars($can['nombre']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre del Espacio *</label>
                        <input type="text" name="nombre" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($can['nombre']); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Imagen URL</label>
                        <input type="url" name="imagenURL" class="form-control rounded-pill px-3" placeholder="https://ejemplo.com/cancha.jpg" value="<?= htmlspecialchars($can['imagenurl'] ?? $can['imagenURL'] ?? ''); ?>">
                    </div>
                    <div class="mb-3 form-check form-switch ms-1">
                        <input class="form-check-input" type="checkbox" name="techado" id="editTechado<?= $can['id']; ?>" <?= $can['techado'] ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold" for="editTechado<?= $can['id']; ?>">Espacio Techado</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Descripción / Ubicación</label>
                        <textarea name="descripcion" class="form-control px-3" rows="3" style="border-radius:15px;"><?= htmlspecialchars($can['descripcion'] ?? ''); ?></textarea>
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

<!-- Modal Crear Cancha -->
<div class="modal fade" id="crearCanchaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="canchas.php" method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="crear">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">Agregar Espacio</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">Nombre del Espacio *</label>
                    <input type="text" name="nombre" class="form-control rounded-pill px-3" required placeholder="Espacio Techado / Pista 2">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Imagen URL</label>
                    <input type="url" name="imagenURL" class="form-control rounded-pill px-3" placeholder="https://ejemplo.com/cancha.jpg">
                </div>
                <div class="mb-3 form-check form-switch ms-1">
                    <input class="form-check-input" type="checkbox" name="techado" id="crearTechado">
                    <label class="form-check-label fw-bold" for="crearTechado">Espacio Techado</label>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Descripción / Ubicación</label>
                    <textarea name="descripcion" class="form-control px-3" rows="3" style="border-radius:15px;" placeholder="Detalles de superficie, iluminación, etc..."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                <button type="submit" class="poliba-btn py-2">Crear Espacio</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
