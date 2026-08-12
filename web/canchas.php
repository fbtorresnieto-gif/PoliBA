<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$canchas = [];
try {
    $stmt = $pdo->query("
        SELECT c.*, p.nombre as polideportivo_nombre 
        FROM canchas c
        JOIN polideportivos p ON c.fk_polideportivo = p.id
        WHERE c.estado = TRUE AND p.estado = TRUE
        ORDER BY p.nombre ASC, c.nombre ASC
    ");
    $canchas = $stmt->fetchAll();
} catch (PDOException $e) {
    // Si falla, el listado estará vacío
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container my-4">
    <h2 class="section-title text-dark">Nuestras Canchas y Espacios</h2>
    <p class="text-center text-muted mb-5">Reservá turnos de juego en las sedes de la Ciudad.</p>
    
    <?php if (empty($canchas)): ?>
        <div class="text-center py-5">
            <i class="bi bi-grid-3x3-gap text-muted fs-1 mb-3"></i>
            <p class="text-muted">No hay canchas registradas en el sistema actualmente.</p>
        </div>
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
                            <p class="poliba-card-meta mb-2">
                                <i class="bi bi-building me-1"></i> Sede: <?= htmlspecialchars($cancha['polideportivo_nombre']); ?>
                            </p>
                            <p class="poliba-card-text">
                                <?= htmlspecialchars($cancha['descripcion']); ?>
                            </p>
                            <a href="cancha.php?id=<?= $cancha['id']; ?>" class="poliba-btn text-center mt-auto w-100">Ver Calendario y Reservar</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
