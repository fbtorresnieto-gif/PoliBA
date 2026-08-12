<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

// Obtener las novedades activas desde la base de datos
$novedades = [];
try {
    $stmt = $pdo->query("
        SELECT n.*, p.nombre as polideportivo_nombre 
        FROM novedades n 
        LEFT JOIN polideportivos p ON n.fk_polideportivo = p.id 
        WHERE n.estado = TRUE 
        ORDER BY n.fecha_inicio DESC
    ");
    $novedades = $stmt->fetchAll();
} catch (PDOException $e) {
    // Si no conecta o hay error, la grilla se mostrará vacía o con datos mock
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- 1. Hero / Carousel Banner (Basado en Prototipo Home.png) -->
<div id="polibaHeroCarousel" class="carousel slide poliba-hero" data-bs-ride="carousel">
    <div class="carousel-inner h-100">
        <div class="carousel-item active h-100">
            <div class="d-flex align-items-center justify-content-center h-100 bg-secondary text-white position-relative" style="background-image: linear-gradient(rgba(19, 38, 68, 0.6), rgba(19, 38, 68, 0.6)), url('https://images.unsplash.com/photo-1544698310-74ea9d1c8258?auto=format&fit=crop&q=80&w=1200'); background-size: cover; background-position: center;">
                <div class="text-center px-4">
                    <h1 class="display-4 fw-bold">Polideportivos de la Ciudad</h1>
                    <p class="lead">Inscribite a clases, reservá canchas y disfrutá del deporte en tu barrio de forma 100% digital.</p>
                    <a href="polideportivos.php" class="poliba-btn mt-2">Explorar Sedes</a>
                </div>
            </div>
        </div>
        <div class="carousel-item h-100">
            <div class="d-flex align-items-center justify-content-center h-100 bg-secondary text-white position-relative" style="background-image: linear-gradient(rgba(19, 38, 68, 0.6), rgba(19, 38, 68, 0.6)), url('https://images.unsplash.com/photo-1517649763962-0c623066013b?auto=format&fit=crop&q=80&w=1200'); background-size: cover; background-position: center;">
                <div class="text-center px-4">
                    <h1 class="display-4 fw-bold">Reservas de Canchas</h1>
                    <p class="lead">Reservá tu espacio de fútbol, tenis, básquet o vóley de manera rápida desde tu perfil.</p>
                    <a href="canchas.php" class="poliba-btn mt-2">Reservar Ahora</a>
                </div>
            </div>
        </div>
    </div>
    <button class="hero-control prev" type="button" data-bs-target="#polibaHeroCarousel" data-bs-slide="prev">
        <span>&lt;</span>
    </button>
    <button class="hero-control next" type="button" data-bs-target="#polibaHeroCarousel" data-bs-slide="next">
        <span>&gt;</span>
    </button>
</div>

<!-- 2. Novedades Section (Basado en Prototipo Home.png) -->
<div class="container my-5">
    <h2 class="section-title">Novedades</h2>
    
    <?php if (empty($novedades)): ?>
        <div class="text-center py-5">
            <i class="bi bi-info-circle text-muted fs-1 mb-3 d-block"></i>
            <p class="text-muted">No hay novedades publicadas en este momento.</p>
        </div>
    <?php else: ?>
        <div class="row g-4 justify-content-center">
            <?php foreach ($novedades as $novedad): 
                // Generar una imagen de respaldo bonita si no existe
                $img_url = "https://images.unsplash.com/photo-1461896836934-ffe607ba8211?auto=format&fit=crop&q=80&w=600"; // fallback deportista
                if (!empty($novedad['imagenURL'])) {
                    if (filter_var($novedad['imagenURL'], FILTER_VALIDATE_URL)) {
                        $img_url = $novedad['imagenURL'];
                    } else {
                        // Si es local, se puede vincular a la ruta correspondiente
                        $img_url = "img/" . $novedad['imagenURL'];
                    }
                }
            ?>
                <div class="col-md-6 col-lg-3">
                    <div class="poliba-card">
                        <div class="poliba-card-img" style="background-image: url('<?= htmlspecialchars($img_url); ?>');">
                            <?php if (empty($novedad['imagenURL'])): ?>
                                <span class="bg-dark bg-opacity-50 text-white w-100 h-100 d-flex align-items-center justify-content-center">PoliBA</span>
                            <?php endif; ?>
                        </div>
                        <div class="poliba-card-body">
                            <h5 class="poliba-card-title text-truncate" title="<?= htmlspecialchars($novedad['nombre']); ?>">
                                <?= htmlspecialchars($novedad['nombre']); ?>
                            </h5>
                            <div class="poliba-card-meta">
                                <i class="bi bi-building me-1"></i> <?= htmlspecialchars($novedad['polideportivo_nombre'] ?? 'General'); ?><br>
                                <i class="bi bi-calendar-event me-1"></i> <?= date('d/m/Y', strtotime($novedad['fecha_inicio'])); ?>
                            </div>
                            <p class="poliba-card-text line-clamp-3">
                                <?= htmlspecialchars(substr($novedad['descripcion'], 0, 100)) . (strlen($novedad['descripcion']) > 100 ? '...' : ''); ?>
                            </p>
                            <a href="#" class="poliba-card-link mt-auto" data-bs-toggle="modal" data-bs-target="#novedadModal<?= $novedad['id']; ?>">
                                Ver más <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Modal de Novedad Individual -->
                <div class="modal fade" id="novedadModal<?= $novedad['id']; ?>" tabindex="-1" aria-labelledby="novedadModalLabel<?= $novedad['id']; ?>" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                            <div class="modal-header border-0 bg-light" style="border-top-left-radius: 16px; border-top-right-radius: 16px;">
                                <h5 class="modal-title fw-bold text-dark" id="novedadModalLabel<?= $novedad['id']; ?>">
                                    <?= htmlspecialchars($novedad['nombre']); ?>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-4">
                                <img src="<?= htmlspecialchars($img_url); ?>" class="img-fluid rounded mb-3 w-100" style="max-height: 250px; object-fit: cover;" alt="Novedad">
                                <div class="mb-3 text-muted">
                                    <span class="me-3"><i class="bi bi-building me-1"></i> <?= htmlspecialchars($novedad['polideportivo_nombre'] ?? 'Sede General'); ?></span>
                                    <span><i class="bi bi-calendar-event me-1"></i> Publicado: <?= date('d/m/Y', strtotime($novedad['fecha_inicio'])); ?></span>
                                </div>
                                <div class="text-dark" style="line-height: 1.6; white-space: pre-line;">
                                    <?= htmlspecialchars($novedad['descripcion']); ?>
                                </div>
                            </div>
                            <div class="modal-footer border-0 bg-light" style="border-bottom-left-radius: 16px; border-bottom-right-radius: 16px;">
                                <button type="button" class="poliba-btn-dark px-4 py-2" data-bs-dismiss="modal">Cerrar</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
