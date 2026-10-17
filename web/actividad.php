<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$actividad_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$actividad = null;
$modulos = [];

if ($actividad_id > 0) {
    try {
        // Consultar detalle de la actividad
        $stmt = $pdo->prepare("
            SELECT d.*, e.nombre as entidad_nombre
            FROM actividades d
            JOIN entidades e ON d.fk_entidad = e.id
            WHERE d.id = ? AND d.estado = TRUE AND e.estado = TRUE
        ");
        $stmt->execute([$actividad_id]);
        $actividad = $stmt->fetch();
        
        if ($actividad) {
            // Query classes for this sport
            $stmt = $pdo->prepare("
                SELECT c.*, 
                       u.nombre as prof_nombre, u.apellido as prof_apellido,
                       cat.nombre as cat_nombre,
                       sub.nombre as sub_nombre,
                       esp.nombre as espacio_nombre
                FROM modulos c
                LEFT JOIN usuarios u ON c.fk_usuario_profesor = u.id
                LEFT JOIN categoria cat ON c.fk_categoria = cat.id
                LEFT JOIN subcategorias sub ON c.fk_subcategoria = sub.id
                LEFT JOIN espacios esp ON c.fk_espacio = esp.id
                WHERE c.fk_actividad = ? AND c.estado = TRUE
                ORDER BY c.nombre ASC
            ");
            $stmt->execute([$actividad_id]);
            $modulos = $stmt->fetchAll();
            
            // For each class, query days of class and count current enrollment
            foreach ($modulos as &$modulo) {
                // Get days
                $stmt_days = $pdo->prepare("
                    SELECT d.nombre 
                    FROM dias_modulos dc
                    JOIN dias d ON dc.fk_dia = d.id
                    WHERE dc.fk_modulo = ?
                    ORDER BY d.orden ASC
                ");
                $stmt_days->execute([$modulo['id']]);
                $modulo['dias'] = $stmt_days->fetchAll(PDO::FETCH_COLUMN);
                
                // Count active students in class (not in waitlist)
                $stmt_count = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM inscripcion 
                    WHERE fk_modulo = ? AND lista_espera = FALSE AND estado = 'activo'
                ");
                $stmt_count->execute([$modulo['id']]);
                $modulo['inscriptos_cantidad'] = $stmt_count->fetchColumn();
            }
            unset($modulo); // break reference
        }
    } catch (PDOException $e) {
        // Log error
    }
}

if (!$actividad) {
    header("Location: actividades.php");
    exit;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container my-4">
    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php" class="text-dark">Home</a></li>
            <li class="breadcrumb-item"><a href="actividades.php" class="text-dark">Actividades</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($actividad['nombre']); ?></li>
        </ol>
    </nav>
    
    <!-- Sport Header Ficha -->
    <div class="row align-items-center mb-5">
        <div class="col-md-5">
            <?php 
            $img_url = "https://images.unsplash.com/photo-1461896836934-ffe607ba8211?auto=format&fit=crop&q=80&w=600";
            $raw_img = $actividad['imagenurl'] ?? $actividad['imagenURL'] ?? '';
            if (!empty($raw_img)) {
                if (filter_var($raw_img, FILTER_VALIDATE_URL)) {
                    $img_url = $raw_img;
                } else {
                    $img_url = "img/" . $raw_img;
                }
            }
            ?>
            <img src="<?= htmlspecialchars($img_url); ?>" class="img-fluid rounded shadow-sm w-100" style="max-height: 350px; object-fit: cover;" alt="<?= htmlspecialchars($actividad['nombre']); ?>">
        </div>
        <div class="col-md-7 mt-4 mt-md-0">
            <span class="badge bg-dark rounded-pill px-3 py-2 mb-2"><i class="bi bi-building me-1"></i> Entidad: <?= htmlspecialchars($actividad['entidad_nombre']); ?></span>
            <h1 class="fw-bold text-dark mb-3"><?= htmlspecialchars($actividad['nombre']); ?></h1>
            <p class="fs-5 text-dark" style="line-height: 1.6;"><?= nl2br(htmlspecialchars($actividad['texto'])); ?></p>
        </div>
    </div>
    
    <!-- Modulos Section -->
    <h3 class="fw-bold mb-4" style="color: var(--poliba-dark-blue); border-bottom: 2px solid var(--poliba-olive); padding-bottom: 0.5rem;">
        Módulos y Horarios Disponibles
    </h3>
    
    <?php if (empty($modulos)): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle-fill me-2"></i> No hay módulos programados para esta actividad en este momento.
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($modulos as $modulo):
                $cupos_libres = max(0, $modulo['cupo_maximo'] - $modulo['inscriptos_cantidad']);
            ?>
                <div class="col-lg-6">
                    <div class="poliba-container-card mt-0 p-4 h-100 border">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <h4 class="fw-bold text-dark m-0"><?= htmlspecialchars($modulo['nombre']); ?></h4>
                            <span class="badge <?= $cupos_libres > 0 ? 'bg-success' : 'bg-warning text-dark'; ?> rounded-pill px-3 py-2">
                                <?= $cupos_libres > 0 ? "$cupos_libres vacantes" : 'Lista de Espera'; ?>
                            </span>
                        </div>
                        
                        <p class="text-muted mb-3"><?= htmlspecialchars($modulo['descripcion']); ?></p>
                        
                        <div class="row mb-3" style="font-size: 0.95rem;">
                            <div class="col-6 mb-2">
                                <strong><i class="bi bi-calendar-check text-muted me-2"></i>Días:</strong> 
                                <?= !empty($modulo['dias']) ? htmlspecialchars(implode(', ', $modulo['dias'])) : 'Sin asignar'; ?>
                            </div>
                            <div class="col-6 mb-2">
                                <strong><i class="bi bi-clock text-muted me-2"></i>Horario:</strong> 
                                <?= date('H:i', strtotime($modulo['horario_inicio'])); ?> - <?= date('H:i', strtotime($modulo['horario_cierre'])); ?>
                            </div>
                            <div class="col-6 mb-2">
                                <strong><i class="bi bi-person-badge text-muted me-2"></i>Profesor:</strong> 
                                <?= !empty($modulo['prof_nombre']) ? htmlspecialchars($modulo['prof_nombre'] . ' ' . $modulo['prof_apellido']) : 'Sin asignar'; ?>
                            </div>
                            <div class="col-6 mb-2">
                                <strong><i class="bi bi-grid-3x3-gap text-muted me-2"></i>Espacio:</strong> 
                                <?= !empty($modulo['espacio_nombre']) ? htmlspecialchars($modulo['espacio_nombre']) : 'Espacio General'; ?>
                            </div>
                            <div class="col-6">
                                <strong><i class="bi bi-people text-muted me-2"></i>Cupo total:</strong> 
                                <?= $modulo['cupo_maximo']; ?> alumnos
                            </div>
                            <div class="col-6">
                                <strong><i class="bi bi-tag text-muted me-2"></i>Categoría:</strong> 
                                <?= htmlspecialchars($modulo['cat_nombre'] ?? 'General'); ?>
                                <?= !empty($modulo['sub_nombre']) ? " (" . htmlspecialchars($modulo['sub_nombre']) . ")" : ""; ?>
                            </div>
                        </div>
                        
                        <div class="mt-4 pt-2 border-top d-flex justify-content-end align-items-center">
                            <?php if (is_logged_in() && has_role('Alumno')): ?>
                                <a href="abm/alumno_inscripcion.php?modulo_id=<?= $modulo['id']; ?>" class="poliba-btn">
                                    <?= $cupos_libres > 0 ? 'Inscribirse' : 'Entrar a Lista de Espera'; ?>
                                </a>
                            <?php elseif (!is_logged_in()): ?>
                                <a href="login.php" class="poliba-btn-dark">Loguearse para Inscribirse</a>
                            <?php else: ?>
                                <span class="text-muted small">Inscripción solo para Alumnos</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
