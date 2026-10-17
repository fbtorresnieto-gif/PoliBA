<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$entidad_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$entidad = null;
$deportes = [];
$espacios = [];

if ($entidad_id > 0) {
    try {
        // Consultar los datos de la entidad
        $stmt = $pdo->prepare("
            SELECT e.*, d1.nombre as dia_apertura, d2.nombre as dia_cierre
            FROM entidades e
            LEFT JOIN dias d1 ON e.fk_dia_apertura = d1.id
            LEFT JOIN dias d2 ON e.fk_dia_cierre = d2.id
            WHERE e.id = ? AND e.estado = TRUE
        ");
        $stmt->execute([$entidad_id]);
        $entidad = $stmt->fetch();
        
        if ($entidad) {
            // Consultar deportes de esta entidad
            $stmt = $pdo->prepare("SELECT * FROM deportes WHERE fk_entidad = ? AND estado = TRUE");
            $stmt->execute([$entidad_id]);
            $deportes = $stmt->fetchAll();
            
            // Consultar espacios de esta entidad
            $stmt = $pdo->prepare("SELECT * FROM espacios WHERE fk_entidad = ? AND estado = TRUE");
            $stmt->execute([$entidad_id]);
            $espacios = $stmt->fetchAll();
        }
    } catch (PDOException $e) {
        // Log o mostrar mensaje
    }
}

// Redireccionar si no existe la sede
if (!$entidad) {
    header("Location: entidades.php");
    exit;
}

// Extraer coordenadas de latitud y longitud
$lat = -34.603722;
$lng = -58.381592;
if (!empty($entidad['coordenadas'])) {
    $parts = explode(',', $entidad['coordenadas']);
    if (count($parts) == 2) {
        $lat = trim($parts[0]);
        $lng = trim($parts[1]);
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container my-4">
    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php" class="text-dark">Home</a></li>
            <li class="breadcrumb-item"><a href="entidades.php" class="text-dark">Entidades</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($entidad['nombre']); ?></li>
        </ol>
    </nav>
    
    <div class="row">
        <!-- Ficha de Información de la Entidad -->
        <div class="col-lg-7">
            <div class="poliba-container-card mt-0 h-100 p-4">
                <h1 class="fw-bold mb-3" style="color: var(--poliba-dark-blue);"><?= htmlspecialchars($entidad['nombre']); ?></h1>
                
                <div class="mb-4">
                    <h5 class="fw-bold text-dark"><i class="bi bi-geo-alt-fill text-danger me-2"></i>Dirección</h5>
                    <p class="fs-5 text-muted ms-4"><?= htmlspecialchars($entidad['direccion']); ?></p>
                </div>
                
                <div class="mb-4">
                    <h5 class="fw-bold text-dark"><i class="bi bi-clock-fill text-muted me-2"></i>Horarios y Días</h5>
                    <p class="fs-5 text-muted ms-4">
                        Abierto de <strong><?= htmlspecialchars($entidad['dia_apertura'] ?? 'Lunes'); ?> a <?= htmlspecialchars($entidad['dia_cierre'] ?? 'Sábado'); ?></strong>
                        <br>
                        Horario: de <?= date('H:i', strtotime($entidad['horario_apertura'])); ?> a <?= date('H:i', strtotime($entidad['horario_cierre'])); ?> hs.
                    </p>
                </div>
                
                <div class="mb-4">
                    <h5 class="fw-bold text-dark"><i class="bi bi-info-circle-fill text-muted me-2"></i>Información General</h5>
                    <p class="text-dark ms-4" style="line-height: 1.6;"><?= nl2br(htmlspecialchars($entidad['informacion'])); ?></p>
                </div>
            </div>
        </div>
        
        <!-- Mapa interactivo (MapLibre GL JS + OpenFreeMap) -->
        <div class="col-lg-5 mt-4 mt-lg-0">
            <div class="poliba-container-card mt-0 h-100 p-4 text-center">
                <h4 class="fw-bold text-dark mb-3"><i class="bi bi-map-fill me-2"></i>Ubicación Interactiva</h4>
                
                <!-- Contenedor del mapa con atributos para JS -->
                <div id="poliba-map" class="poliba-map" 
                     data-lat="<?= $lat; ?>" 
                     data-lng="<?= $lng; ?>" 
                     data-name="<?= htmlspecialchars($entidad['nombre']); ?>"
                     data-address="<?= htmlspecialchars($entidad['direccion']); ?>">
                </div>
                <small class="text-muted d-block mt-2">Usa el mapa interactivo para ver cómo llegar a la entidad.</small>
            </div>
        </div>
    </div>
    
    <!-- Actividades en esta entidad -->
    <div class="my-5">
        <h3 class="fw-bold mb-4" style="color: var(--poliba-dark-blue); border-bottom: 2px solid var(--poliba-olive); padding-bottom: 0.5rem;">
            Actividades Disponibles
        </h3>
        
        <?php if (empty($deportes)): ?>
            <p class="text-muted">No hay actividades registradas para esta entidad en este momento.</p>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($deportes as $deporte): 
                    $img_url = "https://images.unsplash.com/photo-1461896836934-ffe607ba8211?auto=format&fit=crop&q=80&w=600";
                    $raw_img = $deporte['imagenurl'] ?? $deporte['imagenURL'] ?? '';
                    if (!empty($raw_img)) {
                        if (filter_var($raw_img, FILTER_VALIDATE_URL)) {
                            $img_url = $raw_img;
                        } else {
                            $img_url = "img/" . $raw_img;
                        }
                    }
                ?>
                    <div class="col-md-6 col-lg-3">
                        <div class="poliba-card">
                            <div class="poliba-card-img" style="background-image: url('<?= htmlspecialchars($img_url); ?>');"></div>
                            <div class="poliba-card-body">
                                <h5 class="poliba-card-title text-dark fw-bold"><?= htmlspecialchars($deporte['nombre']); ?></h5>
                                <p class="poliba-card-text">
                                    <?= htmlspecialchars(substr($deporte['texto'], 0, 100)) . (strlen($deporte['texto']) > 100 ? '...' : ''); ?>
                                </p>
                                <a href="deporte.php?id=<?= $deporte['id']; ?>" class="poliba-card-link mt-auto">Ver Actividades y Módulos <i class="bi bi-arrow-right ms-1"></i></a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Espacios en esta entidad -->
    <div class="my-5">
        <h3 class="fw-bold mb-4" style="color: var(--poliba-dark-blue); border-bottom: 2px solid var(--poliba-olive); padding-bottom: 0.5rem;">
            Espacios Disponibles
        </h3>
        
        <?php if (empty($espacios)): ?>
            <p class="text-muted">No hay espacios registrados en esta entidad en este momento.</p>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($espacios as $espacio): 
                    $img_url = "https://images.unsplash.com/photo-1544698310-74ea9d1c8258?auto=format&fit=crop&q=80&w=600";
                    $raw_img_espacio = $espacio['imagenurl'] ?? $espacio['imagenURL'] ?? '';
                    if (!empty($raw_img_espacio)) {
                        if (filter_var($raw_img_espacio, FILTER_VALIDATE_URL)) {
                            $img_url = $raw_img_espacio;
                        } else {
                            $img_url = "img/" . $raw_img_espacio;
                        }
                    }
                ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="poliba-card">
                            <div class="poliba-card-img" style="background-image: url('<?= htmlspecialchars($img_url); ?>');">
                                <span class="position-absolute top-0 end-0 m-3 badge rounded-pill <?= $espacio['techado'] ? 'bg-dark' : 'bg-secondary'; ?>">
                                    <?= $espacio['techado'] ? '<i class="bi bi-house-door-fill me-1"></i> Techado' : '<i class="bi bi-brightness-high-fill me-1"></i> Descubierto'; ?>
                                </span>
                            </div>
                            <div class="poliba-card-body">
                                <h5 class="poliba-card-title text-dark fw-bold"><?= htmlspecialchars($espacio['nombre']); ?></h5>
                                <p class="poliba-card-text">
                                    <?= htmlspecialchars($espacio['descripcion']); ?>
                                </p>
                                <a href="espacio.php?id=<?= $espacio['id']; ?>" class="poliba-btn text-center mt-auto w-100">Reservar Espacio</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
