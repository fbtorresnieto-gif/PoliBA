<?php
// Configuración de Google OAuth 2.0 para PoliBA
// -------------------------------------------------------------
// Podés obtener tu Client ID y Client Secret desde Google Cloud Console:
// https://console.cloud.google.com/apis/credentials
//
// URI de Redirección Autorizada requerida en Google Console:
// http://localhost:8080/google_callback.php  (o http://localhost/PoliBA/web/google_callback.php)

define('GOOGLE_CLIENT_ID', 'TU_CLIENT_ID_DE_GOOGLE.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'TU_CLIENT_SECRET_DE_GOOGLE');

// Generador de la URL de redirección dinámica según el servidor actual
function get_google_redirect_uri() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    
    // Si la carpeta actual incluye subdirectorios
    if (strpos($script_dir, '/abm') !== false) {
        $script_dir = dirname($script_dir);
    }
    
    return $protocol . $host . ($script_dir ? $script_dir : '') . '/google_callback.php';
}

// Generar URL para iniciar sesión con Google
function get_google_auth_url() {
    // Si no está configurado un Client ID real, usar URL de aviso o demostración
    if (GOOGLE_CLIENT_ID === 'TU_CLIENT_ID_DE_GOOGLE.apps.googleusercontent.com' || empty(GOOGLE_CLIENT_ID)) {
        return 'login.php?google_setup_required=1';
    }

    $params = [
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => get_google_redirect_uri(),
        'response_type' => 'code',
        'scope'         => 'email profile',
        'access_type'   => 'online',
        'prompt'        => 'select_account'
    ];

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}
