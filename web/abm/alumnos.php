<?php
$base_path = '../';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Validar que sea Administrador
redirect_if_not_logged_in(['Administrador']);

$user = get_logged_user();
$poli_id = $user['fk_polideportivo']; // Sede administrada

if (!$poli_id) {
    die("Error: El administrador no tiene una sede polideportiva asignada.");
}

// Procesar búsqueda
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$alumnos = [];

try {
    $sql = "
        SELECT u.* 
        FROM usuarios u
        JOIN roles r ON u.fk_rol = r.id
        WHERE u.fk_polideportivo = ? AND r.nombre = 'Alumno'
    ";
    
    $params = [$poli_id];
    
    if (!empty($search_query)) {
        // SQLite soporta || para concatenar, Postgres también.
        $sql .= " AND (u.nombre ILIKE ? OR u.apellido ILIKE ? OR u.dni LIKE ?)";
        // Para SQLite que es case-sensitive o usa LIKE normal, usaremos LIKE y concatenamos %
        // Adaptemos la consulta para que funcione en ambos drivers (PostgreSQL y SQLite fallback)
        global $db_driver_used;
        if ($db_driver_used == 'sqlite') {
            $sql = str_replace('ILIKE', 'LIKE', $sql);
        }
        $search_param = "%$search_query%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = "%$search_query%";
    }
    
    $sql .= " ORDER BY u.apellido, u.nombre ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $alumnos = $stmt->fetchAll();
    
    // Obtener las clases inscriptas para cada alumno en esta sede
    foreach ($alumnos as &$alumno) {
        $stmt_cl = $pdo->prepare("
            SELECT c.nombre 
            FROM inscripcion i
            JOIN clases c ON i.fk_clase = c.id
            WHERE i.fk_usuario = ? AND c.fk_polideportivo = ? AND i.estado = 'activo'
        ");
        $stmt_cl->execute([$alumno['id'], $poli_id]);
        $alumno['clases'] = $stmt_cl->fetchAll(PDO::FETCH_COLUMN);
        
        // Cargar menores asociados
        $stmt_m = $pdo->prepare("SELECT nombre, apellido, dni FROM menores WHERE fk_usuario = ?");
        $stmt_m->execute([$alumno['id']]);
        $alumno['menores'] = $stmt_m->fetchAll();
    }
    unset($alumno);
} catch (PDOException $e) {
    // Log
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="mb-4">
        <h2 class="fw-bold text-dark m-0">Buscador de Alumnos</h2>
        <small class="text-muted">Administrando Sede: <strong><?= htmlspecialchars($user['fk_polideportivo_nombre'] ?? 'Mi Polideportivo'); ?></strong></small>
    </div>

    <!-- Barra de búsqueda -->
    <div class="poliba-container-card mt-0 p-4 mb-4">
        <form action="alumnos.php" method="GET">
            <div class="row g-2 align-items-center">
                <div class="col-md-8 col-lg-9">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0 rounded-start-pill px-3"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-start-0 rounded-end-pill px-3 py-2" 
                               placeholder="Buscar por Nombre, Apellido o DNI..." 
                               value="<?= htmlspecialchars($search_query); ?>">
                    </div>
                </div>
                <div class="col-md-4 col-lg-3">
                    <div class="d-flex gap-2">
                        <button type="submit" class="poliba-btn w-100 py-2">Buscar</button>
                        <?php if (!empty($search_query)): ?>
                            <a href="alumnos.php" class="btn btn-outline-secondary rounded-pill px-3 py-2"><i class="bi bi-x-lg"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Resultados -->
    <div class="table-responsive">
        <table class="table table-poliba table-striped">
            <thead>
                <tr>
                    <th>Nombre Completo</th>
                    <th>DNI</th>
                    <th>Gmail</th>
                    <th>Teléfono</th>
                    <th>Clases Activas</th>
                    <th>Menores a Cargo</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($alumnos)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No se encontraron alumnos con los criterios de búsqueda.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($alumnos as $al): ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($al['nombre'] . ' ' . $al['apellido']); ?></td>
                            <td><?= htmlspecialchars($al['dni']); ?></td>
                            <td><?= htmlspecialchars($al['email']); ?></td>
                            <td><?= htmlspecialchars($al['telefono'] ?? '-'); ?></td>
                            <td>
                                <?php if (empty($al['clases'])): ?>
                                    <span class="text-muted small">Ninguna clase</span>
                                <?php else: ?>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php foreach ($al['clases'] as $cls): ?>
                                            <span class="badge bg-info text-dark rounded-pill" style="font-size:0.8rem;"><?= htmlspecialchars($cls); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (empty($al['menores'])): ?>
                                    <span class="text-muted small">-</span>
                                <?php else: ?>
                                    <ul class="m-0 p-0 ps-3 small text-muted">
                                        <?php foreach ($al['menores'] as $m): ?>
                                            <li><?= htmlspecialchars($m['nombre'] . ' ' . $m['apellido']); ?> (DNI: <?= htmlspecialchars($m['dni']); ?>)</li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
