<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ob_start();

register_shutdown_function(static function (): void {
    $error = error_get_last();
    $fatal = $error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
    if (http_response_code() < 500 && !$fatal) return;
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Ocurrió un error al procesar la solicitud.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

/** @param array<string, mixed> $payload */
function apiResponder(array $payload, int $status = 200): never
{
    http_response_code($status);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        http_response_code(500);
        echo '{"error":"No fue posible generar la respuesta."}';
        exit;
    }
    echo $json;
    exit;
}

function apiAceptarMetodo(string $metodo): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $metodo) {
        header('Allow: ' . $metodo);
        apiResponder(['error' => 'Método no permitido.'], 405);
    }
}

function apiParametroEntero(string $nombre): int
{
    $valor = $_GET[$nombre] ?? null;
    if (!is_string($valor) || filter_var($valor, FILTER_VALIDATE_INT) === false || (int) $valor < 1) {
        apiResponder(['error' => "El parámetro {$nombre} es obligatorio y debe ser un entero positivo."], 422);
    }
    return (int) $valor;
}

function apiParametroFecha(string $nombre): string
{
    $valor = trim((string) ($_GET[$nombre] ?? ''));
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    $errores = DateTimeImmutable::getLastErrors();
    $valida = $fecha !== false
        && ($errores === false || ($errores['warning_count'] === 0 && $errores['error_count'] === 0))
        && $fecha->format('Y-m-d') === $valor;
    if (!$valida) apiResponder(['error' => "El parámetro {$nombre} debe tener formato YYYY-MM-DD."], 422);
    return $valor;
}

function apiBasePublica(): ?string
{
    $configurada = trim((string) (getenv('APP_URL') ?: ''));
    if ($configurada !== '' && filter_var($configurada, FILTER_VALIDATE_URL)) return rtrim($configurada, '/');
    $railway = trim((string) (getenv('RAILWAY_PUBLIC_DOMAIN') ?: ''));
    if ($railway !== '' && filter_var('https://' . $railway, FILTER_VALIDATE_URL)) return 'https://' . rtrim($railway, '/');
    $proxy = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]);
    $protocolo = in_array($proxy, ['http', 'https'], true) ? $proxy : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $host = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? ''))[0]);
    return $host !== '' && preg_match('/^[a-z0-9.-]+(?::[0-9]+)?$/i', $host) === 1 ? $protocolo . '://' . $host : null;
}

function apiUrlImagen(?string $imagen): ?string
{
    $imagen = trim((string) $imagen);
    if ($imagen === '') return null;
    if (filter_var($imagen, FILTER_VALIDATE_URL)) return $imagen;
    $base = apiBasePublica();
    if ($base === null) return null;
    $ruta = str_replace('\\', '/', ltrim($imagen, '/\\'));
    return $base . '/img/' . implode('/', array_map('rawurlencode', explode('/', $ruta)));
}

function apiBaseDatos(): PDO
{
    require __DIR__ . '/../../includes/db.php';
    return $pdo;
}

function apiErrorInterno(Throwable $error, string $contexto): never
{
    error_log($contexto . ': ' . $error->getMessage());
    apiResponder(['error' => 'Ocurrió un error al procesar la solicitud.'], 500);
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    apiResponder(['error' => 'Recurso no encontrado.'], 404);
}
