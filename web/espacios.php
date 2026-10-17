<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$espacios = [];
try {
    $stmt = $pdo->query("
        SELECT esp.*, e.nombre as entidad_nombre
        FROM espacios esp
        JOIN entidades e ON esp.fk_entidad = e.id
        WHERE esp.estado = TRUE AND e.estado = TRUE
        ORDER BY e.nombre ASC, esp.nombre ASC
    ");
    $espacios = $stmt->fetchAll();
} catch (PDOException $e) {
    // Si falla, el listado estará vacío
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container my-4">
    <h2 class="section-title text-dark">Nuestros Espacios</h2>
    <p class="text-center text-muted mb-5">Reservá turnos de juego en las distintas entidades de la Ciudad.</p>
    
    <?php if (empty($espacios)): ?>
        <div class="text-center py-5">
            <i class="bi bi-grid-3x3-gap text-muted fs-1 mb-3"></i>
            <p class="text-muted">No hay espacios registrados en el sistema actualmente.</p>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($espacios as $espacio):
                $img_url = "https://images.unsplash.com/photo-1544698310-74ea9d1c8258?auto=format&fit=crop&q=80&w=600";
                $raw_img = $espacio['imagenurl'] ?? $espacio['imagenURL'] ?? '';
                if (!empty($raw_img)) {
                    if (filter_var($raw_img, FILTER_VALIDATE_URL)) {
                        $img_url = $raw_img;
                    } else {
                        $img_url = "img/" . $raw_img;
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
                            <p class="poliba-card-meta mb-2">
                                <i class="bi bi-building me-1"></i> Entidad: <?= htmlspecialchars($espacio['entidad_nombre']); ?>
                            </p>
                            <p class="poliba-card-text">
                                <?= htmlspecialchars($espacio['descripcion']); ?>
                            </p>
                            <a href="espacio.php?id=<?= $espacio['id']; ?>" class="poliba-btn text-center mt-auto w-100">Ver Calendario y Reservar Espacio</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
