<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/google_config.php';

// Verificar que se haya recibido el parámetro 'code' desde Google
if (!isset($_GET['code'])) {
    header("Location: login.php?error=" . urlencode("No se recibió código de autorización de Google."));
    exit;
}

$code = $_GET['code'];
$redirect_uri = get_google_redirect_uri();

// 1. Intercambiar el código por el token de acceso
$token_url = 'https://oauth2.googleapis.com/token';
$post_fields = [
    'code'          => $code,
    'client_id'      => GOOGLE_CLIENT_ID,
    'client_secret'  => GOOGLE_CLIENT_SECRET,
    'redirect_uri'   => $redirect_uri,
    'grant_type'     => 'authorization_code'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $token_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
curl_close($ch);

$token_data = json_decode($response, true);

if (empty($token_data['access_token'])) {
    header("Location: login.php?error=" . urlencode("Error al obtener token de Google. Verificá tus credenciales en google_config.php."));
    exit;
}

$access_token = $token_data['access_token'];

// 2. Obtener los datos del perfil del usuario con el token de acceso
$userinfo_url = 'https://www.googleapis.com/oauth2/v2/userinfo';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $userinfo_url);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$userinfo_response = curl_exec($ch);
curl_close($ch);

$google_user = json_decode($userinfo_response, true);

if (empty($google_user['email'])) {
    header("Location: login.php?error=" . urlencode("No se pudo obtener el correo de la cuenta de Google."));
    exit;
}

$email = trim($google_user['email']);
$nombre = !empty($google_user['given_name']) ? trim($google_user['given_name']) : 'Usuario';
$apellido = !empty($google_user['family_name']) ? trim($google_user['family_name']) : 'Google';

// 3. Buscar el usuario en la base de datos de PoliBA
try {
    $stmt = $pdo->prepare("
        SELECT u.*, r.nombre as rol_nombre 
        FROM usuarios u 
        JOIN roles r ON u.fk_rol = r.id 
        WHERE u.email = ?
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Si el usuario no existe, crearlo automáticamente como Alumno
        $stmt_rol = $pdo->query("SELECT id FROM roles WHERE nombre = 'Alumno' LIMIT 1");
        $rol = $stmt_rol->fetch();
        $rol_id = $rol ? $rol['id'] : 4;
        
        $random_pass = password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT);
        $dummy_dni = 'G-' . rand(10000000, 99999999);
        $fecha_nac = '2000-01-01';

        $stmt_ins = $pdo->prepare("
            INSERT INTO usuarios (nombre, apellido, dni, direccion, email, contrasena, telefono, fecha_nacimiento, fk_polideportivo, fk_rol)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)
        ");
        $stmt_ins->execute([
            $nombre, $apellido, $dummy_dni, 'Registrado con Google', $email, $random_pass, '+54 11 0000 0000', $fecha_nac, $rol_id
        ]);

        // Volver a consultar el usuario recién creado
        $stmt->execute([$email]);
        $user = $stmt->fetch();
    }

    // 4. Iniciar sesión en PoliBA
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_nombre'] = $user['nombre'];
    $_SESSION['user_apellido'] = $user['apellido'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_rol'] = $user['rol_nombre'];
    $_SESSION['user_polideportivo'] = $user['fk_polideportivo'];

    unset($user['contrasena']);
    $_SESSION['user_data'] = $user;

    header("Location: perfil.php?google_success=1");
    exit;

} catch (PDOException $e) {
    header("Location: login.php?error=" . urlencode("Error al iniciar sesión con Google: " . $e->getMessage()));
    exit;
}
