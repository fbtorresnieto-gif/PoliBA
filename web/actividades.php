<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/FiltroEntidades.php';
$filtroEntidades = new FiltroEntidades([], $_GET);
$usuarioCatalogo = get_logged_user();
$errorListado = false;

$actividades = [];
try {
    $filtroEntidades = FiltroEntidades::cargar($pdo, $_GET, $usuarioCatalogo);
    [$filtroSql, $filtroParametros] = $filtroEntidades->condicion('d.fk_entidad');
    $stmt = $pdo->prepare("
        SELECT d.*, e.nombre as entidad_nombre
        FROM actividades d
        JOIN entidades e ON d.fk_entidad = e.id
        WHERE d.estado = TRUE AND e.estado = TRUE
          $filtroSql
        ORDER BY d.nombre ASC
    ");
    $stmt->execute($filtroParametros);
    $actividades = $stmt->fetchAll();
} catch (PDOException $e) {
    $errorListado = true;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container my-4">
    <h2 class="section-title text-dark">Nuestras Actividades</h2>
    <p class="text-center text-muted mb-5">Conocé todas las actividades disponibles en nuestras entidades.</p>
    <?php $filtroCantidad = count($actividades); require __DIR__.'/includes/filtro_entidades_formulario.php'; ?>
    <?php if ($errorListado): ?><div class="alert alert-danger" role="alert">No pudimos cargar las actividades. Intentá nuevamente en unos minutos.</div><?php endif; ?>
    
    <?php if ($errorListado): ?>
    <?php elseif (empty($actividades)): ?>
        <div class="text-center py-5">
            <i class="bi bi-trophy text-muted fs-1 mb-3"></i>
            <p class="text-muted"><?= $filtroEntidades->todas ? 'No hay actividades registradas en el sistema actualmente.' : 'No hay actividades para las entidades seleccionadas.'; ?></p>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($actividades as $actividad):
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
                <div class="col-md-6 col-lg-3">
                    <div class="poliba-card">
                        <div class="poliba-card-img" style="background-image: url('<?= htmlspecialchars($img_url); ?>');"></div>
                        <div class="poliba-card-body">
                            <h5 class="poliba-card-title text-dark fw-bold"><?= htmlspecialchars($actividad['nombre']); ?></h5>
                            <p class="poliba-card-meta mb-2">
                                <i class="bi bi-building me-1"></i> Entidad: <?= htmlspecialchars($actividad['entidad_nombre']); ?>
                            </p>
                            <p class="poliba-card-text">
                                <?= htmlspecialchars(substr($actividad['texto'], 0, 100)) . (strlen($actividad['texto']) > 100 ? '...' : ''); ?>
                            </p>
                            <a href="actividad.php?id=<?= $actividad['id']; ?>" class="poliba-card-link mt-auto">Ver Actividad y Modulos <i class="bi bi-arrow-right ms-1"></i></a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
