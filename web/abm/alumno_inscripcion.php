<?php
$base_path = '../';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Validar que sea Alumno
redirect_if_not_logged_in(['Alumno']);

$user = get_logged_user();
$error_msg = '';
$success_msg = '';

$pre_clase_id = isset($_GET['clase_id']) ? intval($_GET['clase_id']) : 0;

// Cargar Clases Activas para el selector
$clases = [];
try {
    $stmt = $pdo->query("
        SELECT c.*, d.nombre as deporte_nombre, p.nombre as polideportivo_nombre,
               COALESCE(sub.edad_minima, cat.edad_minima, 0) as edad_min,
               COALESCE(sub.edad_maxima, cat.edad_maxima, 99) as edad_max
        FROM clases c
        JOIN deportes d ON c.fk_deporte = d.id
        JOIN polideportivos p ON c.fk_polideportivo = p.id
        LEFT JOIN categoria cat ON c.fk_categoria = cat.id
        LEFT JOIN subcategorias sub ON c.fk_subcategoria = sub.id
        WHERE c.estado = TRUE AND p.estado = TRUE
        ORDER BY p.nombre, d.nombre, c.nombre ASC
    ");
    $clases = $stmt->fetchAll();
} catch (PDOException $e) {}

// Cargar Menores del Usuario
$menores = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM menores WHERE fk_usuario = ? ORDER BY nombre, apellido ASC");
    $stmt->execute([$user['id']]);
    $menores = $stmt->fetchAll();
} catch (PDOException $e) {}

