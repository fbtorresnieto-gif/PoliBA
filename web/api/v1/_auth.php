<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

/** @return array<string, mixed> */
function apiLeerJson(): array
{
    $contenido = file_get_contents('php://input');
    if ($contenido === false || trim($contenido) === '') {
        apiResponder(['error' => 'El cuerpo JSON es obligatorio.'], 400);
    }

    try {
        $datos = json_decode($contenido, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        apiResponder(['error' => 'El cuerpo de la solicitud no contiene JSON válido.'], 400);
    }

    if (!is_array($datos)) {
        apiResponder(['error' => 'El cuerpo JSON debe ser un objeto.'], 400);
    }

    return $datos;
}

function apiTokenBearer(): string
{
    $cabecera = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $cabecera, $coincidencias) !== 1) {
        apiResponder(['error' => 'Autenticación requerida.'], 401);
    }

    return strtolower($coincidencias[1]);
}

/** @return array{token:string,vence_en:string} */
function apiCrearSesion(PDO $pdo, int $usuarioId): array
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare("
        INSERT INTO api_sesiones (fk_usuario, token_hash, vence_en)
        VALUES (?, ?, CURRENT_TIMESTAMP + INTERVAL '30 days')
        RETURNING vence_en
    ");
    $stmt->execute([$usuarioId, $hash]);

    return ['token' => $token, 'vence_en' => (string) $stmt->fetchColumn()];
}

/** @return array<string, mixed> */
function apiUsuarioAutenticado(PDO $pdo, ?string $token = null): array
{
    $token ??= apiTokenBearer();
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombre, u.apellido, u.dni, u.direccion, u.email,
               u.telefono, u.fecha_nacimiento, u.fk_entidad,
               r.nombre AS rol_nombre, e.nombre AS entidad_nombre
        FROM api_sesiones s
        JOIN usuarios u ON s.fk_usuario = u.id
        JOIN roles r ON u.fk_rol = r.id
        LEFT JOIN entidades e ON u.fk_entidad = e.id
        WHERE s.token_hash = ?
          AND s.revocado_en IS NULL
          AND s.vence_en > CURRENT_TIMESTAMP
        LIMIT 1
    ");
    $stmt->execute([hash('sha256', $token)]);
    $usuario = $stmt->fetch();

    if (!$usuario || !in_array(strtolower((string) $usuario['rol_nombre']), ['alumno', 'profesor'], true)) {
        apiResponder(['error' => 'La sesión no es válida o ha vencido.'], 401);
    }

    $usuario['id'] = (int) $usuario['id'];
    $usuario['fk_entidad'] = $usuario['fk_entidad'] === null ? null : (int) $usuario['fk_entidad'];
    return $usuario;
}

/** @return array<string, mixed> */
function apiExigirRol(PDO $pdo, string $rol): array
{
    $usuario = apiUsuarioAutenticado($pdo);
    if (strtolower((string) $usuario['rol_nombre']) !== strtolower($rol)) {
        apiResponder(['error' => 'No tenés permiso para realizar esta acción.'], 403);
    }
    return $usuario;
}

/** @return array{0:PDO,1:array<string,mixed>} */
function apiContextoAutenticado(?string $rol = null): array
{
    $token = apiTokenBearer();
    $pdo = apiBaseDatos();
    $usuario = apiUsuarioAutenticado($pdo, $token);
    if ($rol !== null && strtolower((string) $usuario['rol_nombre']) !== strtolower($rol)) {
        apiResponder(['error' => 'No tenés permiso para realizar esta acción.'], 403);
    }
    return [$pdo, $usuario];
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    apiResponder(['error' => 'Recurso no encontrado.'], 404);
}
