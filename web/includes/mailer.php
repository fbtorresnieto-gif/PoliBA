<?php
// Helper centralizado de envío de emails — MultiPro
// Utiliza la API HTTP de Resend (Railway bloquea SMTP).

/**
 * Envía un email usando la API de Resend.
 *
 * Variables de entorno:
 *   RESEND_API_KEY  API key de Resend (re_...)
 *   MAIL_FROM       Remitente (ej: onboarding@resend.dev o noreply@tudominio.com)
 *   MAIL_FROM_NAME  Nombre visible del remitente
 *
 * @param string $to_email    Destinatario
 * @param string $to_name     Nombre del destinatario
 * @param string $subject     Asunto
 * @param string $body_html   Cuerpo HTML del mail
 * @return bool               True si se envió, false si falló
 */
function send_email(string $to_email, string $to_name, string $subject, string $body_html): bool {
    $api_key   = getenv('RESEND_API_KEY') ?: '';
    $from      = getenv('MAIL_FROM')      ?: 'onboarding@resend.dev';
    $from_name = getenv('MAIL_FROM_NAME') ?: 'MultiPro';

    if ($api_key === '') {
        error_log('Resend error: falta RESEND_API_KEY');
        return false;
    }

    $payload = json_encode([
        'from'    => "{$from_name} <{$from}>",
        'to'      => [$to_email],
        'subject' => $subject,
        'html'    => $body_html,
        'text'    => strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body_html)),
    ]);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        error_log("Resend error (HTTP {$status}): {$err} {$response}");
        return false;
    }
    return true;
}

/**
 * Construye el HTML del email de recuperación de contraseña.
 *
 * @param string $nombre   Nombre del usuario
 * @param string $link     Link completo con el token
 * @return string          HTML del email
 */
function build_reset_email(string $nombre, string $link): string {
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
