<?php
$base_path = '../';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Validar que sea Gestor
redirect_if_not_logged_in(['Gestor']);

$error_msg = '';
$success_msg = '';

// Procesar Alta / Modificación
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $nombre = trim($_POST['nombre']);
    $direccion = trim($_POST['direccion']);
    $horario_apertura = $_POST['horario_apertura'];
    $horario_cierre = $_POST['horario_cierre'];
    $coordenadas = trim($_POST['coordenadas']);
    $informacion = trim($_POST['informacion']);
    $imagenURL = trim($_POST['imagenURL'] ?? '');
    $fk_dia_apertura = !empty($_POST['fk_dia_apertura']) ? intval($_POST['fk_dia_apertura']) : null;
    $fk_dia_cierre = !empty($_POST['fk_dia_cierre']) ? intval($_POST['fk_dia_cierre']) : null;
    $cuit = trim($_POST['cuit'] ?? '');
    
    if (empty($nombre) || empty($direccion) || empty($horario_apertura) || empty($horario_cierre)) {
        $error_msg = 'Por favor, completa los campos obligatorios (*).';
    } elseif (mb_strlen($nombre) < 3) {
        $error_msg = 'El nombre de la entidad debe tener al menos 3 caracteres.';
    } elseif (mb_strlen($direccion) < 5) {
        $error_msg = 'La dirección debe tener al menos 5 caracteres.';
    } elseif (!preg_match('/^\d{11}$/', $cuit)) {
        $error_msg = 'El CUIT debe contener exactamente 11 números (sin guiones ni espacios).';
    } elseif ($horario_apertura >= $horario_cierre) {
        $error_msg = "El horario de apertura ($horario_apertura hs) debe ser anterior al horario de cierre ($horario_cierre hs).";
    } else {
        // Validar coordenadas si se ingresaron
        $coord_valida = true;
        if (!empty($coordenadas)) {
            if (!preg_match('/^-?\d+(\.\d+)?,\s*-?\d+(\.\d+)?$/', $coordenadas)) {
                $error_msg = 'El formato de coordenadas no es válido. Debe ser latitud,longitud (ejemplo: -34.6037,-58.3816).';
                $coord_valida = false;
            }
        }

        // Validar URL / Imagen si se ingresó
        if ($coord_valida && !empty($imagenURL)) {
            $is_valid_url = filter_var($imagenURL, FILTER_VALIDATE_URL) || preg_match('/^https?:\/\//i', $imagenURL);
            $is_valid_filename = preg_match('/^[a-zA-Z0-9_\-.]+\.(jpg|jpeg|png|webp|svg|gif)$/i', $imagenURL);
            if (!$is_valid_url && !$is_valid_filename) {
                $error_msg = 'La URL de la imagen debe comenzar con http:// o https:// (o ser un nombre de archivo de imagen válido como imagen.jpg).';
                $coord_valida = false;
            }
        }

        if ($coord_valida) {
            $chk_id = ($_POST['action'] == 'editar') ? intval($_POST['id']) : 0;
            // Validar unicidad del nombre
            try {
                $stmt_cuit = $pdo->prepare("SELECT COUNT(*) FROM entidades WHERE cuit = ? AND id != ?");
                $stmt_cuit->execute([$cuit, $chk_id]);
                $cuit_dup = (int)$stmt_cuit->fetchColumn();
                $stmt_chk = $pdo->prepare("SELECT COUNT(*) FROM entidades WHERE LOWER(nombre) = LOWER(?) AND id != ?");
                $stmt_chk->execute([$nombre, $chk_id]);
                if ($cuit_dup > 0) {
                    $error_msg = "Ya existe una entidad registrada con el CUIT $cuit.";
                } elseif ($stmt_chk->fetchColumn() > 0) {
                    $error_msg = "Ya existe una entidad registrada con el nombre \"$nombre\".";
                } else {
                    if ($_POST['action'] == 'crear') {
                        $stmt = $pdo->prepare("
                            INSERT INTO entidades (cuit, nombre, direccion, horario_apertura, horario_cierre, coordenadas, informacion, imagenURL, fk_dia_apertura, fk_dia_cierre, estado)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)
                        ");
                        $stmt->execute([$cuit, $nombre, $direccion, $horario_apertura, $horario_cierre, $coordenadas, $informacion, $imagenURL, $fk_dia_apertura, $fk_dia_cierre]);
                        $success_msg = 'Entidad creada con éxito.';
                    } elseif ($_POST['action'] == 'editar') {
                        $id = intval($_POST['id']);
                        $stmt = $pdo->prepare("
                            UPDATE entidades
                            SET cuit = ?, nombre = ?, direccion = ?, horario_apertura = ?, horario_cierre = ?, coordenadas = ?, informacion = ?, imagenURL = ?, fk_dia_apertura = ?, fk_dia_cierre = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$cuit, $nombre, $direccion, $horario_apertura, $horario_cierre, $coordenadas, $informacion, $imagenURL, $fk_dia_apertura, $fk_dia_cierre, $id]);
                        $success_msg = 'Entidad modificada con éxito.';
                    }
                }
            } catch (PDOException $e) {
                $error_msg = 'Error al procesar la entidad en la base de datos: ' . $e->getMessage();
            }
        }
    }
}

