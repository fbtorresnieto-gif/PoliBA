<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

// Validar que esté logueado
redirect_if_not_logged_in();

$user = get_logged_user();
$error_msg = '';
$success_msg = '';

// 1. Procesar Cambios de Perfil (Modificar Perfil)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'modificar_perfil') {
    $nombre = trim($_POST['nombre']);
    $apellido = trim($_POST['apellido']);
    $direccion = trim($_POST['direccion']);
    $telefono = trim($_POST['telefono']);
    
    if (empty($nombre) || empty($apellido)) {
        $error_msg = 'Nombre y Apellido son obligatorios.';
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE usuarios 
                SET nombre = ?, apellido = ?, direccion = ?, telefono = ? 
                WHERE id = ?
            ");
            if ($stmt->execute([$nombre, $apellido, $direccion, $telefono, $user['id']])) {
                $success_msg = 'Perfil modificado con éxito.';
                // Actualizar sesión
                $_SESSION['user_nombre'] = $nombre;
                $_SESSION['user_apellido'] = $apellido;
                unset($_SESSION['user_data']); // Forzar recarga en auth.php
                $user = get_logged_user();
            } else {
                $error_msg = 'Error al actualizar el perfil.';
            }
        } catch (PDOException $e) {
            $error_msg = 'Error al actualizar base de datos.';
        }
    }
}

// 2. Procesar Asociar Menor
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'asociar_menor') {
    $nombre = trim($_POST['nombre']);
    $apellido = trim($_POST['apellido']);
    $dni = trim($_POST['dni']);
    $direccion = trim($_POST['direccion']);
    $fecha_nacimiento = $_POST['fecha_nacimiento'];
    $relacion = trim($_POST['relacion']);
    
    if (empty($nombre) || empty($apellido) || empty($dni) || empty($fecha_nacimiento) || empty($relacion)) {
        $error_msg = 'Completa todos los campos obligatorios del menor.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO menores (nombre, apellido, dni, direccion, fecha_nacimiento, relacion, fk_usuario)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            if ($stmt->execute([$nombre, $apellido, $dni, $direccion, $fecha_nacimiento, $relacion, $user['id']])) {
                $success_msg = 'Menor asociado con éxito.';
            } else {
                $error_msg = 'Error al asociar el menor.';
            }
        } catch (PDOException $e) {
            $error_msg = 'El DNI del menor ya está registrado en el sistema.';
        }
    }
}

// 3. Procesar Modificar Menor
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'modificar_menor') {
    $menor_id = intval($_POST['menor_id']);
    $nombre = trim($_POST['nombre']);
    $apellido = trim($_POST['apellido']);
    $direccion = trim($_POST['direccion']);
    $relacion = trim($_POST['relacion']);
    
    if (empty($nombre) || empty($apellido) || empty($relacion)) {
        $error_msg = 'Completa todos los campos obligatorios del menor.';
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE menores 
                SET nombre = ?, apellido = ?, direccion = ?, relacion = ? 
                WHERE id = ? AND fk_usuario = ?
            ");
            if ($stmt->execute([$nombre, $apellido, $direccion, $relacion, $menor_id, $user['id']])) {
                $success_msg = 'Datos del menor modificados con éxito.';
            } else {
                $error_msg = 'Error al modificar el menor.';
            }
        } catch (PDOException $e) {
            $error_msg = 'Error de base de datos.';
        }
    }
}

