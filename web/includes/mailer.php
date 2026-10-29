<?php
// Helper centralizado de envío de emails — MultiPro
// Utiliza la API HTTPS de Brevo. No usa SMTP ni expone credenciales al cliente.

/**
 * Envía un email transaccional usando la API de Brevo.
 *
 * Variables de entorno:
 *   BREVO_API_KEY   API key privada de Brevo
 *   MAIL_FROM       Remitente verificado en Brevo
 *   MAIL_FROM_NAME  Nombre visible del remitente
 *
 * @param string $to_email    Destinatario
 * @param string $to_name     Nombre del destinatario
 * @param string $subject     Asunto
 * @param string $body_html   Cuerpo HTML del mail
 * @param string $idempotency_key Clave estable opcional para evitar duplicados en Brevo
 * @return bool               True si se envió, false si falló
 */
function send_email(string $to_email, string $to_name, string $subject, string $body_html, string $idempotency_key = ''): bool {
    if (strtolower(trim((string) (getenv('MAIL_ENABLED') ?: 'false'))) !== 'true') {
        return false;
    }
    $api_key   = trim((string) (getenv('BREVO_API_KEY') ?: ''));
    $from      = trim((string) (getenv('MAIL_FROM') ?: ''));
    $from_name = getenv('MAIL_FROM_NAME') ?: 'MultiPro';

    if ($api_key === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        error_log('Brevo no configurado: faltan BREVO_API_KEY o MAIL_FROM valido');
        return false;
    }

    if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        error_log('Brevo rechazo interno: destinatario invalido');
        return false;
    }

    $message = [
        'sender' => ['email' => $from, 'name' => $from_name],
        'to' => [['email' => $to_email, 'name' => $to_name]],
        'subject' => $subject,
        'htmlContent' => $body_html,
        'textContent' => html_entity_decode(
            strip_tags(str_ireplace(['<br>', '<br/>', '<br />'], "\n", $body_html)),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        ),
        'tags' => ['multipro-transaccional'],
    ];
    if ($idempotency_key !== '' && preg_match('/^[A-Za-z0-9:._-]{1,255}$/', $idempotency_key)) {
        $message['headers'] = ['IdempotencyKey' => $idempotency_key];
    }
    $payload = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        error_log('Brevo error interno: no fue posible serializar el mensaje');
        return false;
    }

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'api-key: ' . $api_key,
            'Content-Type: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        // No registrar cuerpos externos: pueden contener datos del mensaje.
        error_log("Brevo error de envio (HTTP {$status}, curl=" . ($err === '' ? 'ninguno' : 'presente') . ')');
        return false;
    }
    return true;
}

function email_habilitado(): bool {
    return strtolower(trim((string) (getenv('MAIL_ENABLED') ?: 'false'))) === 'true';
}

/**
 * Construye el HTML del email de recuperación de contraseña.
 *
 * @param string $nombre   Nombre del usuario
 * @param string $link     Link completo con el token
 * @return string          HTML del email
 */
function build_reset_email(string $nombre, string $link): string {
    $nombre = htmlspecialchars($nombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $link = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return "
    <!DOCTYPE html>
    <html lang='es'>
    <head><meta charset='UTF-8'></head>
    <body style='margin:0; padding:0; background:#f4f6f8; font-family: Arial, sans-serif;'>
        <table width='100%' cellpadding='0' cellspacing='0' style='background:#f4f6f8; padding: 40px 0;'>
            <tr>
                <td align='center'>
                    <table width='560' cellpadding='0' cellspacing='0' style='background:#ffffff; border-radius:12px; overflow:hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08);'>
                        <!-- Header -->
                        <tr>
                            <td style='background:#132644; padding: 32px 40px; text-align:center;'>
                                <h1 style='color:#c5d852; margin:0; font-size:28px; letter-spacing:1px;'>Multi<span style='color:#ffffff;'>Pro</span></h1>
                                <p style='color:#a0b0c8; margin:6px 0 0; font-size:13px;'>Plataforma de Gestión Deportiva</p>
                            </td>
                        </tr>
                        <!-- Body -->
                        <tr>
                            <td style='padding: 40px 40px 32px;'>
                                <h2 style='color:#132644; margin:0 0 12px; font-size:20px;'>Hola, {$nombre} 👋</h2>
                                <p style='color:#4a5568; font-size:15px; line-height:1.6; margin:0 0 24px;'>
                                    Recibimos una solicitud para restablecer la contraseña de tu cuenta en MultiPro.<br>
                                    Hacé clic en el botón de abajo para crear una nueva contraseña:
                                </p>
                                <div style='text-align:center; margin: 32px 0;'>
                                    <a href='{$link}' style='background:#132644; color:#c5d852; text-decoration:none; padding:14px 36px; border-radius:50px; font-weight:bold; font-size:15px; display:inline-block; letter-spacing:0.5px;'>
                                        Restablecer contraseña
                                    </a>
                                </div>
                                <p style='color:#718096; font-size:13px; line-height:1.6; margin:0 0 8px;'>
                                    ⏰ Este enlace expirará en <strong>1 hora</strong>.
                                </p>
                                <p style='color:#718096; font-size:13px; line-height:1.6; margin:0;'>
                                    Si no solicitaste restablecer tu contraseña, podés ignorar este mensaje. Tu cuenta está segura.
                                </p>
                            </td>
                        </tr>
                        <!-- Link fallback -->
                        <tr>
                            <td style='padding: 0 40px 24px;'>
                                <div style='background:#f7fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 18px;'>
                                    <p style='color:#718096; font-size:12px; margin:0 0 6px;'>Si el botón no funciona, copiá este enlace en tu navegador:</p>
                                    <p style='color:#132644; font-size:12px; margin:0; word-break:break-all;'>{$link}</p>
                                </div>
                            </td>
                        </tr>
                        <!-- Footer -->
                        <tr>
                            <td style='background:#f7fafc; border-top:1px solid #e2e8f0; padding:20px 40px; text-align:center;'>
                                <p style='color:#a0aec0; font-size:12px; margin:0;'>© 2026 MultiPro · Plataforma de Gestión Deportiva</p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>
    ";
}
