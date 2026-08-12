<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$polideportivos = [];
try {
    $stmt = $pdo->query("
        SELECT p.*, d1.nombre as dia_apertura, d2.nombre as dia_cierre 
        FROM polideportivos p
        LEFT JOIN dias d1 ON p.fk_dia_apertura = d1.id
        LEFT JOIN dias d2 ON p.fk_dia_cierre = d2.id
        WHERE p.estado = TRUE 
        ORDER BY p.nombre ASC
    ");
    $polideportivos = $stmt->fetchAll();
} catch (PDOException $e) {
    // Si falla, el listado estará vacío
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container my-4">
    <h2 class="section-title text-dark">Nuestros Polideportivos</h2>
    <p class="text-center text-muted mb-5">Explorá las sedes de la Ciudad de Buenos Aires y sus actividades.</p>
    
    <?php if (empty($polideportivos)): ?>
        <div class="text-center py-5">
            <i class="bi bi-building-fill-exclamation text-muted fs-1 mb-3"></i>
            <p class="text-muted">No hay polideportivos registrados en el sistema actualmente.</p>
        </div>
    <?php else: ?>
        <div class="row g-4 justify-content-center">
            <?php foreach ($polideportivos as $poli): 
                $img_url = "https://images.unsplash.com/photo-1579758629938-03607ccdbaba?auto=format&fit=crop&q=80&w=600"; // fallback gym/pool
                if (!empty($poli['imagenURL'])) {
                    if (filter_var($poli['imagenURL'], FILTER_VALIDATE_URL)) {
                        $img_url = $poli['imagenURL'];
                    } else {
                        $img_url = "img/" . $poli['imagenURL'];
                    }
                }
            ?>
                <div class="col-md-6 col-lg-5">
                    <div class="poliba-card">
                        <div class="poliba-card-img" style="background-image: url('<?= htmlspecialchars($img_url); ?>');">
                            <?php if (empty($poli['imagenURL'])): ?>
                                <span class="bg-dark bg-opacity-50 text-white w-100 h-100 d-flex align-items-center justify-content-center">Sede PoliBA</span>
                            <?php endif; ?>
                        </div>
                        <div class="poliba-card-body">
                            <h4 class="poliba-card-title fw-bold text-dark"><?= htmlspecialchars($poli['nombre']); ?></h4>
                            <p class="poliba-card-meta mb-2">
                                <i class="bi bi-geo-alt-fill text-danger me-1"></i> <?= htmlspecialchars($poli['direccion']); ?>
                            </p>
                            <p class="poliba-card-meta mb-3">
                                <i class="bi bi-clock-fill text-muted me-1"></i> 
                                <?= htmlspecialchars($poli['dia_apertura'] ?? 'Lunes'); ?> a <?= htmlspecialchars($poli['dia_cierre'] ?? 'Sábado'); ?> 
                                (<?= date('H:i', strtotime($poli['horario_apertura'])); ?> a <?= date('H:i', strtotime($poli['horario_cierre'])); ?> hs)
                            </p>
                            <p class="poliba-card-text">
                                <?= htmlspecialchars(substr($poli['informacion'], 0, 120)) . (strlen($poli['informacion']) > 120 ? '...' : ''); ?>
                            </p>
                            <div class="mt-auto d-flex justify-content-between align-items-center">
                                <a href="polideportivo.php?id=<?= $poli['id']; ?>" class="poliba-btn text-center w-100">Ver Ficha y Mapa</a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
