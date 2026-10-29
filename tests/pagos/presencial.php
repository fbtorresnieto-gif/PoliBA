<?php
// Se ejecuta dentro del servidor/base temporales de servicio.php, nunca sobre staging.
$local=new \MultiPro\Pagos\ServicioReservas($p);
$resForm['fecha']='2099-04-04';$resForm['horario']='02:00:00';
$resForm['precio_visto']='0.00';$resForm['modalidad_pago']='gratuito';
$rutaPresencial='espacio.php?id='.$configId.'&fecha=2099-04-04';
$web($rutaPresencial,$resForm,$cookieAlumno);
igual((int)q($admin,"SELECT count(*) FROM reservas WHERE fk_espacio=? AND fecha_de_asistencia='2099-04-04'",[$configId])->fetchColumn(),0,'NO_FALSIFICAR_PRECIO');
$resForm['precio_visto']='2500.75';$resForm['modalidad_pago']='online';$web($rutaPresencial,$resForm,$cookieAlumno);
igual((int)q($admin,"SELECT count(*) FROM reservas WHERE fk_espacio=? AND fecha_de_asistencia='2099-04-04'",[$configId])->fetchColumn(),0,'ONLINE_SIGUE_BLOQUEADO');
$resForm['modalidad_pago']='presencial';
[$status,$html]=$web($rutaPresencial,$resForm,$cookieAlumno);
igual(str_contains($html,'Reserva pendiente de pago presencial'),true,'CREAR_PRESENCIAL_WEB');
$rPres=q($admin,"SELECT * FROM reservas WHERE fk_espacio=? AND fecha_de_asistencia='2099-04-04'",[$configId])->fetch();
if(!$rPres)throw new RuntimeException('PRESENCIAL_NO_CREADA');
$presId=(int)$rPres['id'];
igual($rPres['estado'],'pendiente_presencial','PENDIENTE_CORRECTA');igual($rPres['importe_total_ars'],'2500.75','IMPORTE_CONGELADO');
igual($rPres['pago_vence_en'],null,'SIN_RETENCION_ONLINE');
[$status,$dispHtml]=$web('api/v1/disponibilidad.php?espacio_id='.$configId.'&fecha=2099-04-04',null,$cookieAlumno);
igual($status,200,'DISPONIBILIDAD_API');$disp=json_decode($dispHtml,true);
$turno=array_values(array_filter($disp['data']['slots'],static fn(array $s):bool=>$s['horario']==='02:00:00'));
igual($turno[0]['disponible'],false,'PENDIENTE_OCUPA_EN_API');
$web($rutaPresencial,$resForm,$cookieAlumno);
igual((int)q($admin,"SELECT count(*) FROM reservas WHERE fk_espacio=? AND fecha_de_asistencia='2099-04-04'",[$configId])->fetchColumn(),1,'DOBLE_ENVIO_RESERVA');
igual((int)q($admin,"SELECT count(*) FROM notificaciones_email WHERE clave_evento=?",['reserva:'.$presId.':creada_presencial'])->fetchColumn(),1,'NOTIF_RESERVA_IDEMPOTENTE');
[$status,$perfil]=$web('perfil.php',null,$cookieAlumno);igual(str_contains($perfil,'Pago presencial pendiente'),true,'PERFIL_PENDIENTE');
$baseForm['precio_hora_ars']='2700.99';$web('abm/espacios.php',$baseForm,$cookieAdmin);
igual(q($admin,'SELECT importe_total_ars FROM reservas WHERE id=?',[$presId])->fetchColumn(),'2500.75','TARIFA_NO_REESCRIBE_RESERVA');
rechaza(fn()=>$local->registrarCobro($presId,$alumnoFixture),'COBRO_SOLO_ADMIN');
$adminAjeno=(int)q($admin,"SELECT u.id FROM usuarios u JOIN roles ro ON ro.id=u.fk_rol WHERE lower(ro.nombre)='administrador'
    AND u.fk_entidad<>? AND u.estado LIMIT 1",[$entidadApi])->fetchColumn();
if(!$adminAjeno)throw new RuntimeException('FALTA_ADMIN_AJENO_FIXTURE');
rechaza(fn()=>$local->registrarCobro($presId,$adminAjeno),'COBRO_OTRA_ENTIDAD');
rechaza(fn()=>q($p,"INSERT INTO reserva_movimientos_presenciales(fk_reserva,fk_entidad,tipo,importe_ars,fk_actor) VALUES(?,?,'cobro',2500.75,?)",[$presId,$entidadApi,$adminAjeno]),'DB_ACTOR_ENTIDAD');
rechaza(fn()=>q($p,"INSERT INTO reserva_movimientos_presenciales(fk_reserva,fk_entidad,tipo,importe_ars,fk_actor) VALUES(?,?,'cobro',1,?)",[$presId,$entidadApi,$adminFixture]),'DB_MONTO_EXACTO');
rechaza(fn()=>$local->cancelarTitular($presId,$alumno),'CANCELACION_OTRO_TITULAR');
[$status,$adminHtml]=$web('abm/reservas.php?fecha=2099-04-04',null,$cookieAdmin);
preg_match('/name="csrf_reservas" value="([a-f0-9]+)"/',$adminHtml,$mt);$csrfAdmin=$mt[1]??'';
if($csrfAdmin==='')throw new RuntimeException('FALTA_CSRF_ADMIN');
$adminForm=['action'=>'registrar_cobro','reserva_id'=>(string)$presId,'csrf_reservas'=>'invalido'];
$web('abm/reservas.php?fecha=2099-04-04',$adminForm,$cookieAdmin);
igual((int)q($admin,'SELECT count(*) FROM reserva_movimientos_presenciales WHERE fk_reserva=?',[$presId])->fetchColumn(),0,'CSRF_COBRO');
$salidas=$parallel(['cobro',(string)$presId,(string)$adminFixture]);igual($salidas[0],$salidas[1],'COBRO_CONCURRENTE');
$adminForm['csrf_reservas']=$csrfAdmin;$web('abm/reservas.php?fecha=2099-04-04',$adminForm,$cookieAdmin);
igual((int)q($admin,"SELECT count(*) FROM notificaciones_email WHERE clave_evento=?",['reserva:'.$presId.':cobro_presencial'])->fetchColumn(),1,'NOTIF_COBRO_UNICA');
igual((int)q($admin,"SELECT count(*) FROM reserva_movimientos_presenciales WHERE fk_reserva=? AND tipo='cobro'",[$presId])->fetchColumn(),1,'COBRO_UNICO');
igual(q($admin,'SELECT estado FROM reservas WHERE id=?',[$presId])->fetchColumn(),'reservado','COBRO_CONFIRMA');
$mov=q($admin,"SELECT * FROM reserva_movimientos_presenciales WHERE fk_reserva=? AND tipo='cobro'",[$presId])->fetch();
igual((int)$mov['fk_actor'],$adminFixture,'ACTOR_COBRO');igual($mov['importe_ars'],'2500.75','COBRO_MONTO_SNAPSHOT');
$adminForm['action']='cancelar';$adminForm['motivo']='';$web('abm/reservas.php?fecha=2099-04-04',$adminForm,$cookieAdmin);
igual(q($admin,'SELECT estado FROM reservas WHERE id=?',[$presId])->fetchColumn(),'reservado','MOTIVO_OBLIGATORIO');
$adminForm['motivo']='Mantenimiento excepcional';$web('abm/reservas.php?fecha=2099-04-04',$adminForm,$cookieAdmin);
$cancelada=q($admin,'SELECT * FROM reservas WHERE id=?',[$presId])->fetch();
igual($cancelada['estado'],'cancelado','CANCELACION_ADMIN_WEB');igual($cancelada['cancelacion_origen'],'administrador','ORIGEN_ADMIN');
igual((int)$cancelada['cancelado_por_usuario'],$adminFixture,'ACTOR_CANCELACION');igual($cancelada['motivo_cancelacion'],'Mantenimiento excepcional','MOTIVO_AUDITADO');
igual((int)q($admin,"SELECT count(*) FROM reserva_movimientos_presenciales WHERE fk_reserva=? AND tipo='devolucion_solicitada'",[$presId])->fetchColumn(),1,'ADMIN_DEVOLUCION_TOTAL');
$web('abm/reservas.php?fecha=2099-04-04',$adminForm,$cookieAdmin);
igual((int)q($admin,"SELECT count(*) FROM reserva_movimientos_presenciales WHERE fk_reserva=? AND tipo='devolucion_solicitada'",[$presId])->fetchColumn(),1,'CANCELACION_ADMIN_IDEMPOTENTE');
igual((int)q($admin,"SELECT count(*) FROM notificaciones_email WHERE clave_evento IN (?,?)",['reserva:'.$presId.':cancelada','reserva:'.$presId.':devolucion_pendiente'])->fetchColumn(),2,'NOTIF_CANCELACION_Y_DEVOLUCION_PENDIENTE');
$adminForm['action']='registrar_devolucion';$web('abm/reservas.php?fecha=2099-04-04',$adminForm,$cookieAdmin);$web('abm/reservas.php?fecha=2099-04-04',$adminForm,$cookieAdmin);
igual((int)q($admin,"SELECT count(*) FROM reserva_movimientos_presenciales WHERE fk_reserva=? AND tipo='devolucion'",[$presId])->fetchColumn(),1,'DEVOLUCION_REGISTRADA_UNICA');
igual((int)q($admin,"SELECT count(*) FROM notificaciones_email WHERE clave_evento=?",['reserva:'.$presId.':devolucion_realizada'])->fetchColumn(),1,'NOTIF_DEVOLUCION_REALIZADA');
rechaza(fn()=>q($admin,"UPDATE reserva_movimientos_presenciales SET importe_ars=1 WHERE fk_reserva=?",[$presId]),'AUDITORIA_INMUTABLE');
rechaza(fn()=>q($p,"DELETE FROM reserva_movimientos_presenciales WHERE fk_reserva=?",[$presId]),'APP_NO_BORRA_AUDITORIA');
rechaza(fn()=>q($admin,'UPDATE reservas SET importe_total_ars=1 WHERE id=?',[$presId]),'IMPORTE_PRESENCIAL_INMUTABLE');
// Cancelacion del titular con pago anticipado: solicitud total pendiente, sin devolver dinero automaticamente.
$rTit=$local->crear($configId,$alumnoFixture,'2099-04-05','02:00:00','2700.99','presencial');$tid=(int)$rTit['id'];$local->registrarCobro($tid,$adminFixture);
$web('perfil.php#mis-reservas',['action'=>'cancelar_reserva','reserva_id'=>(string)$tid,'csrf_reservas'=>$csrfAlumno],$cookieAlumno);
igual(q($admin,'SELECT estado FROM reservas WHERE id=?',[$tid])->fetchColumn(),'cancelado','TITULAR_CANCELA_WEB');
igual((int)q($admin,"SELECT count(*) FROM reserva_movimientos_presenciales WHERE fk_reserva=? AND tipo='devolucion_solicitada'",[$tid])->fetchColumn(),1,'TITULAR_24H_DEVOLUCION');
igual((int)q($admin,"SELECT count(*) FROM reserva_movimientos_presenciales WHERE fk_reserva=? AND tipo='devolucion'",[$tid])->fetchColumn(),0,'NO_DEVOLUCION_AUTOMATICA');
// Turno cercano: cancelar usuario no genera solicitud; administrador si genera total.
$cerca=q($admin,"SELECT (clock_timestamp() AT TIME ZONE 'America/Argentina/Buenos_Aires'+interval '2 hours')::date AS fecha,
    to_char(date_trunc('hour',clock_timestamp() AT TIME ZONE 'America/Argentina/Buenos_Aires'+interval '2 hours'),'HH24:MI:SS') AS hora")->fetch();
// Fixture evita depender del horario de apertura cuando el reloj queda cerca del cierre.
$nuevoCerca=function(string $hora)use($admin,$configId,$alumnoFixture,$cerca):int{
    return (int)q($admin,"INSERT INTO reservas(fk_espacio,fk_usuario,fecha_de_asistencia,horario,estado,modalidad_pago,importe_total_ars)
        VALUES(?,?,?,?,'pendiente_presencial','presencial',2700.99) RETURNING id",[$configId,$alumnoFixture,$cerca['fecha'],$hora])->fetchColumn();
};
$cid=$nuevoCerca($cerca['hora']);$local->registrarCobro($cid,$adminFixture);$local->cancelarTitular($cid,$alumnoFixture);
igual((int)q($admin,"SELECT count(*) FROM reserva_movimientos_presenciales WHERE fk_reserva=? AND tipo='devolucion_solicitada'",[$cid])->fetchColumn(),0,'MENOS_24H_PRESENCIAL');
$cid2=$nuevoCerca($cerca['hora']);$local->registrarCobro($cid2,$adminFixture);$local->cancelarAdministrador($cid2,$adminFixture,'Cancelacion excepcional cercana');
igual((int)q($admin,"SELECT count(*) FROM reserva_movimientos_presenciales WHERE fk_reserva=? AND tipo='devolucion_solicitada'",[$cid2])->fetchColumn(),1,'ADMIN_MENOS_24H_TOTAL');
$sinCobro=$local->crear($configId,$alumnoFixture,'2099-04-06','02:00:00','2700.99','presencial');$sid=(int)$sinCobro['id'];$local->cancelarTitular($sid,$alumnoFixture);
igual((int)q($admin,'SELECT count(*) FROM reserva_movimientos_presenciales WHERE fk_reserva=?',[$sid])->fetchColumn(),0,'SIN_COBRO_NO_DEVOLUCION');
rechaza(fn()=>$local->registrarCobro($sid,$adminFixture),'NO_COBRAR_CANCELADA');
// La fecha POST se valida independientemente de la fecha visible de calendario.
rechaza(fn()=>$local->crear($configId,$alumnoFixture,'2099-02-30','02:00:00','2700.99','presencial'),'FECHA_INVALIDA');
rechaza(fn()=>$local->crear($configId,$alumnoFixture,'2099-04-07','02:30:00','2700.99','presencial'),'TURNO_INVALIDO');
echo "OK: presencial web, snapshot, cobro concurrente, auditoria, cancelacion y devolucion total presencial segun actor/plazo.\n";
