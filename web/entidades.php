<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$entidades = [];
try {
    $stmt = $pdo->query("
        SELECT e.*, d1.nombre as dia_apertura, d2.nombre as dia_cierre
        FROM entidades e
        LEFT JOIN dias d1 ON e.fk_dia_apertura = d1.id
        LEFT JOIN dias d2 ON e.fk_dia_cierre = d2.id
        WHERE e.estado = TRUE
        ORDER BY e.nombre ASC
    ");
    $entidades = $stmt->fetchAll();
} catch (PDOException $e) {
    // Si falla, el listado estará vacío
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container my-4">
    <h2 class="section-title text-dark">Nuestras Entidades</h2>
    <p class="text-center text-muted mb-5">Explorá las entidades de la Ciudad de Buenos Aires y sus actividades.</p>
    
    <?php if (empty($entidades)): ?>
        <div class="text-center py-5">
            <i class="bi bi-building-fill-exclamation text-muted fs-1 mb-3"></i>
            <p class="text-muted">No hay entidades registradas en el sistema actualmente.</p>
        </div>
    <?php else: ?>
        <div class="row g-4 justify-content-center">
            <?php foreach ($entidades as $entidad):
                $img_url = "https://images.unsplash.com/photo-1579758629938-03607ccdbaba?auto=format&fit=crop&q=80&w=600"; // fallback gym/pool
                $raw_img = $entidad['imagenurl'] ?? $entidad['imagenURL'] ?? '';
                if (!empty($raw_img)) {
                    if (filter_var($raw_img, FILTER_VALIDATE_URL)) {
                        $img_url = $raw_img;
                    } else {
                        $img_url = "img/" . $raw_img;
                    }
                }
            ?>
                <div class="col-md-6 col-lg-5">
                    <div class="poliba-card">
                        <div class="poliba-card-img" style="background-image: url('<?= htmlspecialchars($img_url); ?>');">
                            <?php if (empty($raw_img)): ?>
                                <span class="bg-dark bg-opacity-50 text-white w-100 h-100 d-flex align-items-center justify-content-center">MultiPro</span>
                            <?php endif; ?>
                        </div>
                        <div class="poliba-card-body">
                            <h4 class="poliba-card-title fw-bold text-dark"><?= htmlspecialchars($entidad['nombre']); ?></h4>
                            <p class="poliba-card-meta mb-2">
                                <i class="bi bi-geo-alt-fill text-danger me-1"></i> <?= htmlspecialchars($entidad['direccion']); ?>
                            </p>
                            <p class="poliba-card-meta mb-3">
                                <i class="bi bi-clock-fill text-muted me-1"></i> 
                                <?= htmlspecialchars($entidad['dia_apertura'] ?? 'Lunes'); ?> a <?= htmlspecialchars($entidad['dia_cierre'] ?? 'Sábado'); ?>
                                (<?= date('H:i', strtotime($entidad['horario_apertura'])); ?> a <?= date('H:i', strtotime($entidad['horario_cierre'])); ?> hs)
                            </p>
                            <p class="poliba-card-text">
                                <?= htmlspecialchars(substr($entidad['informacion'], 0, 120)) . (strlen($entidad['informacion']) > 120 ? '...' : ''); ?>
                            </p>
                            <div class="mt-auto d-flex justify-content-between align-items-center">
                                <a href="entidad.php?id=<?= $entidad['id']; ?>" class="poliba-btn text-center w-100">Ver Entidad y Mapa</a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
