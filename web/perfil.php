<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/pagos/ServicioReservas.php';
require_once __DIR__ . '/includes/pagos/ConfiguracionEspacio.php';

// Validar que esté logueado
redirect_if_not_logged_in();

$user = get_logged_user();
$error_msg = '';
$success_msg = '';
$_SESSION['csrf_reservas'] ??= bin2hex(random_bytes(32));

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

// Valida que la fecha de nacimiento sea válida, no futura y corresponda a menos de 18 años
function es_menor_de_edad($fecha) {
    $nac = DateTime::createFromFormat('Y-m-d', $fecha);
    if (!$nac || $nac->format('Y-m-d') !== $fecha) return false;
    $hoy = new DateTime('today');
    if ($nac > $hoy) return false;
    return $nac->diff($hoy)->y < 18;
}

// Indica si un DNI ya existe en usuarios o en menores (de cualquier adulto).
// $excluir_menor_id permite ignorar al propio menor al modificarlo.
function dni_en_uso($pdo, $dni, $excluir_menor_id = 0) {
    $stmt = $pdo->prepare("SELECT 1 FROM usuarios WHERE dni = ? UNION ALL SELECT 1 FROM menores WHERE dni = ? AND id <> ? LIMIT 1");
    $stmt->execute([$dni, $dni, $excluir_menor_id]);
    return (bool) $stmt->fetchColumn();
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
    } elseif (!es_menor_de_edad($fecha_nacimiento)) {
        $error_msg = 'La persona debe ser menor de 18 años para estar asociada como menor.';
    } elseif (dni_en_uso($pdo, $dni)) {
        $error_msg = 'El DNI ingresado ya está registrado para otro usuario o menor.';
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
    $dni = trim($_POST['dni']);
    $fecha_nacimiento = $_POST['fecha_nacimiento'];
    $direccion = trim($_POST['direccion']);
    $relacion = trim($_POST['relacion']);
    
    if (empty($nombre) || empty($apellido) || empty($dni) || empty($fecha_nacimiento) || empty($relacion)) {
        $error_msg = 'Completa todos los campos obligatorios del menor (Nombre, Apellido, DNI, Fecha de Nacimiento y Relación).';
    } elseif (!es_menor_de_edad($fecha_nacimiento)) {
        $error_msg = 'La persona debe ser menor de 18 años para estar asociada como menor.';
    } elseif (dni_en_uso($pdo, $dni, $menor_id)) {
        $error_msg = 'El DNI ingresado ya está registrado para otro usuario o menor.';
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE menores 
                SET nombre = ?, apellido = ?, dni = ?, fecha_nacimiento = ?, direccion = ?, relacion = ? 
                WHERE id = ? AND fk_usuario = ?
            ");
            if ($stmt->execute([$nombre, $apellido, $dni, $fecha_nacimiento, $direccion, $relacion, $menor_id, $user['id']])) {
                $success_msg = 'Datos del menor modificados con éxito.';
            } else {
                $error_msg = 'Error al modificar el menor.';
            }
        } catch (PDOException $e) {
            $error_msg = 'El DNI ingresado ya se encuentra registrado para otro usuario o menor.';
        }
    }
}

// 4. Procesar Cancelar Reserva
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'cancelar_reserva') {
    try {
        $token=$_POST['csrf_reservas']??'';
        if (!is_string($token)||!hash_equals($_SESSION['csrf_reservas'],$token)) throw new RuntimeException('Recargá la página antes de cancelar.');
        $res_id=filter_var($_POST['reserva_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$res_id) throw new RuntimeException('Seleccioná una reserva válida.');
        (new \MultiPro\Pagos\ServicioReservas($pdo))->cancelarTitular($res_id,(int)$user['id']);
        notificacionesDespacharSilencioso($pdo);
        $success_msg = 'Reserva cancelada. Si corresponde una devolución presencial, queda pendiente de realizar por la entidad.';
    } catch (PDOException $e) {
        $error_msg = 'Error al cancelar la reserva.';
    } catch (RuntimeException $e) {
        $error_msg = $e->getMessage();
    }
}

// 5. La baja/reanudación de módulos se gestionará desde el perfil de Profesor (pendiente).

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
            SELECT r.*, esp.nombre as espacio_nombre, e.nombre as entidad_nombre,
                EXISTS(SELECT 1 FROM reserva_movimientos_presenciales m WHERE m.fk_reserva=r.id AND m.tipo='cobro') AS presencial_cobrado,
                EXISTS(SELECT 1 FROM reserva_movimientos_presenciales m WHERE m.fk_reserva=r.id AND m.tipo='devolucion_solicitada') AS devolucion_pendiente,
                EXISTS(SELECT 1 FROM reserva_movimientos_presenciales m WHERE m.fk_reserva=r.id AND m.tipo='devolucion') AS devolucion_realizada
            FROM reservas r
            JOIN espacios esp ON r.fk_espacio = esp.id
            JOIN entidades e ON esp.fk_entidad = e.id
            WHERE r.fk_usuario = ? AND r.estado <> 'vencido'
            ORDER BY r.fecha_de_asistencia DESC, r.horario DESC
        ");
        $stmt->execute([$user['id']]);
        $reservas = $stmt->fetchAll();
    } catch (PDOException $e) {}
}

