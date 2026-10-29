<?php
declare(strict_types=1);

final class NotificacionesEmail
{
    /** @param array<string,mixed> $datos */
    public static function encolar(PDO $pdo, string $clave, string $tipo, int $usuarioId, array $datos, ?int $reservaId=null, ?string $programadaEn=null, bool $requiereConfirmada=false): void
    {
        $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stmt = $pdo->prepare('INSERT INTO notificaciones_email(clave_evento,tipo,fk_usuario,datos,fk_reserva,proximo_intento_en,requiere_reserva_confirmada) VALUES(?,?,?,?,?,COALESCE(?::timestamptz,CURRENT_TIMESTAMP),?) ON CONFLICT(clave_evento) DO NOTHING');
        $stmt->execute([$clave, $tipo, $usuarioId, $json, $reservaId, $programadaEn, $requiereConfirmada ? 1 : 0]);
    }

    /** @param callable(string,string,string,string):bool $enviar */
    public static function despachar(PDO $pdo, callable $enviar, int $maximo=10): int
    {
        $enviadas = 0;
        for ($i=0; $i<$maximo; $i++) {
            $pdo->beginTransaction();
            try {
                $stmt=$pdo->query("SELECT n.*,u.email,u.nombre FROM notificaciones_email n JOIN usuarios u ON u.id=n.fk_usuario
                    WHERE n.estado IN ('pendiente','error','procesando') AND n.proximo_intento_en<=CURRENT_TIMESTAMP
                      AND n.intentos<20
                      AND (n.estado<>'procesando' OR n.bloqueo_hasta<CURRENT_TIMESTAMP)
                    ORDER BY n.creado_en,n.id FOR UPDATE OF n SKIP LOCKED LIMIT 1");
                $n=$stmt->fetch(PDO::FETCH_ASSOC);
                if(!$n){$pdo->commit();break;}
                if($n['requiere_reserva_confirmada']){
                    $vigente=$pdo->prepare("SELECT estado='reservado' AND ((fecha_de_asistencia+horario) AT TIME ZONE 'America/Argentina/Buenos_Aires')>CURRENT_TIMESTAMP FROM reservas WHERE id=?");
                    $vigente->execute([$n['fk_reserva']]);
                    if(!$vigente->fetchColumn()){
                        $pdo->prepare("UPDATE notificaciones_email SET estado='omitida',codigo_error='RESERVA_NO_CONFIRMADA',actualizado_en=clock_timestamp() WHERE id=?")->execute([$n['id']]);
                        $pdo->commit();continue;
                    }
                }
                if(!$n['email'] || !filter_var($n['email'],FILTER_VALIDATE_EMAIL)){
                    $pdo->prepare("UPDATE notificaciones_email SET estado='omitida',codigo_error='DESTINATARIO_INVALIDO',actualizado_en=clock_timestamp() WHERE id=?")->execute([$n['id']]);
                    $pdo->commit();continue;
                }
                $pdo->prepare("UPDATE notificaciones_email SET estado='procesando',intentos=intentos+1,bloqueo_hasta=CURRENT_TIMESTAMP+INTERVAL '60 seconds',codigo_error=NULL,actualizado_en=clock_timestamp() WHERE id=?")->execute([$n['id']]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

            [$asunto,$html]=self::render((string)$n['tipo'],json_decode((string)$n['datos'],true,512,JSON_THROW_ON_ERROR),(string)$n['nombre']);
            $ok=$enviar((string)$n['email'],(string)$n['nombre'],$asunto,$html,(string)$n['clave_evento']);
            if($ok){
                $pdo->prepare("UPDATE notificaciones_email SET estado='enviada',enviado_en=clock_timestamp(),bloqueo_hasta=NULL,codigo_error=NULL,actualizado_en=clock_timestamp() WHERE id=? AND estado='procesando'")->execute([$n['id']]);
                $enviadas++;
            }else{
                $pdo->prepare("UPDATE notificaciones_email SET estado=CASE WHEN intentos>=20 THEN 'omitida' ELSE 'error' END,proximo_intento_en=CURRENT_TIMESTAMP+INTERVAL '5 minutes',bloqueo_hasta=NULL,codigo_error=CASE WHEN intentos>=20 THEN 'REINTENTOS_AGOTADOS' ELSE 'PROVEEDOR_NO_ACEPTO' END,actualizado_en=clock_timestamp() WHERE id=? AND estado='procesando'")->execute([$n['id']]);
            }
        }
        return $enviadas;
    }

    /** @param array<string,mixed> $d @return array{string,string} */
    private static function render(string $tipo,array $d,string $nombre): array
    {
        $e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $titulo='Notificación de MultiPro';$texto='Hay una actualización en tu cuenta.';
        switch($tipo){
            case 'promocion_modulo':
                $titulo='Cambio de módulo — MultiPro';
                $texto='Tu inscripción cambió de <strong>'.$e($d['modulo_anterior']??'').'</strong> a <strong>'.$e($d['modulo_nuevo']??'').'</strong>.';break;
            case 'reserva_presencial_creada':
                $titulo='Reserva presencial pendiente — MultiPro';
                $texto='Registramos tu reserva de <strong>'.$e($d['espacio']??'').'</strong> para el '.$e($d['fecha']??'').' a las '.$e($d['hora']??'').' hs. Importe final: <strong>'.$e($d['importe']??'').'</strong>. Aboná en la entidad para confirmarla.';break;
            case 'reserva_gratuita_creada':
                $titulo='Reserva gratuita confirmada — MultiPro';
                $texto='Tu reserva gratuita de <strong>'.$e($d['espacio']??'').'</strong> quedó confirmada para el '.$e($d['fecha']??'').' a las '.$e($d['hora']??'').' hs.';break;
            case 'cobro_presencial_registrado':
                $titulo='Pago presencial registrado — MultiPro';
                $texto='La entidad registró el pago de tu reserva de <strong>'.$e($d['espacio']??'').'</strong> por <strong>'.$e($d['importe']??'').'</strong>.';break;
            case 'reserva_cancelada':
                $titulo='Reserva cancelada — MultiPro';
                $texto='Tu reserva de <strong>'.$e($d['espacio']??'').'</strong> del '.$e($d['fecha']??'').' a las '.$e($d['hora']??'').' hs fue cancelada.';break;
            case 'devolucion_pendiente':
                $titulo='Devolución pendiente — MultiPro';
                $texto='La cancelación generó una devolución total pendiente de <strong>'.$e($d['importe']??'').'</strong>. La entidad debe realizarla y registrar su entrega.';break;
            case 'devolucion_realizada':
                $titulo='Devolución registrada — MultiPro';
                $texto='La entidad registró la devolución total de <strong>'.$e($d['importe']??'').'</strong> correspondiente a tu reserva cancelada.';break;
            case 'recordatorio_turno':
                $titulo='Recordatorio de tu turno — MultiPro';
                $texto='Te recordamos que mañana tenés reservado <strong>'.$e($d['espacio']??'').'</strong> el '.$e($d['fecha']??'').' a las '.$e($d['hora']??'').' hs en '.$e($d['entidad']??'').'.';break;
        }
        $html="<!doctype html><html lang='es'><body style='font-family:Arial,sans-serif;background:#f4f6f8;padding:24px'><div style='max-width:560px;margin:auto;background:#fff;border-radius:12px;overflow:hidden'><div style='background:#132644;color:#fff;padding:24px'><strong style='color:#c5d852;font-size:24px'>MultiPro</strong></div><div style='padding:28px'><h2 style='color:#132644'>".$e($titulo)."</h2><p>Hola, ".$e($nombre).".</p><p style='line-height:1.6'>".$texto."</p><p style='color:#718096;font-size:13px'>Este es un mensaje transaccional de MultiPro.</p></div></div></body></html>";
        return [$titulo,$html];
    }
}

function notificacionesDespacharSilencioso(PDO $pdo,int $maximo=10): void
{
    require_once __DIR__.'/mailer.php';
    if(!email_habilitado())return;
    try{NotificacionesEmail::despachar($pdo,'send_email',$maximo);}
    catch(Throwable $e){error_log('Notificaciones MultiPro: despacho pendiente');}
}
