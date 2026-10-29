<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/includes/db.php';
require_once dirname(__DIR__).'/includes/NotificacionesEmail.php';

$sql="SELECT r.id,r.fk_usuario,r.fecha_de_asistencia,to_char(r.horario,'HH24:MI') hora,
    ((r.fecha_de_asistencia+r.horario) AT TIME ZONE 'America/Argentina/Buenos_Aires')-INTERVAL '24 hours' programada,
    e.nombre espacio,en.nombre entidad
    FROM reservas r JOIN espacios e ON e.id=r.fk_espacio JOIN entidades en ON en.id=e.fk_entidad
    WHERE r.estado='reservado' AND ((r.fecha_de_asistencia+r.horario) AT TIME ZONE 'America/Argentina/Buenos_Aires')>CURRENT_TIMESTAMP";
foreach($pdo->query($sql,PDO::FETCH_ASSOC) as $r){
    NotificacionesEmail::encolar($pdo,'reserva:'.$r['id'].':recordatorio_24h','recordatorio_turno',(int)$r['fk_usuario'],[
        'fecha'=>(new DateTimeImmutable($r['fecha_de_asistencia']))->format('d/m/Y'),'hora'=>$r['hora'],
        'espacio'=>$r['espacio'],'entidad'=>$r['entidad']],(int)$r['id'],(string)$r['programada'],true);
}
notificacionesDespacharSilencioso($pdo,100);
echo "MULTIPRO_NOTIFICACIONES_OK\n";