// Procesar Baja Lógica con Desactivación en Cascada
if (isset($_GET['toggle_estado'])) {
    $id = intval($_GET['toggle_estado']);
    $nuevo_estado = $_GET['estado'] == '1' ? 'FALSE' : 'TRUE';
    try {
        if ($nuevo_estado == 'FALSE') {
            // Desactivación en cascada: entidad, actividades, espacios, modulos y subcategorías
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE entidades SET estado = FALSE WHERE id = ?");
            $stmt->execute([$id]);

            $stmt = $pdo->prepare("UPDATE actividades SET estado = FALSE WHERE fk_entidad = ?");
            $stmt->execute([$id]);

            $stmt = $pdo->prepare("UPDATE espacios SET estado = FALSE WHERE fk_entidad = ?");
            $stmt->execute([$id]);

            $stmt = $pdo->prepare("UPDATE modulos SET estado = FALSE WHERE fk_entidad = ?");
            $stmt->execute([$id]);

            $stmt = $pdo->prepare("UPDATE subcategorias SET estado = FALSE WHERE fk_entidad = ?");
            $stmt->execute([$id]);

            $pdo->commit();
            $success_msg = 'Entidad desactivada. Sus actividades, espacios y módulos asociados fueron desactivados automáticamente en cascada.';
        } else {
            // Activación de la entidad
            $stmt = $pdo->prepare("UPDATE entidades SET estado = TRUE WHERE id = ?");
            $stmt->execute([$id]);
            $success_msg = 'Entidad activada con éxito. Ya puedes gestionar sus actividades y espacios.';
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_msg = 'Error al cambiar el estado de la entidad.';
    }
}

// Cargar Entidades
$entidades = [];
try {
    $stmt = $pdo->query("
        SELECT e.*, d1.nombre as dia_apertura_nombre, d2.nombre as dia_cierre_nombre
        FROM entidades e
        LEFT JOIN dias d1 ON e.fk_dia_apertura = d1.id
        LEFT JOIN dias d2 ON e.fk_dia_cierre = d2.id
        ORDER BY e.id ASC
    ");
    $entidades = $stmt->fetchAll();
} catch (PDOException $e) {}

// Cargar Dias
$dias = [];
try {
    $stmt = $pdo->query("SELECT * FROM dias ORDER BY orden ASC");
    $dias = $stmt->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-dark m-0">ABM Entidades</h2>
        <button class="poliba-btn" data-bs-toggle="modal" data-bs-target="#crearEntidadModal">Agregar Entidad</button>
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
                    <th>CUIT</th>
                    <th>Nombre</th>
                    <th>Dirección</th>
                    <th>Horario</th>
                    <th>Días</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entidades as $entidad): ?>
                    <tr>
                        <td><?= htmlspecialchars($entidad['cuit'] ?? ''); ?></td>
                        <td class="fw-bold"><?= htmlspecialchars($entidad['nombre']); ?></td>
                        <td><?= htmlspecialchars($entidad['direccion']); ?></td>
                        <td><?= date('H:i', strtotime($entidad['horario_apertura'])); ?> - <?= date('H:i', strtotime($entidad['horario_cierre'])); ?></td>
                        <td><?= htmlspecialchars($entidad['dia_apertura_nombre'] ?? 'Lunes'); ?> a <?= htmlspecialchars($entidad['dia_cierre_nombre'] ?? 'Sábado'); ?></td>
                        <td>
                            <span class="badge rounded-pill <?= $entidad['estado'] ? 'bg-success' : 'bg-danger'; ?>">
                                <?= $entidad['estado'] ? 'Activo' : 'Inactivo'; ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-dark rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#editEntidadModal<?= $entidad['id']; ?>">Editar</button>
                            <a href="entidades.php?toggle_estado=<?= $entidad['id']; ?>&estado=<?= $entidad['estado'] ? '1' : '0'; ?>"
                               class="btn btn-sm <?= $entidad['estado'] ? 'btn-danger' : 'btn-success'; ?> rounded-pill px-3"
                               onclick="return confirm('¿Seguro deseas cambiar el estado de esta entidad? Si la desactivas, todas sus actividades, espacios y módulos se desactivarán automáticamente.');">
                                <?= $entidad['estado'] ? 'Desactivar' : 'Activar'; ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modales de Edición (Colocados fuera de la tabla para HTML válido en Bootstrap 5) -->
<?php foreach ($entidades as $entidad): ?>
    <div class="modal fade" id="editEntidadModal<?= $entidad['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form action="entidades.php" method="POST" class="modal-content border-0 shadow">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="id" value="<?= $entidad['id']; ?>">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold">Editar Entidad: <?= htmlspecialchars($entidad['nombre']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">CUIT *</label>
                        <input type="text" name="cuit" class="form-control rounded-pill px-3" required inputmode="numeric" maxlength="11" minlength="11" pattern="[0-9]{11}" title="Debe contener exactamente 11 números" oninput="this.value=this.value.replace(/\D/g,'')" value="<?= htmlspecialchars($entidad['cuit'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre *</label>
                        <input type="text" name="nombre" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($entidad['nombre']); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Dirección *</label>
                        <input type="text" name="direccion" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($entidad['direccion']); ?>">
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Hora Apertura *</label>
                            <input type="time" name="horario_apertura" class="form-control rounded-pill px-3" required value="<?= $entidad['horario_apertura']; ?>">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Hora Cierre *</label>
                            <input type="time" name="horario_cierre" class="form-control rounded-pill px-3" required value="<?= $entidad['horario_cierre']; ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Día Apertura *</label>
                            <select name="fk_dia_apertura" class="form-select rounded-pill px-3">
                                <?php foreach ($dias as $dia): ?>
                                    <option value="<?= $dia['id']; ?>" <?= $entidad['fk_dia_apertura'] == $dia['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($dia['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Día Cierre *</label>
                            <select name="fk_dia_cierre" class="form-select rounded-pill px-3">
                                <?php foreach ($dias as $dia): ?>
                                    <option value="<?= $dia['id']; ?>" <?= $entidad['fk_dia_cierre'] == $dia['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($dia['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Coordenadas (Lat,Lng)</label>
                        <input type="text" name="coordenadas" class="form-control rounded-pill px-3" pattern="^-?[0-9]+(\.[0-9]+)?,\s*-?[0-9]+(\.[0-9]+)?$" title="Formato requerido: latitud,longitud (ej: -34.574,-58.448)" placeholder="-34.574,-58.448" value="<?= htmlspecialchars($entidad['coordenadas'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">URL de la Imagen</label>
                        <input type="text" name="imagenURL" class="form-control rounded-pill px-3" pattern="^(https?://.+|[a-zA-Z0-9_\-.]+\.(jpg|jpeg|png|webp|svg|gif))$" title="Debe ser una URL válida iniciando con http:// o https://, o un nombre de archivo de imagen (.jpg, .png, etc.)" placeholder="https://ejemplo.com/imagen.jpg o nombre_imagen.jpg" value="<?= htmlspecialchars($entidad['imagenurl'] ?? $entidad['imagenURL'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Información General</label>
                        <textarea name="informacion" class="form-control px-3" rows="3" style="border-radius:15px;"><?= htmlspecialchars($entidad['informacion'] ?? ''); ?></textarea>
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

<!-- Modal Crear Entidad -->
<div class="modal fade" id="crearEntidadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="entidades.php" method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="crear">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">Agregar Entidad</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">CUIT *</label>
                    <input type="text" name="cuit" class="form-control rounded-pill px-3" required inputmode="numeric" maxlength="11" minlength="11" pattern="[0-9]{11}" title="Debe contener exactamente 11 números" placeholder="20123456789" oninput="this.value=this.value.replace(/\D/g,'')">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Nombre *</label>
                    <input type="text" name="nombre" class="form-control rounded-pill px-3" required placeholder="Entidad Chacarita">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Dirección *</label>
                    <input type="text" name="direccion" class="form-control rounded-pill px-3" required placeholder="Av. Alvarez Thomas 123">
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Hora Apertura *</label>
                        <input type="time" name="horario_apertura" class="form-control rounded-pill px-3" required value="08:00">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Hora Cierre *</label>
                        <input type="time" name="horario_cierre" class="form-control rounded-pill px-3" required value="22:00">
                    </div>
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Día Apertura *</label>
                        <select name="fk_dia_apertura" class="form-select rounded-pill px-3">
                            <?php foreach ($dias as $dia): ?>
                                <option value="<?= $dia['id']; ?>"><?= htmlspecialchars($dia['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Día Cierre *</label>
                        <select name="fk_dia_cierre" class="form-select rounded-pill px-3">
                            <?php foreach ($dias as $dia): ?>
                                <option value="<?= $dia['id']; ?>" <?= $dia['orden'] == 6 ? 'selected' : ''; ?>><?= htmlspecialchars($dia['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Coordenadas (Lat,Lng)</label>
                    <input type="text" name="coordenadas" class="form-control rounded-pill px-3" pattern="^-?[0-9]+(\.[0-9]+)?,\s*-?[0-9]+(\.[0-9]+)?$" title="Formato requerido: latitud,longitud (ej: -34.574,-58.448)" placeholder="-34.574,-58.448">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">URL de la Imagen</label>
                    <input type="text" name="imagenURL" class="form-control rounded-pill px-3" pattern="^(https?://.+|[a-zA-Z0-9_\-.]+\.(jpg|jpeg|png|webp|svg|gif))$" title="Debe ser una URL válida iniciando con http:// o https://, o un nombre de archivo de imagen (.jpg, .png, etc.)" placeholder="https://ejemplo.com/imagen.jpg o nombre_imagen.jpg">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Información General</label>
                    <textarea name="informacion" class="form-control px-3" rows="3" style="border-radius:15px;" placeholder="Descripción corta de la entidad..."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                <button type="submit" class="poliba-btn py-2">Crear Entidad</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
