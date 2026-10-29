<?php
$base_path = '../';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/pagos/ConfiguracionEspacio.php';

// Validar que sea Administrador
redirect_if_not_logged_in(['Administrador']);

$user = get_logged_user();
$entidad_id = $user['fk_entidad']; // Entidad administrada

if (!$entidad_id) {
    die("Error: El administrador no tiene una entidad asignada.");
}

$error_msg = '';
$success_msg = '';
$_SESSION['csrf_espacios'] ??= bin2hex(random_bytes(32));
$cobro = null;
$error_cobro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_recibido = $_POST['csrf_espacios'] ?? '';
    if (!is_string($csrf_recibido) || !hash_equals($_SESSION['csrf_espacios'], $csrf_recibido)) {
        $error_cobro = 'La sesión del formulario venció. Recargá la página e intentá nuevamente.';
    } elseif (!is_string($_POST['action'] ?? null) || !in_array($_POST['action'], ['crear','editar'], true)) {
        $error_cobro = 'La acción solicitada no es válida.';
    } elseif ($_POST['action'] === 'editar' && (!is_string($_POST['id'] ?? null) ||
        filter_var($_POST['id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) === false)) {
        $error_cobro = 'El espacio seleccionado no es válido.';
    } else {
        try {
            $cobro = \MultiPro\Pagos\ConfiguracionEspacio::leer($_POST);
        } catch (InvalidArgumentException $e) {
            $error_cobro = $e->getMessage();
        }
    }
}

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
    $nombre = is_string($_POST['nombre'] ?? null) ? trim($_POST['nombre']) : '';
    $descripcion = is_string($_POST['descripcion'] ?? null) ? trim($_POST['descripcion']) : '';
    $imagenURL = is_string($_POST['imagenURL'] ?? null) ? trim($_POST['imagenURL']) : '';
    $techado = isset($_POST['techado']) ? 1 : 0;
    
    if ($error_cobro !== '') {
        $error_msg = $error_cobro;
    } elseif (empty($nombre)) {
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
            $stmt_all = $pdo->prepare("SELECT id, nombre FROM espacios WHERE fk_entidad = ? AND id != ?");
            $stmt_all->execute([$entidad_id, $chk_id]);
            $espacios_existentes = $stmt_all->fetchAll();

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
                            INSERT INTO espacios (nombre, descripcion, imagenURL, techado, fk_entidad, estado,
                                precio_hora_ars, cobro_online_habilitado, pago_presencial_habilitado)
                            VALUES (?, ?, ?, ?, ?, TRUE, ?, FALSE, ?)
                        ");
                        $stmt->execute([$nombre, $descripcion, $imagenURL, $techado, $entidad_id,
                            $cobro['precio_hora_ars'], $cobro['pago_presencial_habilitado'] ? 'true' : 'false']);
                        $success_msg = 'Espacio creado con éxito.';
                    } catch (PDOException $e) {
                        $error_msg = 'Error al crear el espacio.';
                    }
                } elseif ($_POST['action'] == 'editar') {
                    $id = intval($_POST['id']);
                    try {
                        $stmt = $pdo->prepare("
                            UPDATE espacios
                            SET nombre = ?, descripcion = ?, imagenURL = ?, techado = ?,
                                precio_hora_ars = ?, cobro_online_habilitado = FALSE, pago_presencial_habilitado = ?
                            WHERE id = ? AND fk_entidad = ?
                        ");
                        $stmt->execute([$nombre, $descripcion, $imagenURL, $techado, $cobro['precio_hora_ars'],
                            $cobro['pago_presencial_habilitado'] ? 'true' : 'false', $id, $entidad_id]);
                        if ($stmt->rowCount() === 1) {
                            $success_msg = 'Espacio modificado con éxito.';
                        } else {
                            $error_msg = 'El espacio no está disponible para esta entidad.';
                        }
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
                $stmt = $pdo->prepare("UPDATE espacios SET estado = TRUE WHERE id = ? AND fk_entidad = ?");
                $stmt->execute([$id, $entidad_id]);
                $success_msg = 'Espacio activado con éxito.';
            }
        } else {
            // Desactivar espacio
            $stmt = $pdo->prepare("UPDATE espacios SET estado = FALSE WHERE id = ? AND fk_entidad = ?");
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
    $stmt = $pdo->prepare("SELECT * FROM espacios WHERE fk_entidad = ? ORDER BY id ASC");
    $stmt->execute([$entidad_id]);
    $espacios = $stmt->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';

function formulario_espacio(array $base, string $accion): array {
    global $error_msg;
    if ($error_msg === '' || ($_POST['action'] ?? null) !== $accion ||
        ($accion === 'editar' && (string)($base['id'] ?? '') !== ($_POST['id'] ?? null))) return $base;
    foreach (['nombre','descripcion','imagenURL','precio_hora_ars'] as $campo) {
        $base[$campo] = is_string($_POST[$campo] ?? null) ? $_POST[$campo] : '';
    }
    $base['imagenurl'] = $base['imagenURL'];
    $base['techado'] = isset($_POST['techado']);
    $base['pago_presencial_habilitado'] = ($_POST['pago_presencial_habilitado'] ?? null) === '1';
    return $base;
}
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

    <div class="alert alert-info" role="status">Con precio mayor a $0, habilitá pago presencial para permitir reservas con pago pendiente en la entidad. El pago online todavía no está disponible.</div>
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
                    <th>Precio por hora</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($espacios)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">No hay espacios registrados para esta entidad.</td>
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
                                <span class="fw-bold"><?= htmlspecialchars(\MultiPro\Pagos\ConfiguracionEspacio::etiqueta($espacio['precio_hora_ars'])); ?></span>
                                <?php if ($espacio['precio_hora_ars'] !== '0.00'): ?>
                                    <small class="d-block text-muted"><?= $espacio['pago_presencial_habilitado'] ? 'Presencial configurado' : 'Sin medio de pago configurado'; ?></small>
                                    <small class="d-block text-muted"><?= $espacio['pago_presencial_habilitado'] ? 'Reserva con pago pendiente en la entidad' : 'Reservas no disponibles sin medio de pago'; ?></small>
                                <?php endif; ?>
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
<?php foreach ($espacios as $espacio): $formEspacio = formulario_espacio($espacio, 'editar');
    $reintento = $error_msg !== '' && ($_POST['action'] ?? null) === 'editar' && ($_POST['id'] ?? null) === (string)$espacio['id']; ?>
    <div class="modal fade" id="editEspacioModal<?= $espacio['id']; ?>" tabindex="-1" aria-hidden="true" <?= $reintento ? 'data-reabrir' : ''; ?>>
        <div class="modal-dialog modal-dialog-scrollable">
            <form action="espacios.php" method="POST" class="modal-content border-0 shadow">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="csrf_espacios" value="<?= htmlspecialchars($_SESSION['csrf_espacios']); ?>">
                <input type="hidden" name="id" value="<?= $espacio['id']; ?>">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold">Editar Espacio: <?= htmlspecialchars($espacio['nombre']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <?php if ($reintento): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error_msg); ?></div><?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre del Espacio *</label>
                        <input type="text" name="nombre" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($formEspacio['nombre']); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Imagen URL</label>
                        <input type="url" name="imagenURL" class="form-control rounded-pill px-3" placeholder="https://ejemplo.com/espacio.jpg" value="<?= htmlspecialchars($formEspacio['imagenurl'] ?? $formEspacio['imagenURL'] ?? ''); ?>">
                    </div>
                    <div class="mb-3 form-check form-switch ms-1">
                        <input class="form-check-input" type="checkbox" name="techado" id="editTechado<?= $espacio['id']; ?>" <?= $formEspacio['techado'] ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold" for="editTechado<?= $espacio['id']; ?>">Espacio Techado</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Descripción / Ubicación</label>
                        <textarea name="descripcion" class="form-control px-3" rows="3" style="border-radius:15px;"><?= htmlspecialchars($formEspacio['descripcion'] ?? ''); ?></textarea>
                    </div>
                    <?php $cobroEspacio = $formEspacio; $sufijoCobro = 'Editar' . $espacio['id']; require __DIR__ . '/../includes/pagos/campos_espacio.php'; ?>
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
<?php $formCrear = formulario_espacio([], 'crear'); $reintentoCrear = $error_msg !== '' && ($_POST['action'] ?? null) === 'crear'; ?>
<div class="modal fade" id="crearEspacioModal" tabindex="-1" aria-hidden="true" <?= $reintentoCrear ? 'data-reabrir' : ''; ?>>
    <div class="modal-dialog modal-dialog-scrollable">
        <form action="espacios.php" method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="crear">
            <input type="hidden" name="csrf_espacios" value="<?= htmlspecialchars($_SESSION['csrf_espacios']); ?>">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">Agregar Espacio</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <?php if ($reintentoCrear): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error_msg); ?></div><?php endif; ?>
                <div class="mb-3">
                    <label class="form-label fw-bold">Nombre del Espacio *</label>
                    <input type="text" name="nombre" class="form-control rounded-pill px-3" required placeholder="Espacio Techado / Pista 2" value="<?= htmlspecialchars($formCrear['nombre'] ?? ''); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Imagen URL</label>
                    <input type="url" name="imagenURL" class="form-control rounded-pill px-3" placeholder="https://ejemplo.com/espacio.jpg" value="<?= htmlspecialchars($formCrear['imagenURL'] ?? ''); ?>">
                </div>
                <div class="mb-3 form-check form-switch ms-1">
                    <input class="form-check-input" type="checkbox" name="techado" id="crearTechado" <?= !empty($formCrear['techado']) ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-bold" for="crearTechado">Espacio Techado</label>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Descripción / Ubicación</label>
                    <textarea name="descripcion" class="form-control px-3" rows="3" style="border-radius:15px;" placeholder="Detalles de superficie, iluminación, etc..."><?= htmlspecialchars($formCrear['descripcion'] ?? ''); ?></textarea>
                </div>
                <?php $cobroEspacio = $formCrear; $sufijoCobro = 'Crear'; require __DIR__ . '/../includes/pagos/campos_espacio.php'; ?>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                <button type="submit" class="poliba-btn py-2">Crear Espacio</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-configuracion-cobro]').forEach(grupo => {
        const precio = grupo.querySelector('[name="precio_hora_ars"]');
        const presencial = grupo.querySelector('[data-pago-presencial]');
        const actualizar = () => {
            const valor = precio.value.trim();
            presencial.disabled = !/^[0-9]{1,10}([.,][0-9]{1,2})?$/.test(valor) || /^0+([.,]0{1,2})?$/.test(valor);
            if (presencial.disabled) presencial.checked = false;
        };
        precio.addEventListener('input', actualizar);
        actualizar();
    });
    const reabrir = document.querySelector('[data-reabrir]');
    if (reabrir && window.bootstrap) new bootstrap.Modal(reabrir).show();
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
