<?php
$base_path = '../';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Validar que sea Administrador
redirect_if_not_logged_in(['Administrador']);

$user = get_logged_user();
$entidad_id = $user['fk_entidad']; // Entidad administrada

if (!$entidad_id) {
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

// Procesar creación o modificación de espacios
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
        $stmt_entidad = $pdo->prepare("SELECT estado, nombre FROM entidades WHERE id = ?");
        $stmt_entidad->execute([$entidad_id]);
        $entidad_row = $stmt_entidad->fetch();
        if (!$entidad_row || !$entidad_row['estado']) {
            $error_msg = 'No es posible gestionar espacios porque la entidad se encuentra desactivada.';
        } else {
            $chk_id = ($_POST['action'] == 'editar') ? intval($_POST['id']) : 0;
            
            // Validar unicidad y similitud inteligente dentro de la entidad
<<<<<<< HEAD:web/abm/canchas.php
            $stmt_all = $pdo->prepare("SELECT id, nombre FROM canchas WHERE fk_entidad = ? AND id != ?");
            $stmt_all->execute([$entidad_id, $chk_id]);
            $canchas_existentes = $stmt_all->fetchAll();
=======
            $stmt_all = $pdo->prepare("SELECT id, nombre FROM espacios WHERE fk_entidad = ? AND id != ?");
            $stmt_all->execute([$entidad_id, $chk_id]);
            $espacios_existentes = $stmt_all->fetchAll();
>>>>>>> 52d9ac9 (Actualiza canchas por espacios):web/abm/espacios.php

            $norm_nuevo = normalizar_espacio($nombre);
            $num_nuevo = preg_replace('/\D/', '', $norm_nuevo);

            $nombre_duplicado = null;
            $es_muy_similar = false;

            foreach ($espacios_existentes as $espacio_existente) {
                $norm_existente = normalizar_espacio($espacio_existente['nombre']);
                $num_existente = preg_replace('/\D/', '', $norm_existente);

                // Si ambos contienen números y son diferentes (ej. "espaciovoley1" vs "espaciovoley2"), son espacios distintos
                if (!empty($num_nuevo) && !empty($num_existente) && intval($num_nuevo) !== intval($num_existente)) {
                    continue;
                }

                $base_nuevo = preg_replace('/[0-9]+/', '', $norm_nuevo);
                $base_existente = preg_replace('/[0-9]+/', '', $norm_existente);

                // 1. Coincidencia exacta ignorando tildes, mayúsculas, espacios y ceros a la izquierda
                if ($norm_nuevo === $norm_existente || ($base_nuevo === $base_existente && $num_nuevo !== '' && intval($num_nuevo) === intval($num_existente))) {
                    $nombre_duplicado = $espacio_existente['nombre'];
                    break;
                }

                // 2. Similitud alta si coinciden en número (o no tienen número) pero varían ligeramente en texto (ej. "espacio voley 1" vs "espacio voleyball 1")
                if (($num_nuevo === '' && $num_existente === '') || (intval($num_nuevo) === intval($num_existente) && $num_nuevo !== '')) {
                    similar_text($base_nuevo, $base_existente, $percent);
                    $lev = (strlen($base_nuevo) < 255 && strlen($base_existente) < 255) ? levenshtein($base_nuevo, $base_existente) : 999;
                    $es_prefijo = (strpos($base_existente, $base_nuevo) === 0 || strpos($base_nuevo, $base_existente) === 0) && abs(strlen($base_nuevo) - strlen($base_existente)) <= 4;

                    if ($percent >= 75 || $lev <= 2 || $es_prefijo) {
                        $nombre_duplicado = $espacio_existente['nombre'];
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
<<<<<<< HEAD:web/abm/canchas.php
                            INSERT INTO canchas (nombre, descripcion, imagenURL, techado, fk_entidad, estado)
=======
                            INSERT INTO espacios (nombre, descripcion, imagenURL, techado, fk_entidad, estado)
>>>>>>> 52d9ac9 (Actualiza canchas por espacios):web/abm/espacios.php
                            VALUES (?, ?, ?, ?, ?, TRUE)
                        ");
                        $stmt->execute([$nombre, $descripcion, $imagenURL, $techado, $entidad_id]);
                        $success_msg = 'Espacio creado con éxito.';
                    } catch (PDOException $e) {
                        $error_msg = 'Error al crear el espacio.';
                    }
                } elseif ($_POST['action'] == 'editar') {
                    $id = intval($_POST['id']);
                    try {
                        $stmt = $pdo->prepare("
                            UPDATE espacios
                            SET nombre = ?, descripcion = ?, imagenURL = ?, techado = ? 
                            WHERE id = ? AND fk_entidad = ?
                        ");
                        $stmt->execute([$nombre, $descripcion, $imagenURL, $techado, $id, $entidad_id]);
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
            $stmt_entidad = $pdo->prepare("SELECT estado, nombre FROM entidades WHERE id = ?");
            $stmt_entidad->execute([$entidad_id]);
            $entidad_row = $stmt_entidad->fetch();
            if (!$entidad_row || !$entidad_row['estado']) {
                $error_msg = 'No se puede activar el espacio porque la entidad se encuentra desactivada.';
            } else {
<<<<<<< HEAD:web/abm/canchas.php
                $stmt = $pdo->prepare("UPDATE canchas SET estado = TRUE WHERE id = ? AND fk_entidad = ?");
=======
                $stmt = $pdo->prepare("UPDATE espacios SET estado = TRUE WHERE id = ? AND fk_entidad = ?");
>>>>>>> 52d9ac9 (Actualiza canchas por espacios):web/abm/espacios.php
                $stmt->execute([$id, $entidad_id]);
                $success_msg = 'Espacio activado con éxito.';
            }
        } else {
            // Desactivar espacio
<<<<<<< HEAD:web/abm/canchas.php
            $stmt = $pdo->prepare("UPDATE canchas SET estado = FALSE WHERE id = ? AND fk_entidad = ?");
=======
            $stmt = $pdo->prepare("UPDATE espacios SET estado = FALSE WHERE id = ? AND fk_entidad = ?");
>>>>>>> 52d9ac9 (Actualiza canchas por espacios):web/abm/espacios.php
            $stmt->execute([$id, $entidad_id]);
            $success_msg = 'Espacio desactivado con éxito.';
        }
    } catch (PDOException $e) {
        $error_msg = 'Error al actualizar el estado.';
    }
}

// Cargar espacios de la entidad
$espacios = [];
try {
<<<<<<< HEAD:web/abm/canchas.php
    $stmt = $pdo->prepare("SELECT * FROM canchas WHERE fk_entidad = ? ORDER BY id ASC");
    $stmt->execute([$entidad_id]);
    $canchas = $stmt->fetchAll();
=======
    $stmt = $pdo->prepare("SELECT * FROM espacios WHERE fk_entidad = ? ORDER BY id ASC");
    $stmt->execute([$entidad_id]);
    $espacios = $stmt->fetchAll();
>>>>>>> 52d9ac9 (Actualiza canchas por espacios):web/abm/espacios.php
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark m-0">ABM Espacios</h2>
            <small class="text-muted">Administrando Entidad: <strong><?= htmlspecialchars($user['fk_entidad_nombre'] ?? 'Mi Entidad'); ?></strong></small>
        </div>
        <button class="poliba-btn" data-bs-toggle="modal" data-bs-target="#crearEspacioModal">Agregar Espacio</button>
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
                <?php if (empty($espacios)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted">No hay espacios registrados para esta entidad.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($espacios as $espacio): ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($espacio['nombre']); ?></td>
                            <td><?= htmlspecialchars($espacio['descripcion']); ?></td>
                            <td>
                                <span class="badge rounded-pill <?= $espacio['techado'] ? 'bg-dark' : 'bg-secondary'; ?>">
                                    <?= $espacio['techado'] ? 'Techado' : 'Descubierto'; ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge rounded-pill <?= $espacio['estado'] ? 'bg-success' : 'bg-danger'; ?>">
                                    <?= $espacio['estado'] ? 'Activo' : 'Inactivo'; ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-dark rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#editEspacioModal<?= $espacio['id']; ?>">Editar</button>
                                <a href="espacios.php?toggle_estado=<?= $espacio['id']; ?>&estado=<?= $espacio['estado'] ? '1' : '0'; ?>"
                                   class="btn btn-sm <?= $espacio['estado'] ? 'btn-danger' : 'btn-success'; ?> rounded-pill px-3"
                                   onclick="return confirm('¿Seguro deseas cambiar el estado de este espacio?');">
                                    <?= $espacio['estado'] ? 'Desactivar' : 'Activar'; ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modales para editar espacios (fuera de la tabla para Bootstrap 5) -->
<?php foreach ($espacios as $espacio): ?>
    <div class="modal fade" id="editEspacioModal<?= $espacio['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form action="espacios.php" method="POST" class="modal-content border-0 shadow">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="id" value="<?= $espacio['id']; ?>">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold">Editar Espacio: <?= htmlspecialchars($espacio['nombre']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre del Espacio *</label>
                        <input type="text" name="nombre" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($espacio['nombre']); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Imagen URL</label>
                        <input type="url" name="imagenURL" class="form-control rounded-pill px-3" placeholder="https://ejemplo.com/espacio.jpg" value="<?= htmlspecialchars($espacio['imagenurl'] ?? $espacio['imagenURL'] ?? ''); ?>">
                    </div>
                    <div class="mb-3 form-check form-switch ms-1">
                        <input class="form-check-input" type="checkbox" name="techado" id="editTechado<?= $espacio['id']; ?>" <?= $espacio['techado'] ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold" for="editTechado<?= $espacio['id']; ?>">Espacio Techado</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Descripción / Ubicación</label>
                        <textarea name="descripcion" class="form-control px-3" rows="3" style="border-radius:15px;"><?= htmlspecialchars($espacio['descripcion'] ?? ''); ?></textarea>
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

<!-- Modal para crear un espacio -->
<div class="modal fade" id="crearEspacioModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="espacios.php" method="POST" class="modal-content border-0 shadow">
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
                    <input type="url" name="imagenURL" class="form-control rounded-pill px-3" placeholder="https://ejemplo.com/espacio.jpg">
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