// Cargar Modulos de los Menores a cargo (agrupados por menor)
$modulos_menores = [];
if (has_role('Alumno') && !empty($menores)) {
    try {
        $stmt = $pdo->prepare("
            SELECT i.id as inscripcion_id, i.fk_menor, i.lista_espera, i.estado as inscripcion_estado,
                   c.nombre as modulo_nombre, c.horario_inicio, c.horario_cierre,
                   d.nombre as actividad_nombre, e.nombre as entidad_nombre
            FROM inscripcion i
            JOIN menores m ON i.fk_menor = m.id
            JOIN modulos c ON i.fk_modulo = c.id
            JOIN actividades d ON c.fk_actividad = d.id
            JOIN entidades e ON c.fk_entidad = e.id
            WHERE m.fk_usuario = ?
            ORDER BY c.nombre ASC
        ");
        $stmt->execute([$user['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $modulos_menores[$row['fk_menor']][] = $row;
        }
    } catch (PDOException $e) {}
}

// Cargar Modulos del Usuario (Alumno o Profesor)
$modulos_inscriptas = [];
if (has_role('Alumno')) {
    try {
        // Modulos propias
        $stmt = $pdo->prepare("
            SELECT i.id as inscripcion_id, i.lista_espera, i.estado as inscripcion_estado,
                   c.nombre as modulo_nombre, c.horario_inicio, c.horario_cierre,
                   d.nombre as actividad_nombre, e.nombre as entidad_nombre
            FROM inscripcion i
            JOIN modulos c ON i.fk_modulo = c.id
            JOIN actividades d ON c.fk_actividad = d.id
            JOIN entidades e ON c.fk_entidad = e.id
            WHERE i.fk_usuario = ? AND i.fk_menor IS NULL
            ORDER BY c.nombre ASC
        ");
        $stmt->execute([$user['id']]);
        $modulos_inscriptas = $stmt->fetchAll();
    } catch (PDOException $e) {}
} elseif (has_role('Profesor')) {
    try {
        $stmt = $pdo->prepare("
            SELECT c.*, d.nombre as actividad_nombre, e.nombre as entidad_nombre
            FROM modulos c
            JOIN actividades d ON c.fk_actividad = d.id
            JOIN entidades e ON c.fk_entidad = e.id
            WHERE c.fk_usuario_profesor = ? AND c.estado = TRUE
            ORDER BY c.nombre ASC
        ");
        $stmt->execute([$user['id']]);
        $modulos_inscriptas = $stmt->fetchAll();
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
        <div class="my-5" id="mis-reservas">
            <h3 class="fw-bold mb-4 text-center text-dark">Mis Reservas de Espacios</h3>
            <?php if (empty($reservas)): ?>
                <div class="alert alert-info text-center">No has realizado ninguna reserva de espacio aún. <a href="espacios.php" class="alert-link text-decoration-none">Ver espacios</a></div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($reservas as $res): 
                        $status_badge = 'bg-success';
                        if (strtolower($res['estado']) == 'cancelado') $status_badge = 'bg-danger';
                        if (str_starts_with($res['estado'], 'pendiente')) $status_badge = 'bg-warning text-dark';
                    ?>
                        <div class="col-md-6 col-lg-3">
                            <div class="poliba-card border-top border-5 border-success">
                                <div class="poliba-card-body p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="badge <?= $status_badge; ?>"><?= htmlspecialchars(\MultiPro\Pagos\ConfiguracionEspacio::estadoReserva($res['estado'])); ?></span>
                                    </div>
                                    <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($res['espacio_nombre']); ?></h5>
                                    <div class="text-muted small mb-2"><i class="bi bi-building"></i> Entidad: <?= htmlspecialchars($res['entidad_nombre']); ?></div>
                                    <div class="text-dark small"><i class="bi bi-calendar-event me-2"></i><?= date('d/m/Y', strtotime($res['fecha_de_asistencia'])); ?></div>
                                    <div class="text-dark small mb-3"><i class="bi bi-clock me-2"></i><?= date('H:i', strtotime($res['horario'])); ?> hs</div>
                                    <p class="small fw-bold">Importe final por 1 hora: <?= htmlspecialchars(\MultiPro\Pagos\ConfiguracionEspacio::etiqueta($res['importe_total_ars'])); ?></p>
                                    <?php if ($res['modalidad_pago']==='presencial'): ?>
                                        <p class="small"><?= $res['presencial_cobrado'] ? 'Cobro presencial registrado' : ($res['estado']==='cancelado' ? 'Sin cobro registrado' : 'Pago presencial pendiente'); ?></p>
                                        <?php if ($res['estado']==='reservado' && $res['presencial_cobrado']): ?>
                                            <p class="small text-muted">Si cancelás con 24 horas o más, corresponde devolución total. Con menos de 24 horas no se genera devolución.</p>
                                        <?php endif; ?>
                                        <?php if ($res['devolucion_realizada']): ?><p class="small">Devolución total registrada por la entidad.</p>
                                        <?php elseif ($res['devolucion_pendiente']): ?><p class="small text-warning-emphasis">Devolución total pendiente de realizar por la entidad.</p><?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <div class="d-flex justify-content-between gap-1 mt-auto">
                                        <?php if (strtolower($res['estado']) == 'reservado'): ?>
                                            <button type="button" class="btn btn-outline-dark btn-sm flex-grow-1"
                                                    data-share-url="ver_reserva.php?t=<?= urlencode($res['token_compartir']); ?>"
                                                    onclick="compartirReserva(this)">Compartir</button>
                                        <?php endif; ?>
                                        <?php if (in_array($res['estado'],['reservado','pendiente_presencial'],true) && $res['modalidad_pago']!=='online'): ?>
                                            <form action="perfil.php#mis-reservas" method="POST" onsubmit="return confirm('¿Querés cancelar esta reserva?');">
                                                <input type="hidden" name="action" value="cancelar_reserva">
                                                <input type="hidden" name="reserva_id" value="<?= $res['id']; ?>">
                                                <input type="hidden" name="csrf_reservas" value="<?= htmlspecialchars($_SESSION['csrf_reservas']); ?>">
                                                <button class="btn btn-danger btn-sm" type="submit">Cancelar</button>
                                            </form>
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

    <!-- Sección 3: Mis Módulos (Alumnos/Profesores, Basada en Prototipo Mi Perfil.png) -->
    <div class="my-5" id="mis-modulos">
        <h3 class="fw-bold mb-4 text-center text-dark">
            <?= has_role('Profesor') ? 'Módulos que Dicto' : 'Mis Módulos'; ?>
        </h3>
        
        <?php if (empty($modulos_inscriptas)): ?>
            <div class="alert alert-info text-center">
                <?= has_role('Profesor') ? 'No tienes módulos asignados actualmente.' : 'No estás inscripto en ningún módulo actualmente. <a href="actividades.php" class="alert-link text-decoration-none">Buscar actividades</a>'; ?>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php if (has_role('Alumno')): ?>
                    <?php foreach ($modulos_inscriptas as $modulo):
                        $status_badge = $modulo['lista_espera'] ? 'badge-espera' : 'badge-activo';
                        if ($modulo['inscripcion_estado'] == 'cancelado') $status_badge = 'badge-cancelado';
                    ?>
                        <div class="col-md-6 col-lg-3">
                            <div class="poliba-card border-top border-5 border-info">
                                <div class="poliba-card-body p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="badge rounded-pill <?= $status_badge; ?>">
                                            <?= $modulo['inscripcion_estado'] == 'cancelado' ? 'CANCELADA' : ($modulo['lista_espera'] ? 'LISTA DE ESPERA' : 'ACTIVA'); ?>
                                        </span>
                                    </div>
                                    <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($modulo['modulo_nombre']); ?></h5>
                                    <div class="text-muted small mb-2"><i class="bi bi-trophy"></i> <?= htmlspecialchars($modulo['actividad_nombre']); ?></div>
                                    <div class="text-muted small mb-3"><i class="bi bi-building"></i> Entidad: <?= htmlspecialchars($modulo['entidad_nombre']); ?></div>
                                    
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php elseif (has_role('Profesor')): ?>
                    <!-- Profesor Modulos List -->
                    <?php foreach ($modulos_inscriptas as $modulo): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="poliba-card">
                                <div class="poliba-card-body p-3">
                                    <h5 class="fw-bold text-dark mb-2"><?= htmlspecialchars($modulo['nombre']); ?></h5>
                                    <p class="text-muted small mb-3"><?= htmlspecialchars($modulo['descripcion']); ?></p>
                                    <div class="text-dark small mb-1"><i class="bi bi-clock me-2"></i><?= date('H:i', strtotime($modulo['horario_inicio'])); ?> - <?= date('H:i', strtotime($modulo['horario_cierre'])); ?></div>
                                    <div class="text-dark small mb-3"><i class="bi bi-building me-2"></i>Entidad: <?= htmlspecialchars($modulo['entidad_nombre']); ?></div>
                                    
                                    <div class="d-grid gap-2 mt-auto">
                                        <a href="abm/profesor_modulos.php?modulo_id=<?= $modulo['id']; ?>" class="poliba-btn text-center text-decoration-none">Tomar Asistencia / Alumnos</a>
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

<!-- Sección 4: Módulos de Menores a Cargo -->
<?php if (has_role('Alumno') && !empty($menores)): ?>
<div class="container my-4" id="modulos-menores">
    <h3 class="fw-bold mb-4 text-center text-dark">Módulos de Menores</h3>
    <?php foreach ($menores as $menor):
        $mods = $modulos_menores[$menor['id']] ?? [];
    ?>
        <h5 class="fw-bold text-dark mb-3 border-bottom pb-2">
            <i class="bi bi-person-heart me-2"></i><?= htmlspecialchars($menor['nombre'] . ' ' . $menor['apellido']); ?>
            <small class="text-muted fw-normal">(<?= htmlspecialchars($menor['relacion']); ?>)</small>
        </h5>
        <?php if (empty($mods)): ?>
            <div class="alert alert-info mb-4">Este menor no está inscripto en ningún módulo.</div>
        <?php else: ?>
            <div class="row g-4 mb-4">
                <?php foreach ($mods as $m):
                    $badge = $m['lista_espera'] ? 'badge-espera' : 'badge-activo';
                    if ($m['inscripcion_estado'] == 'cancelado') $badge = 'badge-cancelado';
                ?>
                    <div class="col-md-6 col-lg-3">
                        <div class="poliba-card border-top border-5 border-info">
                            <div class="poliba-card-body p-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="badge rounded-pill <?= $badge; ?>">
                                        <?= $m['inscripcion_estado'] == 'cancelado' ? 'CANCELADA' : ($m['lista_espera'] ? 'LISTA DE ESPERA' : 'ACTIVA'); ?>
                                    </span>
                                </div>
                                <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($m['modulo_nombre']); ?></h5>
                                <div class="text-muted small mb-2"><i class="bi bi-trophy"></i> <?= htmlspecialchars($m['actividad_nombre']); ?></div>
                                <div class="text-dark small mb-1"><i class="bi bi-clock me-2"></i><?= date('H:i', strtotime($m['horario_inicio'])); ?> - <?= date('H:i', strtotime($m['horario_cierre'])); ?></div>
                                <div class="text-muted small"><i class="bi bi-building"></i> Entidad: <?= htmlspecialchars($m['entidad_nombre']); ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

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
                        <input type="date" name="fecha_nacimiento" class="form-control rounded-pill px-3" required min="<?= date('Y-m-d', strtotime('-18 years +1 day')); ?>" max="<?= date('Y-m-d'); ?>">
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
                        <option value="Hermano/a">Hermano/a</option>
                        <option value="Nieto/a">Abuelo/a (Nieto/a)</option>
                        <option value="Sobrino/a">Tío/a (Sobrino/a)</option>
                        <option value="Tutorado/a">Tutor Legal (Tutorado/a)</option>
                        <option value="Otro">Otro vínculo familiar</option>
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
                    <table class="table table-poliba table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Nombre Completo</th>
                                <th>DNI</th>
                                <th>Fecha Nac. / Edad</th>
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
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($menor['dni']); ?></span></td>
                                    <td><?= date('d/m/Y', strtotime($menor['fecha_nacimiento'])); ?> <small class="text-muted">(<?= $age; ?> años)</small></td>
                                    <td><span class="badge bg-secondary rounded-pill"><?= htmlspecialchars($menor['relacion']); ?></span></td>
                                    <td>
                                        <button class="btn btn-sm btn-dark rounded-pill px-3" data-bs-toggle="collapse" data-bs-target="#editMenorCol<?= $menor['id']; ?>">
                                            <i class="bi bi-pencil me-1"></i> Editar
                                        </button>
                                    </td>
                                </tr>
                                <tr class="collapse" id="editMenorCol<?= $menor['id']; ?>">
                                    <td colspan="5" class="bg-light p-3 border rounded">
                                        <form action="perfil.php" method="POST">
                                            <input type="hidden" name="action" value="modificar_menor">
                                            <input type="hidden" name="menor_id" value="<?= $menor['id']; ?>">
                                            <h6 class="fw-bold mb-3 text-dark"><i class="bi bi-pencil-square me-1"></i> Modificar Datos de <?= htmlspecialchars($menor['nombre'] . ' ' . $menor['apellido']); ?></h6>
                                            <div class="row g-2">
                                                <div class="col-md-4 mb-2">
                                                    <label class="form-label small fw-bold text-dark mb-1">Nombre *</label>
                                                    <input type="text" name="nombre" class="form-control form-control-sm rounded-pill px-3" placeholder="Nombre" required value="<?= htmlspecialchars($menor['nombre']); ?>">
                                                </div>
                                                <div class="col-md-4 mb-2">
                                                    <label class="form-label small fw-bold text-dark mb-1">Apellido *</label>
                                                    <input type="text" name="apellido" class="form-control form-control-sm rounded-pill px-3" placeholder="Apellido" required value="<?= htmlspecialchars($menor['apellido']); ?>">
                                                </div>
                                                <div class="col-md-4 mb-2">
                                                    <label class="form-label small fw-bold text-dark mb-1">DNI *</label>
                                                    <input type="text" name="dni" class="form-control form-control-sm rounded-pill px-3" placeholder="DNI" required value="<?= htmlspecialchars($menor['dni']); ?>">
                                                </div>
                                                <div class="col-md-4 mb-2">
                                                    <label class="form-label small fw-bold text-dark mb-1">Fecha de Nacimiento *</label>
                                                    <input type="date" name="fecha_nacimiento" class="form-control form-control-sm rounded-pill px-3" required value="<?= htmlspecialchars($menor['fecha_nacimiento']); ?>" min="<?= date('Y-m-d', strtotime('-18 years +1 day')); ?>" max="<?= date('Y-m-d'); ?>">
                                                </div>
                                                <div class="col-md-4 mb-2">
                                                    <label class="form-label small fw-bold text-dark mb-1">Dirección</label>
                                                    <input type="text" name="direccion" class="form-control form-control-sm rounded-pill px-3" placeholder="Dirección" value="<?= htmlspecialchars($menor['direccion'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-4 mb-2">
                                                    <label class="form-label small fw-bold text-dark mb-1">Relación / Vínculo *</label>
                                                    <select name="relacion" class="form-select form-select-sm rounded-pill px-3" required>
                                                        <option value="Hijo/a" <?= $menor['relacion'] == 'Hijo/a' ? 'selected' : ''; ?>>Hijo/a</option>
                                                        <option value="Hermano/a" <?= $menor['relacion'] == 'Hermano/a' ? 'selected' : ''; ?>>Hermano/a</option>
                                                        <option value="Nieto/a" <?= $menor['relacion'] == 'Nieto/a' ? 'selected' : ''; ?>>Nieto/a</option>
                                                        <option value="Sobrino/a" <?= $menor['relacion'] == 'Sobrino/a' ? 'selected' : ''; ?>>Sobrino/a</option>
                                                        <option value="Tutorado/a" <?= $menor['relacion'] == 'Tutorado/a' ? 'selected' : ''; ?>>Tutorado/a</option>
                                                        <option value="Otro" <?= $menor['relacion'] == 'Otro' ? 'selected' : ''; ?>>Otro</option>
                                                    </select>
                                                </div>
                                                <div class="col-12 text-end mt-2">
                                                    <button type="submit" class="poliba-btn btn-sm py-2 px-4">Guardar Cambios</button>
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

<script>
function compartirReserva(btn) {
    var url = new URL(btn.dataset.shareUrl, window.location.href).href;
    if (navigator.share) {
        navigator.share({ title: 'Reserva PoliBA', text: 'Te comparto mi reserva:', url: url }).catch(function () {});
    } else if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function () { alert('¡Link copiado al portapapeles!'); });
    } else {
        prompt('Copiá este link:', url);
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
