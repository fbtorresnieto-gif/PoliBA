<?php
declare(strict_types=1);

final class RecuperacionContrasena
{
    private PDO $pdo;
    /** @var callable(string,string,string,string):bool */
    private $enviar;

    /** @param callable(string,string,string,string):bool $enviar */
    public function __construct(PDO $pdo, callable $enviar)
    {
        $this->pdo = $pdo;
        $this->enviar = $enviar;
    }

    public function solicitar(string $email): void
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Ingresá un email válido.');
        }

        $stmt = $this->pdo->prepare("INSERT INTO password_reset_solicitudes(email_normalizado,email_hash)
            VALUES(?,?) ON CONFLICT(email_hash) WHERE estado IN ('pendiente','procesando','error') DO NOTHING");
        $stmt->execute([$email, hash('sha256', $email)]);
    }

    public function despacharSolicitudes(string $baseUrl, int $maximo = 10): int
    {
        $procesadas = 0;
        for ($i = 0; $i < $maximo; $i++) {
            $this->pdo->beginTransaction();
            try {
                $stmt = $this->pdo->query("SELECT * FROM password_reset_solicitudes
                    WHERE estado IN ('pendiente','error','procesando')
                      AND proximo_intento_en<=CURRENT_TIMESTAMP AND intentos<5
                      AND (estado<>'procesando' OR bloqueo_hasta<CURRENT_TIMESTAMP)
                    ORDER BY creado_en,id FOR UPDATE SKIP LOCKED LIMIT 1");
                $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$solicitud) {
                    $this->pdo->commit();
                    break;
                }
                $this->pdo->prepare("UPDATE password_reset_solicitudes SET estado='procesando',intentos=intentos+1,
                    bloqueo_hasta=CURRENT_TIMESTAMP+INTERVAL '60 seconds',codigo_error=NULL,actualizado_en=clock_timestamp()
                    WHERE id=?")->execute([$solicitud['id']]);
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $e;
            }

            try {
                $resultado = $this->procesarSolicitud((string) $solicitud['email_normalizado'], $baseUrl);
            } catch (Throwable $e) {
                $resultado = 'error';
            }

            if ($resultado === 'error') {
                $this->pdo->prepare("UPDATE password_reset_solicitudes SET
                    estado=CASE WHEN intentos>=5 THEN 'omitida' ELSE 'error' END,
                    proximo_intento_en=CURRENT_TIMESTAMP+INTERVAL '5 minutes',bloqueo_hasta=NULL,
                    codigo_error=CASE WHEN intentos>=5 THEN 'REINTENTOS_AGOTADOS' ELSE 'ENVIO_NO_ACEPTADO' END,
                    actualizado_en=clock_timestamp() WHERE id=? AND estado='procesando'")
                    ->execute([$solicitud['id']]);
            } else {
                $this->pdo->prepare("UPDATE password_reset_solicitudes SET estado=?,procesado_en=clock_timestamp(),
                    bloqueo_hasta=NULL,codigo_error=NULL,actualizado_en=clock_timestamp()
                    WHERE id=? AND estado='procesando'")->execute([$resultado, $solicitud['id']]);
                $procesadas++;
            }
        }
        return $procesadas;
    }

    private function procesarSolicitud(string $email, string $baseUrl): string
    {

        $stmt = $this->pdo->prepare('SELECT id,nombre,email FROM usuarios WHERE lower(email)=? AND estado=TRUE LIMIT 1');
        $stmt->execute([$email]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$usuario) {
            return 'omitida';
        }

        $usuarioId = (int) $usuario['id'];
        $this->pdo->beginTransaction();
        try {
            $bloqueo = $this->pdo->prepare('SELECT pg_advisory_xact_lock(28005, ?)');
            $bloqueo->execute([$usuarioId]);

            $limite = $this->pdo->prepare("SELECT count(*) FROM password_resets WHERE fk_usuario=? AND created_at>CURRENT_TIMESTAMP-INTERVAL '1 hour'");
            $limite->execute([$usuarioId]);
            if ((int) $limite->fetchColumn() >= 5) {
                $this->pdo->commit();
                return 'omitida';
            }

            $this->pdo->prepare('UPDATE password_resets SET usado_en=COALESCE(usado_en,CURRENT_TIMESTAMP) WHERE fk_usuario=? AND usado_en IS NULL')
                ->execute([$usuarioId]);

            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $insert = $this->pdo->prepare("INSERT INTO password_resets(fk_usuario,token_hash,expires_at) VALUES(?,?,CURRENT_TIMESTAMP+INTERVAL '1 hour') RETURNING id");
            $insert->execute([$usuarioId, $hash]);
            $solicitudId = (int) $insert->fetchColumn();
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        $link = rtrim($baseUrl, '/') . '/resetear_contrasena.php?token=' . rawurlencode($token);
        $html = build_reset_email((string) $usuario['nombre'], $link);
        $aceptado = ($this->enviar)((string) $usuario['email'], (string) $usuario['nombre'], 'Restablecé tu contraseña — MultiPro', $html);

        if ($aceptado) {
            $this->pdo->prepare('UPDATE password_resets SET enviado_en=CURRENT_TIMESTAMP WHERE id=? AND usado_en IS NULL')
                ->execute([$solicitudId]);
            return 'procesada';
        } else {
            $this->pdo->prepare('UPDATE password_resets SET usado_en=CURRENT_TIMESTAMP WHERE id=? AND usado_en IS NULL')
                ->execute([$solicitudId]);
            return 'error';
        }
    }

    public function tokenValido(string $token): bool
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) return false;
        $stmt = $this->pdo->prepare("SELECT 1 FROM password_resets pr JOIN usuarios u ON u.id=pr.fk_usuario WHERE pr.token_hash=? AND pr.usado_en IS NULL AND pr.enviado_en IS NOT NULL AND pr.expires_at>CURRENT_TIMESTAMP AND u.estado=TRUE");
        $stmt->execute([hash('sha256', $token)]);
        return (bool) $stmt->fetchColumn();
    }

    public function restablecer(string $token, string $nueva, string $confirmar): bool
    {
        if (strlen($nueva) < 8) throw new InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
        if ($nueva !== $confirmar) throw new InvalidArgumentException('Las contraseñas no coinciden.');
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) return false;

        $hashToken = hash('sha256', $token);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT pr.id,pr.fk_usuario FROM password_resets pr JOIN usuarios u ON u.id=pr.fk_usuario WHERE pr.token_hash=? AND pr.usado_en IS NULL AND pr.enviado_en IS NOT NULL AND pr.expires_at>CURRENT_TIMESTAMP AND u.estado=TRUE FOR UPDATE OF pr");
            $stmt->execute([$hashToken]);
            $reset = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$reset) {
                $this->pdo->rollBack();
                return false;
            }

            $usuarioId = (int) $reset['fk_usuario'];
            $nuevoHash = password_hash($nueva, PASSWORD_DEFAULT);
            if ($nuevoHash === false) throw new RuntimeException('No fue posible proteger la contraseña.');

            $this->pdo->prepare('UPDATE usuarios SET contrasena=? WHERE id=?')->execute([$nuevoHash, $usuarioId]);
            $this->pdo->prepare('UPDATE password_resets SET usado_en=COALESCE(usado_en,CURRENT_TIMESTAMP) WHERE fk_usuario=? AND usado_en IS NULL')->execute([$usuarioId]);
            $this->pdo->prepare('UPDATE api_sesiones SET revocado_en=COALESCE(revocado_en,CURRENT_TIMESTAMP) WHERE fk_usuario=? AND revocado_en IS NULL')->execute([$usuarioId]);
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}

function recuperacionBaseUrl(): ?string
{
    $configurada = trim((string) (getenv('APP_URL') ?: ''));
    if ($configurada !== '' && filter_var($configurada, FILTER_VALIDATE_URL)) {
        return rtrim($configurada, '/');
    }
    $railway = trim((string) (getenv('RAILWAY_PUBLIC_DOMAIN') ?: ''));
    if ($railway !== '' && preg_match('/^[a-z0-9.-]+$/i', $railway) === 1) {
        return 'https://' . rtrim($railway, '/');
    }
    $ambiente = strtolower(trim((string) (getenv('APP_ENV') ?: '')));
    return in_array($ambiente, ['local','development','test'], true) ? 'http://127.0.0.1:8080' : null;
}
