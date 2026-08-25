<?php
require_once __DIR__ . '/auth.php';
$logged_user = get_logged_user();

// Autodetectar base_path si no fue seteado previamente
if (!isset($base_path)) {
    $script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $base_path = (strpos($script_dir, '/abm') !== false) ? '../' : '';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PoliBA - Plataforma de Polideportivos CABA</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Google Fonts: Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- MapLibre GL JS & OpenFreeMap assets -->
    <script src="https://unpkg.com/maplibre-gl@3.6.2/dist/maplibre-gl.js"></script>
    <link href="https://unpkg.com/maplibre-gl@3.6.2/dist/maplibre-gl.css" rel="stylesheet" />
    
    <!-- Custom CSS -->
    <link href="<?= $base_path; ?>css/styles.css?v=<?= time(); ?>" rel="stylesheet">
</head>
<body>

    <!-- Header Navigation Bar -->
    <nav class="navbar navbar-expand-lg poliba-header navbar-light sticky-top shadow-sm">
        <div class="container-fluid px-md-5">
            <a class="navbar-brand poliba-logo" href="<?= $base_path; ?>index.php">Poli<span>BA</span></a>
            
            <div class="d-flex align-items-center">
                <?php if (is_logged_in()): ?>
                    <!-- Botón Hamburguesa en Mobile -->
                    <button type="button" class="btn poliba-hamburger-btn d-lg-none me-2" id="sidebar-toggle-mobile" data-toggle-sidebar="true" aria-label="Abrir panel" title="Abrir Panel">
                        <i class="bi bi-list"></i>
                    </button>
                <?php endif; ?>
                
                <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
            </div>
            
            <div class="collapse navbar-collapse justify-content-end" id="navbarContent">
                <ul class="navbar-nav align-items-center">
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" href="<?= $base_path; ?>index.php">HOME</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'polideportivos.php' || basename($_SERVER['PHP_SELF']) == 'polideportivo.php' ? 'active' : ''; ?>" href="<?= $base_path; ?>polideportivos.php">Polideportivos</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'deportes.php' || basename($_SERVER['PHP_SELF']) == 'deporte.php' ? 'active' : ''; ?>" href="<?= $base_path; ?>deportes.php">Deportes</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'canchas.php' || basename($_SERVER['PHP_SELF']) == 'cancha.php' ? 'active' : ''; ?>" href="<?= $base_path; ?>canchas.php">Canchas</a>
                    </li>
                    
                    <?php if (is_logged_in()): ?>
                        <!-- Botón Hamburguesa en Desktop -->
                        <li class="nav-item ms-lg-3 d-none d-lg-block">
                            <button type="button" class="btn poliba-hamburger-btn" id="sidebar-toggle" data-toggle-sidebar="true" aria-label="Abrir panel" title="Abrir Panel">
                                <i class="bi bi-list"></i>
                            </button>
                        </li>
                        <!-- Botón directo en Menú Desplegable Mobile -->
                        <li class="nav-item d-lg-none mt-3 w-100">
                            <button type="button" class="btn poliba-btn w-100 d-flex align-items-center justify-content-center py-2" id="sidebar-toggle-collapse" data-toggle-sidebar="true">
                                <i class="bi bi-layout-sidebar-reverse me-2 fs-5"></i> Abrir Panel
                            </button>
                        </li>
                    <?php else: ?>
                        <li class="nav-item ms-lg-3">
                            <a class="poliba-btn-dark py-2 px-4" href="<?= $base_path; ?>login.php">Login</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Sidebar Panel Navigation overlay (Slides from Right) -->
    <?php if (is_logged_in()): 
        $logged_user = $logged_user ?: get_logged_user();
    ?>
    <div id="poliba-sidebar" class="poliba-sidebar">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="sidebar-title m-0">Panel</h3>
            <button id="sidebar-close" class="sidebar-close" aria-label="Cerrar">&times;</button>
        </div>
        
        <div class="mb-4 text-dark p-3 rounded" style="background: rgba(255, 255, 255, 0.45);">
            <small class="text-uppercase text-muted fw-bold d-block" style="font-size: 0.75rem;">Usuario Conectado</small>
            <div class="fw-bold fs-5 text-truncate" title="<?= htmlspecialchars($logged_user['nombre'] . ' ' . $logged_user['apellido']); ?>">
                <?= htmlspecialchars($logged_user['nombre'] . ' ' . $logged_user['apellido']); ?>
            </div>
            <span class="badge bg-dark rounded-pill mt-1"><?= htmlspecialchars($logged_user['rol_nombre']); ?></span>
        </div>

        <div class="sidebar-menu">
            <!-- Botones Generales -->
            <a href="<?= $base_path; ?>perfil.php" class="sidebar-btn"><i class="bi bi-person-fill me-2"></i>Mi perfil</a>
            
            <!-- Botones del GESTOR -->
            <?php if (has_role('Gestor')): ?>
                <a href="<?= $base_path; ?>abm/polideportivos.php" class="sidebar-btn"><i class="bi bi-building me-2"></i>Polideportivos</a>
                <a href="<?= $base_path; ?>abm/administradores.php" class="sidebar-btn"><i class="bi bi-shield-lock me-2"></i>Administradores</a>
            <?php endif; ?>

            <!-- Botones del ADMINISTRADOR -->
            <?php if (has_role('Administrador')): ?>
                <a href="<?= $base_path; ?>abm/deportes.php" class="sidebar-btn"><i class="bi bi-trophy me-2"></i>Deportes</a>
                <a href="<?= $base_path; ?>abm/canchas.php" class="sidebar-btn"><i class="bi bi-grid-3x3-gap me-2"></i>Canchas</a>
                <a href="<?= $base_path; ?>abm/clases.php" class="sidebar-btn"><i class="bi bi-calendar3 me-2"></i>Clases</a>
                <a href="<?= $base_path; ?>abm/novedades.php" class="sidebar-btn"><i class="bi bi-newspaper me-2"></i>Novedades</a>
                <a href="<?= $base_path; ?>abm/profesores.php" class="sidebar-btn"><i class="bi bi-person-badge me-2"></i>Profesores</a>
                <a href="<?= $base_path; ?>abm/subcategorias.php" class="sidebar-btn"><i class="bi bi-tags me-2"></i>Subcategorías</a>
                <a href="<?= $base_path; ?>abm/alumnos.php" class="sidebar-btn"><i class="bi bi-people me-2"></i>Alumnos</a>
                <a href="<?= $base_path; ?>abm/reservas.php" class="sidebar-btn"><i class="bi bi-card-checklist me-2"></i>Ver reservas</a>
            <?php endif; ?>

            <!-- Botones del PROFESOR -->
            <?php if (has_role('Profesor')): ?>
                <a href="<?= $base_path; ?>abm/profesor_clases.php" class="sidebar-btn"><i class="bi bi-journal-check me-2"></i>Mis clases</a>
                <a href="<?= $base_path; ?>abm/profesor_espera.php" class="sidebar-btn"><i class="bi bi-hourglass-split me-2"></i>Lista de espera</a>
                <a href="<?= $base_path; ?>abm/profesor_promocion.php" class="sidebar-btn"><i class="bi bi-arrow-up-circle me-2"></i>Promoción</a>
            <?php endif; ?>

            <!-- Botones del ALUMNO (Usuario Común) -->
            <?php if (has_role('Alumno')): ?>
                <a href="<?= $base_path; ?>abm/alumno_inscripcion.php" class="sidebar-btn"><i class="bi bi-file-earmark-plus me-2"></i>Inscribirse a Clases</a>
                <a href="<?= $base_path; ?>perfil.php#mis-clases" class="sidebar-btn"><i class="bi bi-journal-check me-2"></i>Mis Clases</a>
                <a href="<?= $base_path; ?>canchas.php" class="sidebar-btn"><i class="bi bi-calendar-event me-2"></i>Reservar Cancha</a>
                <a href="<?= $base_path; ?>perfil.php#mis-reservas" class="sidebar-btn"><i class="bi bi-ticket-perforated me-2"></i>Mis Reservas</a>
            <?php endif; ?>
        </div>

        <!-- Botón de Cerrar Sesión dentro del Panel -->
        <div class="sidebar-logout-wrapper mt-4 pt-3 border-top" style="border-color: rgba(19, 38, 68, 0.15) !important;">
            <a href="<?= $base_path; ?>index.php?action=logout" class="sidebar-btn sidebar-btn-logout">
                <i class="bi bi-box-arrow-right me-2 fs-5"></i>Cerrar sesión
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Main Content Wrapper -->
    <main class="flex-shrink-0 container py-4">
    <?php 
    // Capturar la acción de logout desde la URL si existe en cualquier header
    if (isset($_GET['action']) && $_GET['action'] == 'logout') {
        logout();
        header("Location: " . $base_path . "index.php");
        exit;
    }
    
    // Alertas de error genéricas
    if (isset($_GET['error']) && $_GET['error'] == 'acceso_denegado'): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> Acceso denegado. No tienes permisos para ingresar a esta sección.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