// 4. Procesar Cancelar Reserva
if (isset($_GET['cancelar_reserva'])) {
    $res_id = intval($_GET['cancelar_reserva']);
    try {
        $stmt = $pdo->prepare("
            UPDATE reservas 
            SET estado = 'cancelado' 
            WHERE id = ? AND fk_usuario = ?
        ");
        if ($stmt->execute([$res_id, $user['id']])) {
            $success_msg = 'Reserva cancelada con éxito.';
        }
    } catch (PDOException $e) {
        $error_msg = 'Error al cancelar la reserva.';
    }
}

// 5. Procesar Cancelar/Reanudar Clase
if (isset($_GET['cambiar_estado_clase']) && isset($_GET['inscripcion_id'])) {
    $ins_id = intval($_GET['inscripcion_id']);
    $nuevo_estado = $_GET['cambiar_estado_clase'] == 'reanudar' ? 'activo' : 'cancelado';
    
    try {
        // Si cancela a mitad de año, advertir sobre la regla de negocio
        if ($nuevo_estado == 'cancelado') {
            $current_month = intval(date('m'));
            if ($current_month > 3 && $current_month < 12) {
                // Durante el año, requiere profesor para dar de baja
                // Para simplificar la demo, lo damos de baja pero mostramos mensaje explicativo
                $stmt = $pdo->prepare("
                    UPDATE inscripcion 
                    SET estado = 'cancelado' 
                    WHERE id = ? AND (fk_usuario = ? OR fk_menor IN (SELECT id FROM menores WHERE fk_usuario = ?))
                ");
                $stmt->execute([$ins_id, $user['id'], $user['id']]);
                $success_msg = 'Inscripción dada de baja. Nota: Por ser transcurso del año lectivo, avisa a tu profesor.';
            } else {
                // Nuevo año
                $stmt = $pdo->prepare("
                    UPDATE inscripcion 
                    SET estado = 'cancelado' 
                    WHERE id = ? AND (fk_usuario = ? OR fk_menor IN (SELECT id FROM menores WHERE fk_usuario = ?))
                ");
                $stmt->execute([$ins_id, $user['id'], $user['id']]);
                $success_msg = 'Inscripción cancelada con éxito.';
            }
        } else {
            // Reanudar
            $stmt = $pdo->prepare("
                UPDATE inscripcion 
                SET estado = 'activo' 
                WHERE id = ? AND (fk_usuario = ? OR fk_menor IN (SELECT id FROM menores WHERE fk_usuario = ?))
            ");
            $stmt->execute([$ins_id, $user['id'], $user['id']]);
            $success_msg = 'Inscripción reanudada con éxito.';
        }
    } catch (PDOException $e) {
        $error_msg = 'Error al gestionar la inscripción.';
    }
}

// Cargar Menores del Usuario
$menores = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM menores WHERE fk_usuario = ? ORDER BY nombre, apellido ASC");
    $stmt->execute([$user['id']]);
    $menores = $stmt->fetchAll();
} catch (PDOException $e) {}

// Cargar Reservas del Usuario (Si es Alumno)
$reservas = [];
if (has_role('Alumno')) {
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, c.nombre as cancha_nombre, p.nombre as polideportivo_nombre
            FROM reservas r
            JOIN canchas c ON r.fk_cancha = c.id
            JOIN polideportivos p ON c.fk_polideportivo = p.id
            WHERE r.fk_usuario = ?
            ORDER BY r.fecha_de_asistencia DESC, r.horario DESC
        ");
        $stmt->execute([$user['id']]);
        $reservas = $stmt->fetchAll();
    } catch (PDOException $e) {}
}

// Cargar Clases del Usuario (Alumno o Profesor)
$clases_inscriptas = [];
if (has_role('Alumno')) {
    try {
        // Clases propias
        $stmt = $pdo->prepare("
            SELECT i.id as inscripcion_id, i.lista_espera, i.estado as inscripcion_estado,
                   c.nombre as clase_nombre, c.horario_inicio, c.horario_cierre,
                   d.nombre as deporte_nombre, p.nombre as polideportivo_nombre
            FROM inscripcion i
            JOIN clases c ON i.fk_clase = c.id
            JOIN deportes d ON c.fk_deporte = d.id
            JOIN polideportivos p ON c.fk_polideportivo = p.id
            WHERE i.fk_usuario = ? AND i.fk_menor IS NULL
            ORDER BY c.nombre ASC
        ");
        $stmt->execute([$user['id']]);
        $clases_inscriptas = $stmt->fetchAll();
    } catch (PDOException $e) {}
} elseif (has_role('Profesor')) {
    try {
        $stmt = $pdo->prepare("
            SELECT c.*, d.nombre as deporte_nombre, p.nombre as polideportivo_nombre
            FROM clases c
            JOIN deportes d ON c.fk_deporte = d.id
            JOIN polideportivos p ON c.fk_polideportivo = p.id
            WHERE c.fk_usuario_profesor = ? AND c.estado = TRUE
            ORDER BY c.nombre ASC
        ");
        $stmt->execute([$user['id']]);
        $clases_inscriptas = $stmt->fetchAll();
    } catch (PDOException $e) {}
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container my-4">
    <h2 class="section-title text-dark">Mi Perfil</h2>
    
    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error_msg); ?>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($success_msg); ?>
        </div>
    <?php endif; ?>

    <!-- Sección 1: Mis Datos (Basada en Prototipo Mi Perfil.png) -->
    <div class="poliba-container-card mt-0 bg-light-subtle">
        <div class="row">
            <div class="col-md-7">
                <h4 class="fw-bold mb-4 text-dark border-bottom pb-2">Mis datos</h4>
                <div class="row mb-2">
                    <div class="col-sm-4 text-muted fw-bold">Nombre:</div>
                    <div class="col-sm-8 fw-bold text-dark"><?= htmlspecialchars($user['nombre']); ?></div>
                </div>
                <div class="row mb-2">
                    <div class="col-sm-4 text-muted fw-bold">Apellido:</div>
                    <div class="col-sm-8 fw-bold text-dark"><?= htmlspecialchars($user['apellido']); ?></div>
                </div>
                <div class="row mb-2">
                    <div class="col-sm-4 text-muted fw-bold">Dni:</div>
                    <div class="col-sm-8 text-dark"><?= htmlspecialchars($user['dni']); ?></div>
                </div>
                <div class="row mb-2">
                    <div class="col-sm-4 text-muted fw-bold">Dirección:</div>
                    <div class="col-sm-8 text-dark"><?= htmlspecialchars($user['direccion'] ?? 'No registrada'); ?></div>
                </div>
                <div class="row mb-2">
                    <div class="col-sm-4 text-muted fw-bold">Gmail:</div>
                    <div class="col-sm-8 text-dark"><?= htmlspecialchars($user['email']); ?></div>
                </div>
                <div class="row mb-3">
                    <div class="col-sm-4 text-muted fw-bold">Telefono:</div>
                    <div class="col-sm-8 text-dark"><?= htmlspecialchars($user['telefono'] ?? 'No registrado'); ?></div>
                </div>
                
                <button class="poliba-btn py-2" data-bs-toggle="modal" data-bs-target="#modificarPerfilModal">Modificar Perfil</button>
            </div>
            
            <!-- Botones de gestión de Menores (Solo Alumnos) -->
            <?php if (has_role('Alumno')): ?>
                <div class="col-md-5 mt-4 mt-md-0 d-flex flex-column justify-content-start gap-2">
                    <h5 class="fw-bold text-dark mb-3 border-bottom pb-2">Gestión de Menores</h5>
                    <button class="poliba-btn-dark rounded-pill py-2 w-100" data-bs-toggle="modal" data-bs-target="#asociarMenorModal">Asociar menor</button>
                    
                    <?php if (!empty($menores)): ?>
                        <button class="poliba-btn-dark rounded-pill py-2 w-100" data-bs-toggle="modal" data-bs-target="#verMenoresModal">Ver menores a cargo</button>
                    <?php else: ?>
                        <div class="alert alert-secondary text-center small m-0">No tienes menores a cargo registrados.</div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sección 2: Mis Reservas (Solo Alumnos, Basada en Prototipo Mi Perfil.png) -->
    <?php if (has_role('Alumno')): ?>
        <div class="my-5">
            <h3 class="fw-bold mb-4 text-center text-dark">Mis Reservas</h3>
            <?php if (empty($reservas)): ?>
                <div class="alert alert-info text-center">No has realizado ninguna reserva de cancha aún. <a href="canchas.php" class="alert-link text-decoration-none">Ver canchas</a></div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($reservas as $res): 
                        $status_badge = 'bg-success';
                        if (strtolower($res['estado']) == 'cancelado') $status_badge = 'bg-danger';
                    ?>
                        <div class="col-md-6 col-lg-3">
                            <div class="poliba-card border-top border-5 border-success">
                                <div class="poliba-card-body p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="badge <?= $status_badge; ?> text-uppercase"><?= htmlspecialchars($res['estado']); ?></span>
                                        <small class="text-muted">ID #<?= $res['id']; ?></small>
                                    </div>
                                    <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($res['cancha_nombre']); ?></h5>
                                    <div class="text-muted small mb-2"><i class="bi bi-building"></i> Sede: <?= htmlspecialchars($res['polideportivo_nombre']); ?></div>
                                    <div class="text-dark small"><i class="bi bi-calendar-event me-2"></i><?= date('d/m/Y', strtotime($res['fecha_de_asistencia'])); ?></div>
                                    <div class="text-dark small mb-3"><i class="bi bi-clock me-2"></i><?= date('H:i', strtotime($res['horario'])); ?> hs</div>
                                    
                                    <div class="d-flex justify-content-between gap-1 mt-auto">
                                        <?php if (strtolower($res['estado']) == 'reservado'): ?>
                                            <a href="ver_reserva.php?id=<?= $res['id']; ?>" class="btn btn-outline-dark btn-sm flex-grow-1">Compartir</a>
                                            <a href="perfil.php?cancelar_reserva=<?= $res['id']; ?>" class="btn btn-danger btn-sm px-2" onclick="return confirm('¿Seguro quieres cancelar esta reserva?');">Cancelar</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Sección 3: Mis Clases (Alumnos/Profesores, Basada en Prototipo Mi Perfil.png) -->
    <div class="my-5">
        <h3 class="fw-bold mb-4 text-center text-dark">
            <?= has_role('Profesor') ? 'Clases que Dicto' : 'Mis Clases'; ?>
        </h3>
        
        <?php if (empty($clases_inscriptas)): ?>
            <div class="alert alert-info text-center">
                <?= has_role('Profesor') ? 'No tienes clases asignadas actualmente.' : 'No estás inscripto en ninguna clase de deporte. <a href="deportes.php" class="alert-link text-decoration-none">Buscar clases</a>'; ?>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php if (has_role('Alumno')): ?>
                    <?php foreach ($clases_inscriptas as $clase): 
                        $status_badge = $clase['lista_espera'] ? 'badge-espera' : 'badge-activo';
                        if ($clase['inscripcion_estado'] == 'cancelado') $status_badge = 'badge-cancelado';
                    ?>
                        <div class="col-md-6 col-lg-3">
                            <div class="poliba-card border-top border-5 border-info">
                                <div class="poliba-card-body p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="badge rounded-pill <?= $status_badge; ?>">
                                            <?= $clase['inscripcion_estado'] == 'cancelado' ? 'CANCELADA' : ($clase['lista_espera'] ? 'LISTA DE ESPERA' : 'ACTIVA'); ?>
                                        </span>
                                        <small class="text-muted">#<?= $clase['inscripcion_id']; ?></small>
                                    </div>
                                    <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($clase['clase_nombre']); ?></h5>
                                    <div class="text-muted small mb-2"><i class="bi bi-trophy"></i> <?= htmlspecialchars($clase['deporte_nombre']); ?></div>
                                    <div class="text-muted small mb-3"><i class="bi bi-building"></i> Sede: <?= htmlspecialchars($clase['polideportivo_nombre']); ?></div>
                                    
                                    <div class="d-grid mt-auto gap-2">
                                        <?php if ($clase['inscripcion_estado'] == 'activo'): ?>
                                            <a href="perfil.php?cambiar_estado_clase=cancelar&inscripcion_id=<?= $clase['inscripcion_id']; ?>" 
                                               class="btn btn-outline-danger btn-sm" onclick="return confirm('¿Seguro quieres darte de baja de esta clase?');">
                                                Darse de baja
                                            </a>
                                        <?php else: ?>
                                            <a href="perfil.php?cambiar_estado_clase=reanudar&inscripcion_id=<?= $clase['inscripcion_id']; ?>" 
                                               class="btn btn-outline-success btn-sm">
                                                Reanudar clase
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php elseif (has_role('Profesor')): ?>
                    <!-- Profesor Clases List -->
                    <?php foreach ($clases_inscriptas as $clase): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="poliba-card">
                                <div class="poliba-card-body p-3">
                                    <h5 class="fw-bold text-dark mb-2"><?= htmlspecialchars($clase['nombre']); ?></h5>
                                    <p class="text-muted small mb-3"><?= htmlspecialchars($clase['descripcion']); ?></p>
                                    <div class="text-dark small mb-1"><i class="bi bi-clock me-2"></i><?= date('H:i', strtotime($clase['horario_inicio'])); ?> - <?= date('H:i', strtotime($clase['horario_cierre'])); ?></div>
                                    <div class="text-dark small mb-3"><i class="bi bi-building me-2"></i>Sede: <?= htmlspecialchars($clase['polideportivo_nombre']); ?></div>
                                    
                                    <div class="d-grid gap-2 mt-auto">
                                        <a href="abm/profesor_clases.php?clase_id=<?= $clase['id']; ?>" class="poliba-btn text-center text-decoration-none">Tomar Asistencia / Alumnos</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ================= MODALS DE PERFIL ================= -->

<!-- 1. Modal Modificar Perfil -->
<div class="modal fade" id="modificarPerfilModal" tabindex="-1" aria-labelledby="modificarPerfilModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form action="perfil.php" method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="modificar_perfil">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold text-dark" id="modificarPerfilModalLabel">Modificar Datos de Perfil</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">Nombre *</label>
                    <input type="text" name="nombre" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($user['nombre']); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Apellido *</label>
                    <input type="text" name="apellido" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($user['apellido']); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Dirección</label>
                    <input type="text" name="direccion" class="form-control rounded-pill px-3" value="<?= htmlspecialchars($user['direccion'] ?? ''); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Teléfono</label>
                    <input type="text" name="telefono" class="form-control rounded-pill px-3" value="<?= htmlspecialchars($user['telefono'] ?? ''); ?>">
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                <button type="submit" class="poliba-btn py-2">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. Modal Asociar Menor -->
<div class="modal fade" id="asociarMenorModal" tabindex="-1" aria-labelledby="asociarMenorModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form action="perfil.php" method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="asociar_menor">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold text-dark" id="asociarMenorModalLabel">Asociar Menor de Edad</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Nombre *</label>
                        <input type="text" name="nombre" class="form-control rounded-pill px-3" required>
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Apellido *</label>
                        <input type="text" name="apellido" class="form-control rounded-pill px-3" required value="<?= htmlspecialchars($user['apellido']); ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">DNI *</label>
                        <input type="text" name="dni" class="form-control rounded-pill px-3" required>
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Fecha de Nacimiento *</label>
                        <input type="date" name="fecha_nacimiento" class="form-control rounded-pill px-3" required max="<?= date('Y-m-d', strtotime('-6 years')); ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Dirección</label>
                    <input type="text" name="direccion" class="form-control rounded-pill px-3" value="<?= htmlspecialchars($user['direccion'] ?? ''); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Relación con el menor *</label>
                    <select name="relacion" class="form-select rounded-pill px-3" required>
                        <option value="Hijo/a">Padre/Madre (Hijo/a)</option>
                        <option value="Nieto/a">Abuelo/a (Nieto/a)</option>
                        <option value="Tutorado/a">Tutor Legal (Tutorado/a)</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                <button type="submit" class="poliba-btn py-2">Asociar Menor</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. Modal Ver y Editar Menores a Cargo -->
<div class="modal fade" id="verMenoresModal" tabindex="-1" aria-labelledby="verMenoresModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold text-dark" id="verMenoresModalLabel">Menores a Cargo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="table-responsive">
                    <table class="table table-poliba table-striped">
                        <thead>
                            <tr>
                                <th>Nombre Completo</th>
                                <th>DNI</th>
                                <th>Edad</th>
                                <th>Relación</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($menores as $menor): 
                                $age = date_diff(date_create($menor['fecha_nacimiento']), date_create('today'))->y;
                            ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($menor['nombre'] . ' ' . $menor['apellido']); ?></td>
                                    <td><?= htmlspecialchars($menor['dni']); ?></td>
                                    <td><?= $age; ?> años</td>
                                    <td><?= htmlspecialchars($menor['relacion']); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-dark rounded-pill px-3" data-bs-toggle="collapse" data-bs-target="#editMenorCol<?= $menor['id']; ?>">Editar</button>
                                    </td>
                                </tr>
                                <tr class="collapse" id="editMenorCol<?= $menor['id']; ?>">
                                    <td colspan="5" class="bg-light p-3 border rounded">
                                        <form action="perfil.php" method="POST">
                                            <input type="hidden" name="action" value="modificar_menor">
                                            <input type="hidden" name="menor_id" value="<?= $menor['id']; ?>">
                                            <h6 class="fw-bold mb-2">Editar Datos de: <?= htmlspecialchars($menor['nombre']); ?></h6>
                                            <div class="row g-2">
                                                <div class="col-md-3">
                                                    <input type="text" name="nombre" class="form-control form-control-sm rounded-pill px-3" placeholder="Nombre" required value="<?= htmlspecialchars($menor['nombre']); ?>">
                                                </div>
                                                <div class="col-md-3">
                                                    <input type="text" name="apellido" class="form-control form-control-sm rounded-pill px-3" placeholder="Apellido" required value="<?= htmlspecialchars($menor['apellido']); ?>">
                                                </div>
                                                <div class="col-md-3">
                                                    <input type="text" name="direccion" class="form-control form-control-sm rounded-pill px-3" placeholder="Dirección" value="<?= htmlspecialchars($menor['direccion'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-3">
                                                    <select name="relacion" class="form-select form-select-sm rounded-pill px-3" required>
                                                        <option value="Hijo/a" <?= $menor['relacion'] == 'Hijo/a' ? 'selected' : ''; ?>>Hijo/a</option>
                                                        <option value="Nieto/a" <?= $menor['relacion'] == 'Nieto/a' ? 'selected' : ''; ?>>Nieto/a</option>
                                                        <option value="Tutorado/a" <?= $menor['relacion'] == 'Tutorado/a' ? 'selected' : ''; ?>>Tutorado/a</option>
                                                    </select>
                                                </div>
                                                <div class="col-12 text-end mt-2">
                                                    <button type="submit" class="poliba-btn btn-sm py-1">Guardar</button>
                                                </div>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
