<?php
// Integracion HTTP sobre base/servidor temporales, credenciales efimeras solo en memoria.
$tokenPropio=bin2hex(random_bytes(32));
q($admin,"INSERT INTO api_sesiones(fk_usuario,token_hash,vence_en) VALUES(?,?,clock_timestamp()+interval '5 minutes')",[$alumnoFixture,hash('sha256',$tokenPropio)]);
$api=function(string $metodo,string $ruta,?array $datos=null,?string $bearer=null)use($tokenPropio):array {
    $h="Content-Type: application/json\r\n";$bearer??=$tokenPropio;
    if($bearer!=='')$h.='Authorization: Bearer '.$bearer."\r\n";
    $opts=['method'=>$metodo,'timeout'=>5,'ignore_errors'=>true,'header'=>$h];if($datos!==null)$opts['content']=json_encode($datos);
    $body=file_get_contents('http://127.0.0.1:8082/api/v1/'.$ruta,false,stream_context_create(['http'=>$opts]));
    preg_match('/ ([0-9]{3}) /',$http_response_header[0]??'',$m);
    return [(int)($m[1]??0),json_decode($body?:'{}',true)];
};
[$st,$datosEsp]=$api('GET','espacio.php?id='.$configId);igual($st,200,'API_PRECIO_ESPACIO');
igual($datosEsp['data']['precio_hora_ars'],'2700.99','API_PRECIO_TEXTO');igual($datosEsp['data']['modalidades_disponibles'],['presencial'],'API_MODALIDADES');
igual($datosEsp['data']['cobro_online_habilitado'],false,'API_ONLINE_DESACTIVADO');
[$st,$listEsp]=$api('GET','espacios.php?entidad_id='.$entidadApi);igual($st,200,'API_LISTA_ESPACIOS');
$item=array_values(array_filter($listEsp['data'],fn(array $r):bool=>$r['id']===$configId))[0];igual($item['precio_hora_ars'],'2700.99','API_LISTADO_PRECIO');
$body=['espacio_id'=>$configId,'fecha'=>'2099-05-01','horario'=>'03:00','modalidad_pago'=>'presencial','precio_visto'=>'2700.99'];
[$st]=$api('POST','reservas/crear.php',$body,'');igual($st,401,'API_SIN_SESION');
foreach([['espacio_id'=>true],['espacio_id'=>[]],['precio_visto'=>2700.99],['precio_visto'=>'1.00'],['modalidad_pago'=>'online'],['fecha'=>'2099-02-30']] as $cambio){
    [$st]=$api('POST','reservas/crear.php',array_replace($body,$cambio));if(!in_array($st,[409,422],true))throw new RuntimeException('API_ACEPTO_DATOS_INVALIDOS');
}
[$st,$creada]=$api('POST','reservas/crear.php',$body+['importe_total_ars'=>'0.00','fk_usuario'=>$alumno,'estado'=>'reservado']);
igual($st,201,'API_CREAR_PRESENCIAL');$apiIdPres=$creada['data']['id'];
igual($creada['data']['estado'],'pendiente_presencial','API_ESTADO_PENDIENTE');igual($creada['data']['importe_total_ars'],'2700.99','API_MONTO_SERVIDOR');
igual((int)q($admin,'SELECT fk_usuario FROM reservas WHERE id=?',[$apiIdPres])->fetchColumn(),$alumnoFixture,'API_TITULAR_SESION');
[$st,$repetida]=$api('POST','reservas/crear.php',$body);igual($st,201,'API_REINTENTO');igual($repetida['data']['id'],$apiIdPres,'API_SIN_DUPLICADO');
[$st]=$api('GET','reservas/detalle.php?id='.$apiIdPres,null,$token);igual($st,404,'API_DETALLE_AJENO');
[$st]=$api('POST','reservas/cancelar.php',['reserva_id'=>$apiIdPres],$token);igual($st,404,'API_CANCELACION_AJENA');
igual(q($admin,'SELECT estado FROM reservas WHERE id=?',[$apiIdPres])->fetchColumn(),'pendiente_presencial','API_AJENO_NO_MODIFICA');
$local->registrarCobro($apiIdPres,$adminFixture);
[$st,$detalle]=$api('GET','reservas/detalle.php?id='.$apiIdPres);igual($detalle['data']['presencial_cobrado'],true,'API_COBRO_VISIBLE');igual($detalle['data']['estado'],'reservado','API_ESTADO_COBRADO');
q($admin,'UPDATE espacios SET precio_hora_ars=2900 WHERE id=?',[$configId]);
[$st,$detalle]=$api('GET','reservas/detalle.php?id='.$apiIdPres);igual($detalle['data']['importe_total_ars'],'2700.99','API_PRECIO_CONGELADO');
[$st,$cancelada]=$api('POST','reservas/cancelar.php',['reserva_id'=>$apiIdPres]);igual($st,200,'API_CANCELAR_PRESENCIAL');igual($cancelada['data']['devolucion_presencial'],'pendiente','API_DEVOLUCION_PENDIENTE');
[$st]=$api('POST','reservas/cancelar.php',['reserva_id'=>$apiIdPres]);igual($st,200,'API_CANCELAR_IDEMPOTENTE');
igual((int)q($admin,"SELECT count(*) FROM reserva_movimientos_presenciales WHERE fk_reserva=? AND tipo='devolucion_solicitada'",[$apiIdPres])->fetchColumn(),1,'API_DEVOLUCION_UNICA');
$local->registrarDevolucion($apiIdPres,$adminFixture);
[$st,$detalle]=$api('GET','reservas/detalle.php?id='.$apiIdPres);igual($detalle['data']['devolucion_presencial'],'realizada','API_DEVOLUCION_REALIZADA');
// Vencidas excluidas de lista y detalle; canceladas conservadas.
$vencida=(int)q($admin,"INSERT INTO reservas(fk_espacio,fk_usuario,fecha_de_asistencia,horario,estado,modalidad_pago,importe_total_ars)
    VALUES(?,?,'2099-05-02','03:00','vencido','online',100) RETURNING id",[$configId,$alumnoFixture])->fetchColumn();
[$st,$hist]=$api('GET','reservas/listar.php');igual($st,200,'API_HISTORIAL');$ids=array_column($hist['data'],'id');igual(in_array($vencida,$ids,true),false,'API_NO_VENCIDAS');igual(in_array($apiIdPres,$ids,true),true,'API_SI_CANCELADAS');
[$st]=$api('GET','reservas/detalle.php?id='.$vencida);igual($st,404,'API_DETALLE_VENCIDA');
foreach(['token_compartir','referencia_credencial','cuenta_proveedor','fk_actor'] as $privado)igual(array_key_exists($privado,$detalle['data']),false,'API_SIN_DATOS_INTERNOS');
// Compatibilidad con cliente anterior: solo espacio/fecha/horario en gratuita.
[$st,$gratisApi]=$api('POST','reservas/crear.php',['espacio_id'=>$espacioApi,'fecha'=>'2099-05-03','horario'=>'03:00']);igual($st,201,'API_CLIENTE_ANTERIOR');igual($gratisApi['data']['importe_total_ars'],'0.00','API_GRATUITA_CERO');
[$st]=$api('POST','reservas/cancelar.php',['reserva_id'=>$gratisApi['data']['id']]);igual($st,200,'API_GRATUITA_CANCELA');
echo "OK: API presencial, compatibilidad gratuita, importe, titular, idempotencia, historial y devoluciones.\n";
