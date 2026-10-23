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
    
    // Si la sesión conserva el contrato anterior, recargar el usuario desde la DB.
    if (isset($_SESSION['user_data'])) {
        if (!array_key_exists('fk_entidad', $_SESSION['user_data'])) {
            unset($_SESSION['user_data'], $_SESSION['user_polideportivo']);
        } else {
            return $_SESSION['user_data'];
        }
    }
    
    // Si no, buscarlo en la DB
    try {
        $stmt = $pdo->prepare("
            SELECT u.*, r.nombre as rol_nombre, e.nombre as fk_entidad_nombre
            FROM usuarios u
            JOIN roles r ON u.fk_rol = r.id
            LEFT JOIN entidades e ON u.fk_entidad = e.id
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

    // Si el usuario fue desactivado mientras tenía la sesión abierta, cerrarla.
    global $pdo;
    try {
        $stmt_estado = $pdo->prepare("SELECT estado FROM usuarios WHERE id = ?");
        $stmt_estado->execute([$_SESSION['user_id']]);
        $estado_actual = $stmt_estado->fetchColumn();
        if ($estado_actual === false || !$estado_actual) {
            session_unset();
            session_destroy();
            header("Location: $login_url?error=" . urlencode('Tu cuenta está desactivada. Contactate con el Gestor.'));
            exit;
        }
    } catch (PDOException $e) {
        // Si falla la consulta, no bloquear la navegación.
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
            SELECT u.*, r.nombre as rol_nombre, e.nombre as fk_entidad_nombre
            FROM usuarios u
            JOIN roles r ON u.fk_rol = r.id
            LEFT JOIN entidades e ON u.fk_entidad = e.id
            WHERE u.email = ?
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['contrasena'])) {
            if (empty($user['estado'])) {
                $GLOBALS['login_cuenta_inactiva'] = true;
                return false;
            }
            // Guardar datos mínimos en sesión
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_nombre'] = $user['nombre'];
            $_SESSION['user_apellido'] = $user['apellido'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_rol'] = $user['rol_nombre'];
            unset($_SESSION['user_polideportivo']);
            $_SESSION['user_entidad'] = $user['fk_entidad'];
            
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
function register_alumno($nombre, $apellido, $dni, $direccion, $email, $password, $telefono, $fecha_nacimiento, $entidad_id = null) {
    global $pdo;
    try {
        // Buscar el ID del rol 'Alumno'
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE nombre = 'Alumno' LIMIT 1");
        $stmt->execute();
        $rol = $stmt->fetch();
        $rol_id = $rol ? $rol['id'] : 4; // fallback a 4 si no está
        
        $hash = password_hash($password, PASSWORD_BCRYPT);
        
        $stmt = $pdo->prepare("
            INSERT INTO usuarios (nombre, apellido, dni, direccion, email, contrasena, telefono, fecha_nacimiento, fk_entidad, fk_rol)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([
            $nombre, $apellido, $dni, $direccion, $email, $hash, $telefono, $fecha_nacimiento, $entidad_id, $rol_id
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
