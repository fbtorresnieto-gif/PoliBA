<?php
// Gestión de Sesiones y Autenticación para PoliBA

if (session_status() == PHP_SESSION_NONE) {
    ob_start();
    session_start();
}

require_once __DIR__ . '/db.php';

// Verificar si el usuario está logueado
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// Obtener datos del usuario logueado
function get_logged_user() {
    global $pdo;
    if (!is_logged_in()) {
        return null;
    }
    
    // Si ya está guardado en sesión completo, retornarlo
    if (isset($_SESSION['user_data'])) {
        return $_SESSION['user_data'];
    }
    
    // Si no, buscarlo en la DB
    try {
        $stmt = $pdo->prepare("
            SELECT u.*, r.nombre as rol_nombre 
            FROM usuarios u 
            JOIN roles r ON u.fk_rol = r.id 
            WHERE u.id = ?
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user) {
            unset($user['contrasena']); // Quitar la clave por seguridad
            $_SESSION['user_data'] = $user;
            return $user;
        }
    } catch (PDOException $e) {
        // En caso de error, retornar lo que haya en sesión básico
    }
    
    return [
        'id' => $_SESSION['user_id'],
        'nombre' => $_SESSION['user_nombre'] ?? 'Usuario',
        'apellido' => $_SESSION['user_apellido'] ?? '',
        'email' => $_SESSION['user_email'] ?? '',
        'rol_nombre' => $_SESSION['user_rol'] ?? 'Alumno'
    ];
}

// Validar que el usuario tenga un rol específico
function has_role($role_name) {
    if (!is_logged_in()) return false;
    $user = get_logged_user();
    return ($user && strtolower($user['rol_nombre']) === strtolower($role_name));
}

// Redireccionar si no está logueado
function redirect_if_not_logged_in($allowed_roles = []) {
    // Detectar si estamos en una subcarpeta (abm/) para armar el redirect correcto
    $script_dir = dirname($_SERVER['SCRIPT_NAME']);
    $is_subfolder = (strpos($script_dir, '/abm') !== false);
    $login_url   = $is_subfolder ? '../login.php' : 'login.php';
    $index_url   = $is_subfolder ? '../index.php'  : 'index.php';

    if (!is_logged_in()) {
        header("Location: $login_url");
        exit;
    }
    
    if (!empty($allowed_roles)) {
        $user = get_logged_user();
        $has_access = false;
        foreach ($allowed_roles as $role) {
            if (strtolower($user['rol_nombre']) === strtolower($role)) {
                $has_access = true;
                break;
            }
        }
        if (!$has_access) {
            header("Location: $index_url?error=acceso_denegado");
            exit;
        }
    }
}

// Lógica de Login convencional
function login($email, $password) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT u.*, r.nombre as rol_nombre 
            FROM usuarios u 
            JOIN roles r ON u.fk_rol = r.id 
            WHERE u.email = ?
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['contrasena'])) {
            // Guardar datos mínimos en sesión
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_nombre'] = $user['nombre'];
            $_SESSION['user_apellido'] = $user['apellido'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_rol'] = $user['rol_nombre'];
            $_SESSION['user_polideportivo'] = $user['fk_polideportivo'];
            
            // Cargar datos completos
            unset($user['contrasena']);
            $_SESSION['user_data'] = $user;
            return true;
        }
    } catch (PDOException $e) {
        // Log error
    }
    return false;
}

// Registro de Alumnos
function register_alumno($nombre, $apellido, $dni, $direccion, $email, $password, $telefono, $fecha_nacimiento, $polideportivo_id = null) {
    global $pdo;
    try {
        // Buscar el ID del rol 'Alumno'
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE nombre = 'Alumno' LIMIT 1");
        $stmt->execute();
        $rol = $stmt->fetch();
        $rol_id = $rol ? $rol['id'] : 4; // fallback a 4 si no está
        
        $hash = password_hash($password, PASSWORD_BCRYPT);
        
        $stmt = $pdo->prepare("
            INSERT INTO usuarios (nombre, apellido, dni, direccion, email, contrasena, telefono, fecha_nacimiento, fk_polideportivo, fk_rol)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([
            $nombre, $apellido, $dni, $direccion, $email, $hash, $telefono, $fecha_nacimiento, $polideportivo_id, $rol_id
        ]);
    } catch (PDOException $e) {
        // En caso de duplicación de DNI o Email saltará excepción
        throw new Exception("El DNI o el correo electrónico ya se encuentran registrados en el sistema.");
    }
}

// Cierre de Sesión
function logout() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