// Procesar Inscripción
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'inscribirse') {
    $clase_id = intval($_POST['clase_id']);
    $inscribir_a = $_POST['inscribir_a']; // 'tutor' (el padre) o el ID del menor
    
    // Obtener detalles de la clase y edad límite
    $clase_sel = null;
    foreach ($clases as $c) {
        if ($c['id'] == $clase_id) {
            $clase_sel = $c;
            break;
        }
    }
    
    if (!$clase_sel) {
        $error_msg = 'La clase seleccionada no existe o no está activa.';
    } else {
        // Obtener datos de la persona a inscribir
        $persona_nombre = '';
        $persona_nacimiento = '';
        $fk_usuario = null;
        $fk_menor = null;
        
        if ($inscribir_a == 'tutor') {
            $persona_nombre = $user['nombre'] . ' ' . $user['apellido'];
            $persona_nacimiento = $user['fecha_nacimiento'];
            $fk_usuario = $user['id'];
        } else {
            $menor_id = intval($inscribir_a);
            $menor_sel = null;
            foreach ($menores as $m) {
                if ($m['id'] == $menor_id) {
                    $menor_sel = $m;
                    break;
                }
            }
            if ($menor_sel) {
                $persona_nombre = $menor_sel['nombre'] . ' ' . $menor_sel['apellido'];
                $persona_nacimiento = $menor_sel['fecha_nacimiento'];
                $fk_menor = $menor_sel['id'];
            }
        }
        
        if (empty($persona_nacimiento)) {
            $error_msg = 'La persona seleccionada no tiene cargada su fecha de nacimiento.';
        } else {
            // Calcular edad
            $age = date_diff(date_create($persona_nacimiento), date_create('today'))->y;
            $edad_min = $clase_sel['edad_min'];
            $edad_max = $clase_sel['edad_max'];
            
            // Validar edad
            if ($age < $edad_min || $age > $edad_max) {
                $error_msg = "Error de Inscripción: $persona_nombre tiene $age años. Esta clase está permitida únicamente para edades entre $edad_min y $edad_max años.";
            } else {
                try {
                    // Verificar si ya está inscripto en esta clase
                    $stmt_chk = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM inscripcion 
                        WHERE fk_clase = ? AND (fk_usuario = ? OR fk_menor = ?) AND estado = 'activo'
                    ");
                    $stmt_chk->execute([$clase_id, $fk_usuario, $fk_menor]);
                    $duplicado = $stmt_chk->fetchColumn();
                    
                    if ($duplicado > 0) {
                        $error_msg = "$persona_nombre ya se encuentra inscrito en esta clase.";
                    } else {
                        // Contar cupos activos actuales
                        $stmt_cnt = $pdo->prepare("
                            SELECT COUNT(*) 
                            FROM inscripcion 
                            WHERE fk_clase = ? AND lista_espera = FALSE AND estado = 'activo'
                        ");
                        $stmt_cnt->execute([$clase_id]);
                        $activos = $stmt_cnt->fetchColumn();
                        
                        $lista_espera = $activos >= $clase_sel['cupo_maximo'] ? 1 : 0;
                        
                        // Insertar inscripción
                        $stmt_ins = $pdo->prepare("
                            INSERT INTO inscripcion (fk_clase, fk_usuario, fk_menor, lista_espera, estado)
                            VALUES (?, ?, ?, ?, 'activo')
                        ");
                        // SQLite no soporta boolean directamente, usamos 1 o 0
                        $stmt_ins->execute([$clase_id, $fk_usuario, $fk_menor, $lista_espera]);
                        
                        if ($lista_espera) {
                            $success_msg = "¡Inscripción registrada! Debido a que el cupo está completo, $persona_nombre ha sido ingresado a la <strong>LISTA DE ESPERA</strong> de la clase.";
                        } else {
                            $success_msg = "¡Inscripción completada con éxito! $persona_nombre se ha incorporado a la clase activa.";
                        }
                    }
                } catch (PDOException $e) {
                    $error_msg = 'Error al procesar la inscripción en la base de datos: ' . $e->getMessage();
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="poliba-container-card mt-0">
                <h2 class="section-title text-dark">Inscripción a Clases</h2>
                <p class="text-center text-muted mb-4">Inscribite a clases deportivas o anotá a los menores a tu cargo.</p>
                
                <?php if (!empty($error_msg)): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $error_msg; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($success_msg)): ?>
                    <div class="alert alert-success p-4" role="alert" style="border-radius:12px;">
                        <h4 class="alert-heading fw-bold"><i class="bi bi-check-circle-fill me-2"></i>Inscripción Procesada</h4>
                        <p class="mb-3"><?= $success_msg; ?></p>
                        <hr>
                        <p class="mb-0">Podés ver el estado de la inscripción en <a href="../perfil.php" class="alert-link text-decoration-none">Mi Perfil</a>.</p>
                    </div>
                <?php endif; ?>

                <form action="alumno_inscripcion.php" method="POST">
                    <input type="hidden" name="action" value="inscribirse">
                    
                    <!-- 1. Seleccionar persona a inscribir (Basado en Sitemap elegir cuenta) -->
                    <div class="mb-4">
                        <label class="form-label fw-bold text-dark fs-5">1. ¿Quién se va a inscribir?</label>
                        <div class="row g-3">
                            <!-- Opción 1: Tutor/Usuario principal -->
                            <div class="col-md-6">
                                <div class="border rounded p-3 h-100 hover-shadow" style="cursor:pointer; position:relative;">
                                    <input class="form-check-input position-absolute" type="radio" name="inscribir_a" 
                                           id="radio_tutor" value="tutor" required checked style="top:15px; right:15px;">
                                    <label class="form-check-label w-100 h-100 d-block cursor-pointer" for="radio_tutor">
                                        <div class="fw-bold text-dark fs-5">Mi cuenta</div>
                                        <small class="text-muted d-block mt-1"><?= htmlspecialchars($user['nombre'] . ' ' . $user['apellido']); ?></small>
                                        <small class="text-muted d-block">Edad: <?= date_diff(date_create($user['fecha_nacimiento']), date_create('today'))->y; ?> años</small>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- Opción 2: Menores a cargo -->
                            <?php foreach ($menores as $men): 
                                $men_age = date_diff(date_create($men['fecha_nacimiento']), date_create('today'))->y;
                            ?>
                                <div class="col-md-6">
                                    <div class="border rounded p-3 h-100 hover-shadow" style="cursor:pointer; position:relative;">
                                        <input class="form-check-input position-absolute" type="radio" name="inscribir_a" 
                                               id="radio_menor_<?= $men['id']; ?>" value="<?= $men['id']; ?>" required style="top:15px; right:15px;">
                                        <label class="form-check-label w-100 h-100 d-block cursor-pointer" for="radio_menor_<?= $men['id']; ?>">
                                            <div class="fw-bold text-dark fs-5"><?= htmlspecialchars($men['nombre']); ?> <span class="badge bg-secondary" style="font-size:0.7rem;">Menor</span></div>
                                            <small class="text-muted d-block mt-1">Relación: <?= htmlspecialchars($men['relacion']); ?></small>
                                            <small class="text-muted d-block">Edad: <?= $men_age; ?> años</small>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- 2. Seleccionar la clase -->
                    <div class="mb-4">
                        <label class="form-label fw-bold text-dark fs-5">2. Seleccioná la Clase de Deporte</label>
                        <select name="clase_id" class="form-select rounded-pill px-3" required>
                            <option value="">-- Elegir Clase --</option>
                            <?php foreach ($clases as $cl): 
                                $selected = ($pre_clase_id == $cl['id']) ? 'selected' : '';
                            ?>
                                <option value="<?= $cl['id']; ?>" <?= $selected; ?>>
                                    [<?= htmlspecialchars($cl['polideportivo_nombre']); ?>] <?= htmlspecialchars($cl['deporte_nombre']); ?> - <?= htmlspecialchars($cl['nombre']); ?> (Edades: <?= $cl['edad_min']; ?> a <?= $cl['edad_max']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="text-center mt-5 pt-3 border-top">
                        <button type="submit" class="poliba-btn px-5 py-2 fw-bold text-uppercase fs-5">Inscribirse</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
/* Estilos interactivos locales para las tarjetas de selección de cuenta */
.cursor-pointer {
    cursor: pointer;
}
.hover-shadow {
    transition: all 0.3s ease;
}
.hover-shadow:hover {
    background-color: rgba(197, 216, 82, 0.15);
    border-color: var(--poliba-dark-blue) !important;
}
input[type="radio"]:checked + label {
    color: var(--poliba-dark-blue);
}
input[type="radio"]:checked {
    border-color: var(--poliba-dark-blue);
    background-color: var(--poliba-dark-blue);
}
input[type="radio"]:checked ~ label {
    font-weight: bold;
}
</style>
