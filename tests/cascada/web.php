<?php
// Se ejecuta desde validar.php; solo usa identidades y contraseñas efímeras de la base temporal.
$clave=bin2hex(random_bytes(24));
foreach([$gestor,$responsable,$profesor,$alumno] as $uid)cq($root,'UPDATE usuarios SET contrasena=? WHERE id=?',[password_hash($clave,PASSWORD_DEFAULT),$uid]);
$token=bin2hex(random_bytes(32));cq($root,"INSERT INTO api_sesiones(fk_usuario,token_hash,vence_en) VALUES(?,?,clock_timestamp()+interval '5 minutes')",[$profesor,hash('sha256',$token)]);
$entorno=array_merge(getenv(),['PGHOST'=>'127.0.0.1','PGPORT'=>'5433','PGDATABASE'=>getenv('MULTIPRO_TEST_DB'),'PGUSER'=>'multipro_app_local','PGPASSWORD'=>getenv('MULTIPRO_TEST_APP_PASSWORD'),'PGSSLMODE'=>'disable','MAIL_ENABLED'=>'false']);
$socket=@fsockopen('127.0.0.1',8084,$errno,$error,0.1);if($socket){fclose($socket);throw new RuntimeException('Puerto de pruebas ocupado.');}
$log=tempnam(sys_get_temp_dir(),'multipro-cascada-');$pipes=[];
$servidor=proc_open([PHP_BINARY,'-c',getenv('MULTIPRO_TEST_PHP_INI'),'-S','127.0.0.1:8084','-t',realpath(__DIR__.'/../../web')],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,null,$entorno);
if(!is_resource($servidor))throw new RuntimeException('No se pudo iniciar el servidor temporal.');fclose($pipes[0]);
try {
    $listo=false;for($n=0;$n<50;$n++){$socket=@fsockopen('127.0.0.1',8084,$errno,$error,0.1);if($socket){fclose($socket);$listo=true;break;}usleep(50000);}ca($listo,'servidor temporal disponible');
    $http=static function(string $ruta,?array $datos,string &$cookie,string $bearer=''):array {
        $op=['method'=>$datos===null?'GET':'POST','timeout'=>10,'ignore_errors'=>true,'follow_location'=>0,'header'=>'Cookie: '.$cookie."\r\n"];
        if($bearer!=='')$op['header'].='Authorization: Bearer '.$bearer."\r\n";
        if($datos!==null){$op['header'].="Content-Type: application/x-www-form-urlencoded\r\n";$op['content']=http_build_query($datos);}
        $html=file_get_contents('http://127.0.0.1:8084/'.$ruta,false,stream_context_create(['http'=>$op]));
        foreach($http_response_header??[] as $h)if(preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i',$h,$m))$cookie=$m[1];
        preg_match('/ ([0-9]{3}) /',$http_response_header[0]??'',$m);
        ca(!str_contains((string)$html,'Fatal error') && !str_contains((string)$html,'Warning:'),'sin errores PHP '.$ruta);
        return [(int)($m[1]??0),(string)$html];
    };
    $campos=static function(string $html):array {
        $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);$v=[];
        foreach($xp->query('//input[@type="hidden"]') as $input)$v[$input->getAttribute('name')]=$input->getAttribute('value');return $v;
    };
    $cg='';$caCookie='';$anon='';$cal='';
    $emailResponsable=cq($root,'SELECT email FROM usuarios WHERE id=?',[$responsable])->fetchColumn();
    foreach([['gestor@example.invalid',&$cg],[$emailResponsable,&$caCookie],['alumno@example.invalid',&$cal]] as &$login){[$codigo]=$http('login.php',['action'=>'login','email'=>$login[0],'contrasena'=>$clave],$login[1]);ca($codigo===302,'login web de fixture');}unset($login);
    $apiAsistencia=static function(array $datos)use($token):array {
        $contexto=stream_context_create(['http'=>['method'=>'POST','timeout'=>10,'ignore_errors'=>true,
            'header'=>"Authorization: Bearer $token\r\nContent-Type: application/json\r\n",
            'content'=>json_encode($datos,JSON_THROW_ON_ERROR)]]);
        $contenido=file_get_contents('http://127.0.0.1:8084/api/v1/profesor/asistencia.php',false,$contexto);
        preg_match('/ ([0-9]{3}) /',$http_response_header[0]??'',$coincide);
        return [(int)($coincide[1]??0),json_decode((string)$contenido,true,512,JSON_THROW_ON_ERROR)];
    };
    [$codigo,$respuesta]=$apiAsistencia(['modulo_id'=>$m,'inscripcion_id'=>$i1,'estado'=>'presente','fecha'=>(new DateTimeImmutable('tomorrow',new DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d')]);
    ca($codigo===422 && str_contains($respuesta['error']??'','fecha futura'),'API rechaza asistencia futura');
    foreach(['index','actividades','espacios'] as $pagina) {
        [$codigo,$html]=$http($pagina.'.php?'.http_build_query(['provincias'=>[$provincia],'localidades'=>[$lp],'entidades'=>[$e]]),null,$anon);
        ca($codigo===200 && str_contains($html,'name="provincias[]"') && str_contains($html,'name="localidades[]"'),'catálogo real con ubicación '.$pagina);
        ca(!str_contains($html,'No pudimos cargar'),'consulta PostgreSQL de catálogo '.$pagina);
    }
    [$codigo,$html]=$http('abm/entidades.php',null,$cg);ca($codigo===200,'ABM entidad y campos ubicación');$csrf=$campos($html);
    $datos=['action'=>'editar','csrf_entidades'=>$csrf['csrf_entidades'],'id'=>$otra,'nombre'=>'Otra fixture','cuit'=>'23123459876','tipo_entidad'=>'publica','direccion'=>'Dirección ficticia','horario_apertura'=>'00:00','horario_cierre'=>'23:59','coordenadas'=>'','informacion'=>'','imagenURL'=>'','provincia'=>' Buenos   Aires ','localidad'=>'Otra localidad'];
    [$codigo,$html]=$http('abm/entidades.php',$datos,$cg);
    ca($codigo===200 && str_contains($html,'Entidad modificada con éxito'),'edición ubicación por Gestor');
    ca(cq($p,'SELECT p.nombre FROM entidades e JOIN localidades l ON l.id=e.fk_localidad JOIN provincias p ON p.id=l.fk_provincia WHERE e.id=?',[$otra])->fetchColumn()==='Buenos Aires','edición reutiliza provincia normalizada');
    [$codigo,$html]=$http('abm/espacios.php',null,$caCookie);$csrfAdmin=$campos($html);
    [$codigo]=$http('abm/espacios.php',['estado_accion'=>'desactivar','recurso_id'=>$pago,'motivo'=>'Sin token'],$caCookie);
    ca($codigo!==302 && cq($p,'SELECT estado FROM espacios WHERE id=?',[$pago])->fetchColumn(),'rechazo CSRF no modifica espacio');
    // Desactivar vía GET ya no ejecuta acciones.
    [$codigo]=$http('abm/espacios.php?toggle_estado='.$pago.'&estado=1',null,$caCookie);
    ca((bool)cq($p,'SELECT estado FROM espacios WHERE id=?',[$pago])->fetchColumn(),'GET no muta estado');
    // Preparar una devolución pendiente y demostrar su resolución desde la entidad inactiva.
    $rWeb=$reservas->crear($pago,$alumno,$turno->modify('+8 days')->format('Y-m-d'),$hora,'100.00','presencial');$reservas->registrarCobro((int)$rWeb['id'],$responsable);
    $cobroPasado=$id("INSERT INTO reservas(fk_espacio,fk_usuario,fecha_de_asistencia,horario,estado,modalidad_pago,importe_total_ars) VALUES(?,?,'2020-01-02','08:00','pendiente_presencial','presencial',100)",[$pago,$alumno2]);
    [$codigo,$html]=$http('abm/entidades.php',null,$cg);$csrf=$campos($html);
    $solicitud=['estado_accion'=>'desactivar','recurso_id'=>$e,'motivo'=>'Cierre desde web','csrf_entidades'=>$csrf['csrf_entidades'],'csrf_estados'=>$csrf['csrf_estados']];
    [$codigo,$html]=$http('abm/entidades.php',$solicitud,$cg);
    ca($codigo===200 && str_contains($html,'Revisar impacto:'),'vista previa web');
    ca((bool)cq($p,'SELECT estado FROM entidades WHERE id=?',[$e])->fetchColumn(),'vista previa no muta');
    $confirmar=$campos($html); // Puede haber otros formularios en la página: tomar el formulario de impacto.
    $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);$confirmar=[];
    foreach($xp->query('//section[@aria-labelledby="titulo-impacto"]//input[@type="hidden"]') as $input)$confirmar[$input->getAttribute('name')]=$input->getAttribute('value');
    [$codigo]=$http('abm/entidades.php',$confirmar,$cg);ca($codigo===302,'confirma baja web');
    ca(!cq($p,'SELECT estado FROM entidades WHERE id=?',[$e])->fetchColumn(),'entidad inactiva en BD');
    [$codigo]=$http('abm/actividades.php',['action'=>'crear','nombre'=>'No autorizado'],$caCookie);ca($codigo===403,'administrador inactivo solo gestiona economía');
    [$codigo]=$http('abm/reservas.php',null,$caCookie);ca($codigo===200,'historial accesible con entidad inactiva');
    [$codigo,$html]=$http('abm/reservas.php?fecha='.$rWeb['fecha_de_asistencia'],null,$caCookie);$crf=$campos($html);
    [$codigo,$html]=$http('abm/reservas.php',['action'=>'registrar_devolucion','reserva_id'=>$rWeb['id'],'csrf_reservas'=>$crf['csrf_reservas']],$caCookie);
    ca($codigo===200 && (int)cq($p,"SELECT count(*) FROM reserva_movimientos_presenciales WHERE fk_reserva=? AND tipo='devolucion'",[$rWeb['id']])->fetchColumn()===1,'entrega real desde administración inactiva');
    ca((int)cq($p,'SELECT multipro_pendientes_economicos(?)',[$e])->fetchColumn()===1,'cobro pasado sigue pendiente y no se cancela retroactivamente');
    cr(fn()=>$s->cerrarAdministracion($e,$gestor,'Queda un cobro'),'cobro pendiente bloquea cierre');
    [$codigo,$html]=$http('abm/reservas.php?fecha=2020-01-02',null,$caCookie);$crf=$campos($html);
    [$codigo]=$http('abm/reservas.php',['action'=>'registrar_cobro','reserva_id'=>$cobroPasado,'csrf_reservas'=>$crf['csrf_reservas']],$caCookie);
    ca($codigo===200 && cq($p,'SELECT estado FROM reservas WHERE id=?',[$cobroPasado])->fetchColumn()==='reservado','puede resolver cobro existente sin reabrir entidad');
    $s->cerrarAdministracion($e,$gestor,'Pendientes resueltos');
    [$codigo,$html]=$http('abm/reservas.php',null,$caCookie);ca($codigo===200 && str_contains($html,'administración de la entidad está cerrada'),'historial y aviso de cierre');
    [$codigo]=$http('abm/reservas.php',['action'=>'registrar_cobro','reserva_id'=>$cobroPasado,'csrf_reservas'=>$crf['csrf_reservas']],$caCookie);ca($codigo===403,'administración cerrada solo permite consulta');
    [$codigo,$html]=$http('api/v1/profesor/modulos.php',null,$anon,$token);ca($codigo===200 && json_decode($html,true)['data']===[],'API profesor oculta módulos inactivos');
    [$codigo,$html]=$http('api/v1/profesor/alumnos.php?modulo_id='.$m.'&fecha=2026-10-30',null,$anon,$token);ca($codigo===404,'acceso directo API módulo inactivo rechazado');
    [$codigo,$html]=$http('perfil.php',null,$cal);ca($codigo===200 && str_contains($html,'SUSPENDIDA'),'perfil muestra suspensión');
    $logTexto=file_get_contents($log);ca(!preg_match('/PHP (Warning|Fatal|Parse|Notice)/',$logTexto),'servidor sin avisos ni errores');
    echo "CASCADA_WEB_API_OK\n";
} finally {proc_terminate($servidor);proc_close($servidor);unlink($log);}
