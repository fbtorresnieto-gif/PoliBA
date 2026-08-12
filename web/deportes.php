<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$deportes = [];
try {
    $stmt = $pdo->query("
        SELECT d.*, p.nombre as polideportivo_nombre 
        FROM deportes d
        JOIN polideportivos p ON d.fk_polideportivo = p.id
        WHERE d.estado = TRUE AND p.estado = TRUE
        ORDER BY d.nombre ASC
    ");
    $deportes = $stmt->fetchAll();
} catch (PDOException $e) {
    // Si falla, el listado estará vacío
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container my-4">
    <h2 class="section-title text-dark">Disciplinas Deportivas</h2>
    <p class="text-center text-muted mb-5">Conocé todos los deportes disponibles en nuestras sedes.</p>
    
    <?php if (empty($deportes)): ?>
        <div class="text-center py-5">
            <i class="bi bi-trophy text-muted fs-1 mb-3"></i>
            <p class="text-muted">No hay deportes registrados en el sistema actualmente.</p>
        </div>
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
                            <p class="poliba-card-meta mb-2">
                                <i class="bi bi-building me-1"></i> Sede: <?= htmlspecialchars($deporte['polideportivo_nombre']); ?>
                            </p>
                            <p class="poliba-card-text">
                                <?= htmlspecialchars(substr($deporte['texto'], 0, 100)) . (strlen($deporte['texto']) > 100 ? '...' : ''); ?>
                            </p>
                            <a href="deporte.php?id=<?= $deporte['id']; ?>" class="poliba-card-link mt-auto">Ver Clases y Horarios <i class="bi bi-arrow-right ms-1"></i></a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
