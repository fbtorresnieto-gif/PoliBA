<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/google_config.php';

function apiGoogleBase64UrlDecode(string $value): string|false
{
    $padding = strlen($value) % 4;
    if ($padding !== 0) $value .= str_repeat('=', 4 - $padding);
    return base64_decode(strtr($value, '-_', '+/'), true);
}

/** @return array<string, mixed> */
function apiVerificarGoogleIdToken(string $idToken): array
{
    if ($idToken === '' || strlen($idToken) > 16384) {
        apiResponder(['error' => 'La credencial de Google no es válida.'], 401);
    }
    $partes = explode('.', $idToken);
    if (count($partes) !== 3) apiResponder(['error' => 'La credencial de Google no es válida.'], 401);
    [$header64, $payload64, $firma64] = $partes;
    $headerJson = apiGoogleBase64UrlDecode($header64);
    $payloadJson = apiGoogleBase64UrlDecode($payload64);
    $firma = apiGoogleBase64UrlDecode($firma64);
    if ($headerJson === false || $payloadJson === false || $firma === false) {
        apiResponder(['error' => 'La credencial de Google no es válida.'], 401);
    }
    try {
        $header = json_decode($headerJson, true, 32, JSON_THROW_ON_ERROR);
        $payload = json_decode($payloadJson, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        apiResponder(['error' => 'La credencial de Google no es válida.'], 401);
    }
    if (!is_array($header) || !is_array($payload) || ($header['alg'] ?? '') !== 'RS256'
            || !is_string($header['kid'] ?? null)) {
        apiResponder(['error' => 'La credencial de Google no es válida.'], 401);
    }

    $curl = curl_init('https://www.googleapis.com/oauth2/v1/certs');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $certificadosJson = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($certificadosJson) || $status !== 200) {
        apiResponder(['error' => 'No pudimos validar la cuenta de Google en este momento.'], 503);
    }
    try {
        $certificados = json_decode($certificadosJson, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        apiResponder(['error' => 'No pudimos validar la cuenta de Google en este momento.'], 503);
    }
    $certificado = is_array($certificados) ? ($certificados[$header['kid']] ?? null) : null;
    if (!is_string($certificado)
            || openssl_verify($header64 . '.' . $payload64, $firma, $certificado, OPENSSL_ALGO_SHA256) !== 1) {
        apiResponder(['error' => 'La credencial de Google no es válida.'], 401);
    }

    $clientIdEsperado = trim((string) (getenv('GOOGLE_ANDROID_WEB_CLIENT_ID') ?: GOOGLE_CLIENT_ID));
    $ahora = time();
    $emailVerificado = ($payload['email_verified'] ?? false) === true
        || ($payload['email_verified'] ?? '') === 'true';
    if ($clientIdEsperado === '' || ($payload['aud'] ?? '') !== $clientIdEsperado
            || !in_array(($payload['iss'] ?? ''), ['accounts.google.com', 'https://accounts.google.com'], true)
            || (int) ($payload['exp'] ?? 0) <= $ahora
            || (int) ($payload['iat'] ?? 0) > $ahora + 60
            || !$emailVerificado || !filter_var(($payload['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        apiResponder(['error' => 'La credencial de Google no es válida.'], 401);
    }
    return $payload;
}

