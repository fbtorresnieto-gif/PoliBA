<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$poli_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$poli = null;
$deportes = [];
$canchas = [];

if ($poli_id > 0) {
    try {
        // Query polideportivo details
        $stmt = $pdo->prepare("
            SELECT p.*, d1.nombre as dia_apertura, d2.nombre as dia_cierre 
            FROM polideportivos p
            LEFT JOIN dias d1 ON p.fk_dia_apertura = d1.id
            LEFT JOIN dias d2 ON p.fk_dia_cierre = d2.id
            WHERE p.id = ? AND p.estado = TRUE
        ");
        $stmt->execute([$poli_id]);
        $poli = $stmt->fetch();
        
        if ($poli) {
            // Query sports in this polideportivo
            $stmt = $pdo->prepare("SELECT * FROM deportes WHERE fk_polideportivo = ? AND estado = TRUE");
            $stmt->execute([$poli_id]);
            $deportes = $stmt->fetchAll();
            
            // Query courts in this polideportivo
            $stmt = $pdo->prepare("SELECT * FROM canchas WHERE fk_polideportivo = ? AND estado = TRUE");
            $stmt->execute([$poli_id]);
            $canchas = $stmt->fetchAll();
        }
    } catch (PDOException $e) {
        // Log o mostrar mensaje
    }
}

// Redireccionar si no existe la sede
if (!$poli) {
    header("Location: polideportivos.php");
    exit;
}

// Extraer coordenadas de latitud y longitud
$lat = -34.603722;
$lng = -58.381592;
if (!empty($poli['coordenadas'])) {
    $parts = explode(',', $poli['coordenadas']);
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
            <li class="breadcrumb-item"><a href="polideportivos.php" class="text-dark">Polideportivos</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($poli['nombre']); ?></li>
        </ol>
    </nav>
    
    <div class="row">
        <!-- Ficha de Información de la Sede -->
        <div class="col-lg-7">
            <div class="poliba-container-card mt-0 h-100 p-4">
                <h1 class="fw-bold mb-3" style="color: var(--poliba-dark-blue);"><?= htmlspecialchars($poli['nombre']); ?></h1>
                
                <div class="mb-4">
                    <h5 class="fw-bold text-dark"><i class="bi bi-geo-alt-fill text-danger me-2"></i>Dirección</h5>
                    <p class="fs-5 text-muted ms-4"><?= htmlspecialchars($poli['direccion']); ?></p>
                </div>
                
                <div class="mb-4">
                    <h5 class="fw-bold text-dark"><i class="bi bi-clock-fill text-muted me-2"></i>Horarios y Días</h5>
                    <p class="fs-5 text-muted ms-4">
                        Abierto de <strong><?= htmlspecialchars($poli['dia_apertura'] ?? 'Lunes'); ?> a <?= htmlspecialchars($poli['dia_cierre'] ?? 'Sábado'); ?></strong>
                        <br>
                        Horario: de <?= date('H:i', strtotime($poli['horario_apertura'])); ?> a <?= date('H:i', strtotime($poli['horario_cierre'])); ?> hs.
                    </p>
                </div>
                
                <div class="mb-4">
                    <h5 class="fw-bold text-dark"><i class="bi bi-info-circle-fill text-muted me-2"></i>Información General</h5>
                    <p class="text-dark ms-4" style="line-height: 1.6;"><?= nl2br(htmlspecialchars($poli['informacion'])); ?></p>
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
                     data-name="<?= htmlspecialchars($poli['nombre']); ?>" 
                     data-address="<?= htmlspecialchars($poli['direccion']); ?>">
                </div>
                <small class="text-muted d-block mt-2">Usa el mapa interactivo para ver cómo llegar a la sede.</small>
            </div>
        </div>
    </div>
    
    <!-- Deportes en esta sede -->
    <div class="my-5">
        <h3 class="fw-bold mb-4" style="color: var(--poliba-dark-blue); border-bottom: 2px solid var(--poliba-olive); padding-bottom: 0.5rem;">
            Deportes Disponibles
        </h3>
        
        <?php if (empty($deportes)): ?>
            <p class="text-muted">No hay deportes registrados para esta sede en este momento.</p>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($deportes as $deporte): 
                    $img_url = "https://images.unsplash.com/photo-1461896836934-ffe607ba8211?auto=format&fit=crop&q=80&w=600";
                    if (!empty($deporte['imagenURL'])) {
                        if (filter_var($deporte['imagenURL'], FILTER_VALIDATE_URL)) {
                            $img_url = $deporte['imagenURL'];
                        } else {
                            $img_url = "img/" . $deporte['imagenURL'];
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
                                <a href="deporte.php?id=<?= $deporte['id']; ?>" class="poliba-card-link mt-auto">Ver Actividades y Clases <i class="bi bi-arrow-right ms-1"></i></a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Canchas en esta sede -->
    <div class="my-5">
        <h3 class="fw-bold mb-4" style="color: var(--poliba-dark-blue); border-bottom: 2px solid var(--poliba-olive); padding-bottom: 0.5rem;">
            Canchas y Espacios
        </h3>
        
        <?php if (empty($canchas)): ?>
            <p class="text-muted">No hay canchas registradas en esta sede en este momento.</p>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($canchas as $cancha): 
                    $img_url = "https://images.unsplash.com/photo-1544698310-74ea9d1c8258?auto=format&fit=crop&q=80&w=600";
                    if (!empty($cancha['imagenURL'])) {
                        if (filter_var($cancha['imagenURL'], FILTER_VALIDATE_URL)) {
                            $img_url = $cancha['imagenURL'];
                        } else {
                            $img_url = "img/" . $cancha['imagenURL'];
                        }
                    }
                ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="poliba-card">
                            <div class="poliba-card-img" style="background-image: url('<?= htmlspecialchars($img_url); ?>');">
                                <span class="position-absolute top-0 end-0 m-3 badge rounded-pill <?= $cancha['techado'] ? 'bg-dark' : 'bg-secondary'; ?>">
                                    <?= $cancha['techado'] ? '<i class="bi bi-house-door-fill me-1"></i> Techada' : '<i class="bi bi-brightness-high-fill me-1"></i> Descubierta'; ?>
                                </span>
                            </div>
                            <div class="poliba-card-body">
                                <h5 class="poliba-card-title text-dark fw-bold"><?= htmlspecialchars($cancha['nombre']); ?></h5>
                                <p class="poliba-card-text">
                                    <?= htmlspecialchars($cancha['descripcion']); ?>
                                </p>
                                <a href="cancha.php?id=<?= $cancha['id']; ?>" class="poliba-btn text-center mt-auto w-100">Reservar Turno</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
