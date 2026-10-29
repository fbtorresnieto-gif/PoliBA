<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/web/includes/NotificacionesEmail.php';
require dirname(__DIR__,2).'/web/includes/ServicioPromociones.php';

function nassert(bool $ok,string $mensaje):void{if(!$ok)throw new RuntimeException($mensaje);}
function nq(PDO $p,string $sql,array $params=[]):PDOStatement{$s=$p->prepare($sql);$s->execute($params);return $s;}
$pdo=new PDO(sprintf('pgsql:host=127.0.0.1;port=5433;dbname=%s;sslmode=disable',getenv('MULTIPRO_TEST_DB')),
    (string)getenv('MULTIPRO_TEST_USER'),(string)getenv('MULTIPRO_TEST_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

$usuario=(int)$pdo->query("SELECT id FROM usuarios WHERE estado AND email IS NOT NULL ORDER BY id LIMIT 1")->fetchColumn();
$capturas=[];$fake=static function(string $email,string $nombre,string $asunto,string $html)use(&$capturas):bool{
    $capturas[]=compact('email','nombre','asunto','html');return true;};
NotificacionesEmail::encolar($pdo,'prueba:idempotente','reserva_cancelada',$usuario,['espacio'=>'<script>x</script>','fecha'=>'01/01/2099','hora'=>'10:00']);
NotificacionesEmail::encolar($pdo,'prueba:idempotente','reserva_cancelada',$usuario,['espacio'=>'otro']);
nassert((int)nq($pdo,"SELECT count(*) FROM notificaciones_email WHERE clave_evento='prueba:idempotente'")->fetchColumn()===1,'OUTBOX_NO_IDEMPOTENTE');
$enviadas=NotificacionesEmail::despachar($pdo,$fake,100);
nassert($enviadas>0,'OUTBOX_NO_DESPACHA');
nassert(count($capturas)===$enviadas,'OUTBOX_CANTIDAD_ENVIO');
foreach($capturas as $c)nassert(!str_contains($c['html'],'<script>x</script>'),'OUTBOX_HTML_NO_ESCAPA');
nassert((int)nq($pdo,"SELECT count(*) FROM notificaciones_email WHERE estado='enviada' AND enviado_en IS NOT NULL")->fetchColumn()===$enviadas,'OUTBOX_ESTADO_ENVIADA');

NotificacionesEmail::encolar($pdo,'prueba:reintento','reserva_cancelada',$usuario,['espacio'=>'Cancha','fecha'=>'01/01/2099','hora'=>'10:00']);
NotificacionesEmail::despachar($pdo,static fn():bool=>false,1);
$error=nq($pdo,"SELECT estado,intentos,codigo_error FROM notificaciones_email WHERE clave_evento='prueba:reintento'")->fetch();
nassert($error['estado']==='error'&&(int)$error['intentos']===1&&$error['codigo_error']==='PROVEEDOR_NO_ACEPTO','OUTBOX_ERROR_REINTENTABLE');
nq($pdo,"UPDATE notificaciones_email SET proximo_intento_en=CURRENT_TIMESTAMP-INTERVAL '1 second' WHERE clave_evento='prueba:reintento'");
NotificacionesEmail::despachar($pdo,$fake,1);
nassert(nq($pdo,"SELECT estado FROM notificaciones_email WHERE clave_evento='prueba:reintento'")->fetchColumn()==='enviada','OUTBOX_REINTENTO_NO_ENVIA');

// Fixture aislado de promocion: destinatario y profesor se derivan de la base.
$prof=(int)$pdo->query("SELECT u.id FROM usuarios u JOIN roles r ON r.id=u.fk_rol WHERE lower(r.nombre)='profesor' AND u.estado ORDER BY u.id LIMIT 1")->fetchColumn();
$alumno=(int)$pdo->query("SELECT u.id FROM usuarios u JOIN roles r ON r.id=u.fk_rol WHERE lower(r.nombre)='alumno' AND u.estado ORDER BY u.id LIMIT 1")->fetchColumn();
$base=$pdo->query('SELECT id,fk_entidad,fk_actividad FROM modulos WHERE fk_entidad IS NOT NULL AND fk_actividad IS NOT NULL ORDER BY id LIMIT 1')->fetch();
nassert($prof>0&&$alumno>0&&(bool)$base,'FALTAN_FIXTURES_PROMOCION');
nq($pdo,'UPDATE usuarios SET fecha_nacimiento=? WHERE id=?',['2000-01-01',$alumno]);
$actual=(int)nq($pdo,"INSERT INTO modulos(nombre,descripcion,horario_inicio,horario_cierre,cupo_maximo,fk_usuario_profesor,fk_actividad,fk_entidad,estado) VALUES('Notif actual','Fixture','08:00','09:00',10,?,?,?,TRUE) RETURNING id",[$prof,$base['fk_actividad'],$base['fk_entidad']])->fetchColumn();
$nuevo=(int)nq($pdo,"INSERT INTO modulos(nombre,descripcion,horario_inicio,horario_cierre,cupo_maximo,fk_usuario_profesor,fk_actividad,fk_entidad,estado) VALUES('Notif nuevo','Fixture','09:00','10:00',10,?,?,?,TRUE) RETURNING id",[$prof,$base['fk_actividad'],$base['fk_entidad']])->fetchColumn();
$ins=(int)nq($pdo,"INSERT INTO inscripcion(fk_modulo,fk_usuario,estado) VALUES(?,?,'activo') RETURNING id",[$actual,$alumno])->fetchColumn();
$res=(new ServicioPromociones($pdo))->promocionar($ins,$nuevo,$prof);
nassert($res['modulo_nuevo']==='Notif nuevo','PROMOCION_RESULTADO');
nassert((int)nq($pdo,'SELECT fk_modulo FROM inscripcion WHERE id=?',[$ins])->fetchColumn()===$nuevo,'PROMOCION_NO_CAMBIA');
$audit=(int)nq($pdo,'SELECT id FROM inscripcion_promociones WHERE fk_inscripcion=?',[$ins])->fetchColumn();
nassert($audit>0,'PROMOCION_SIN_AUDITORIA');
$notif=nq($pdo,"SELECT fk_usuario,tipo FROM notificaciones_email WHERE clave_evento=?",['promocion:'.$audit])->fetch();
nassert((int)$notif['fk_usuario']===$alumno&&$notif['tipo']==='promocion_modulo','PROMOCION_DESTINATARIO_SERVIDOR');
$rechazo=false;try{(new ServicioPromociones($pdo))->promocionar($ins,$actual,$alumno);}catch(RuntimeException){$rechazo=true;}
nassert($rechazo,'PROMOCION_ACTOR_MANIPULADO');

// Recordatorio: solo se envia cuando vence y la reserva sigue confirmada.
$espacio=(int)$pdo->query('SELECT id FROM espacios WHERE estado ORDER BY id LIMIT 1')->fetchColumn();
$rid=(int)nq($pdo,"INSERT INTO reservas(fk_espacio,fk_usuario,fecha_de_asistencia,horario,estado,modalidad_pago,importe_total_ars) VALUES(?,?,CURRENT_DATE+3,'10:00','reservado','gratuito',0) RETURNING id",[$espacio,$usuario])->fetchColumn();
NotificacionesEmail::encolar($pdo,'reserva:'.$rid.':recordatorio_24h','recordatorio_turno',$usuario,['espacio'=>'Cancha','entidad'=>'Entidad','fecha'=>'31/12/2099','hora'=>'10:00'],$rid,(new DateTimeImmutable('+1 hour'))->format(DATE_ATOM),true);
NotificacionesEmail::despachar($pdo,$fake,100);
nassert(nq($pdo,'SELECT estado FROM notificaciones_email WHERE fk_reserva=?',[$rid])->fetchColumn()==='pendiente','RECORDATORIO_ANTES_DE_TIEMPO');
nq($pdo,'UPDATE notificaciones_email SET proximo_intento_en=CURRENT_TIMESTAMP WHERE fk_reserva=?',[$rid]);
NotificacionesEmail::despachar($pdo,$fake,10);
nassert(nq($pdo,'SELECT estado FROM notificaciones_email WHERE fk_reserva=?',[$rid])->fetchColumn()==='enviada','RECORDATORIO_CONFIRMADO_NO_ENVIA');
$rid2=(int)nq($pdo,"INSERT INTO reservas(fk_espacio,fk_usuario,fecha_de_asistencia,horario,estado,modalidad_pago,importe_total_ars) VALUES(?,?,CURRENT_DATE+4,'11:00','reservado','gratuito',0) RETURNING id",[$espacio,$usuario])->fetchColumn();
nq($pdo,"UPDATE reservas SET estado='cancelado',cancelado_en=CURRENT_TIMESTAMP,cancelacion_origen='usuario',cancelado_por_usuario=?,motivo_cancelacion='Fixture recordatorio cancelado' WHERE id=?",[$usuario,$rid2]);
NotificacionesEmail::encolar($pdo,'reserva:'.$rid2.':recordatorio_24h','recordatorio_turno',$usuario,['espacio'=>'Cancha','entidad'=>'Entidad','fecha'=>'31/12/2099','hora'=>'11:00'],$rid2,(new DateTimeImmutable('-1 minute'))->format(DATE_ATOM),true);
NotificacionesEmail::despachar($pdo,$fake,10);
nassert(nq($pdo,'SELECT estado FROM notificaciones_email WHERE fk_reserva=?',[$rid2])->fetchColumn()==='omitida','RECORDATORIO_CANCELADO_NO_OMITE');

echo "PRUEBAS_NOTIFICACIONES_LOCAL_OK\n";
