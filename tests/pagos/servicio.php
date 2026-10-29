<?php
declare(strict_types=1);
require_once __DIR__.'/../../web/includes/pagos/ServicioPagos.php';
require_once __DIR__.'/../../web/includes/pagos/ServicioReservas.php';
use MultiPro\Pagos\ServicioPagos;
use MultiPro\Pagos\ProveedorPagos;

// Doble exclusivamente de pruebas. No valida firmas reales ni realiza llamadas de red.
final class ProveedorSimulado implements ProveedorPagos
{
    public array $pagos = [];
    public array $claves = [];
    public bool $fallarDevolucion = false;
    public function autenticarEvento(array $c,array $h,string $b,array $q): array {
        if ($b !== 'fixture-autenticada') throw new RuntimeException('FIRMA_INVALIDA');
        return ['evento_id'=>$q['evento'],'recurso_id'=>$q['recurso'],'tipo'=>'payment'];
    }
    public function consultarPago(array $c,string $id): array { return $this->pagos[$id]; }
    public function reembolsar(array $c,array $p,string $clave): array {
        $this->claves[]=$clave;
        if ($this->fallarDevolucion) { $this->fallarDevolucion=false; throw new RuntimeException('TIMEOUT_SIMULADO'); }
        return ['id'=>'refund-'.$clave,'pago_id'=>$p['pago_proveedor_id'],'importe'=>$p['importe_ars'],'moneda'=>'ARS','estado'=>'aprobado'];
    }
}

function db(bool $app=true): PDO {
    $nombre=getenv('MULTIPRO_TEST_DB');
    if (!is_string($nombre) || !preg_match('/^multipro_test_pagos_[0-9]{14}$/D',$nombre)) throw new RuntimeException('BASE_NO_TEMPORAL');
    $p=new PDO('pgsql:host=127.0.0.1;port=5433;dbname='.$nombre.';sslmode=disable',getenv('MULTIPRO_TEST_USER'),getenv('MULTIPRO_TEST_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    if ($app) $p->exec('SET ROLE multipro_app_local');
    return $p;
}
function q(PDO $p,string $s,array $a=[]): PDOStatement { $r=$p->prepare($s);$r->execute($a);return $r; }
function igual(mixed $a,mixed $b,string $nombre): void { if ($a!==$b) throw new RuntimeException('FALLO_'.$nombre); }
function rechaza(callable $f,string $nombre): void { try{$f();}catch(RuntimeException $e){return;} throw new RuntimeException('ACEPTO_'.$nombre); }
function clave(int $n): string { return '10000000-0000-4000-8000-'.str_pad((string)$n,12,'0',STR_PAD_LEFT); }

try {
    if (($argv[1]??'')==='worker') {
        $p=db();$proveedor=new ProveedorSimulado();$s=new ServicioPagos($p,$proveedor);
        if ($argv[2]==='intento') {
            $pago=$s->iniciarIntento((int)$argv[3],(int)$argv[4],$argv[5]);
            echo $pago['id'];
        } elseif ($argv[2]==='evento') {
            $pago=q($p,'SELECT * FROM pago_transacciones WHERE id=?',[(int)$argv[4]])->fetch();
            $c=q($p,'SELECT * FROM pago_conexiones WHERE id=?',[$pago['fk_conexion']])->fetch();
            $proveedor->pagos['concurrente']=['id'=>'concurrente','referencia'=>$pago['referencia_externa'],'cuenta'=>$c['cuenta_proveedor'],
                'ambiente'=>'prueba','importe'=>'100.00','moneda'=>'ARS','estado'=>'aprobado','inmediato'=>true];
            echo $s->procesarEvento((int)$argv[3]);
        } elseif ($argv[2]==='cobro') {
            (new \MultiPro\Pagos\ServicioReservas($p))->registrarCobro((int)$argv[3],(int)$argv[4]);echo 'cobro-ok';
        }
        exit(0);
    }
    $admin=db(false);$p=db();$fake=new ProveedorSimulado();$s=new ServicioPagos($p,$fake);
    $huella=q($admin,"SELECT md5(string_agg(to_jsonb(r)::text,',' ORDER BY id)) FROM reservas r")->fetchColumn();
    $espacio=q($admin,'SELECT id,fk_entidad FROM espacios WHERE fk_entidad IS NOT NULL ORDER BY id LIMIT 1')->fetch();
    $usuario=(int)q($admin,'SELECT min(id) FROM usuarios')->fetchColumn();
    q($admin,'UPDATE espacios SET estado=true,cobro_online_habilitado=true,precio_hora_ars=100 WHERE id=?',[$espacio['id']]);
    q($admin,'UPDATE entidades SET estado=true WHERE id=?',[$espacio['fk_entidad']]);
    $conexion=(int)q($admin,"INSERT INTO pago_conexiones(fk_entidad,proveedor,ambiente,modalidad_conexion,cuenta_proveedor,referencia_credencial,estado)
        VALUES(?,'simulado','prueba','oauth','fixture-cuenta',gen_random_uuid(),'activa') RETURNING id",[$espacio['fk_entidad']])->fetchColumn();
    $creadas=[];
    $reserva=function(bool $vencida=false,string $fecha='2099-03-01',string $hora='01:00') use($admin,$espacio,$usuario,&$creadas):int {
        $id=(int)q($admin,"INSERT INTO reservas(fk_espacio,fk_usuario,fecha_de_asistencia,horario,estado,modalidad_pago,importe_total_ars,creado_en,pago_vence_en)
            VALUES(?,?,?,?,'pendiente_pago','online',100,clock_timestamp()-interval '1 minute',clock_timestamp()+interval '14 minutes') RETURNING id",
            [$espacio['id'],$usuario,$fecha,$hora])->fetchColumn();
        if($vencida)q($admin,"UPDATE reservas SET creado_en=clock_timestamp()-interval '20 minutes',pago_vence_en=clock_timestamp()-interval '5 minutes' WHERE id=?",[$id]);
        $creadas[]=$id;return $id;
    };
    $snapshot=function(array $pago,string $id,string $estado='aprobado',array $extra=[])use($fake):void{
        $fake->pagos[$id]=array_merge(['id'=>$id,'referencia'=>$pago['referencia_externa'],'cuenta'=>'fixture-cuenta','ambiente'=>'prueba',
            'importe'=>'100.00','moneda'=>'ARS','estado'=>$estado,'inmediato'=>true],$extra);
    };
    $evento=function(string $id,string $recurso)use($s,$conexion):int{return $s->recibirWebhook($conexion,[],'fixture-autenticada',['evento'=>$id,'recurso'=>$recurso]);};
    $r=$reserva();$intento=$s->iniciarIntento($r,$usuario,clave(1));
    igual($s->iniciarIntento($r,$usuario,clave(1))['id'],$intento['id'],'REINTENTO_ESTABLE');
    rechaza(fn()=>$s->iniciarIntento($r,$usuario,clave(2)),'SEGUNDO_INTENTO_INCIERTO');
    rechaza(fn()=>$s->iniciarIntento($r,$usuario+999999,clave(1)),'OTRO_TITULAR');
    rechaza(fn()=>$s->iniciarIntento($r,$usuario,'invalida'),'UUID');
    rechaza(fn()=>ServicioPagos::importe('1e2'),'EXPONENTE');
    rechaza(fn()=>ServicioPagos::importe('100.001'),'DECIMALES');
    igual(ServicioPagos::importe('100'),'100.00','DECIMAL');
    rechaza(fn()=>$s->recibirWebhook($conexion,[],'firma-falsa',[]),'FIRMA');
    igual((int)q($admin,'SELECT count(*) FROM pago_webhook_eventos')->fetchColumn(),0,'FIRMA_NO_PERSISTIDA');
    $snapshot($intento,'pago-1');$ev=$evento('evento-1','pago-1');
    igual($evento('evento-1','pago-1'),$ev,'EVENTO_DUPLICADO');
    rechaza(fn()=>$evento('evento-1','otro-pago'),'EVENTO_CONFLICTIVO');
    igual($s->procesarEvento($ev),'procesado','PROCESAMIENTO');
    igual($s->procesarEvento($ev),'sin_trabajo','REPROCESAMIENTO');
    igual(q($admin,'SELECT estado FROM reservas WHERE id=?',[$r])->fetchColumn(),'reservado','CONFIRMACION');
    igual(q($admin,'SELECT confirmo_reserva FROM pago_transacciones WHERE id=?',[$intento['id']])->fetchColumn(),true,'CONFIRMADOR');
    $snapshot($intento,'pago-1','pendiente');igual($s->procesarEvento($evento('evento-viejo','pago-1')),'ignorado','ORDEN_INVERSO');
    $snapshot($intento,'duplicado');$s->procesarEvento($evento('evento-dup','duplicado'));
    igual((int)q($admin,'SELECT count(*) FROM pago_reembolsos')->fetchColumn(),1,'DEVOLUCION_DUPLICADO');
    $refund=(int)q($admin,'SELECT id FROM pago_reembolsos')->fetchColumn();
    $fake->fallarDevolucion=true;rechaza(fn()=>$s->procesarReembolso($refund),'TIMEOUT');
    igual($s->procesarReembolso($refund),'aprobado','REINTENTO_DEVOLUCION');
    igual($fake->claves[0],$fake->claves[1],'MISMA_CLAVE_DEVOLUCION');
    igual($s->procesarReembolso($refund),'aprobado','DEVOLUCION_TERMINAL');
    igual(count($fake->claves),2,'SIN_TERCERA_LLAMADA');
    $snapshot($intento,'pago-1');$s->procesarEvento($evento('repeticion-original','pago-1'));
    igual((int)q($admin,'SELECT count(*) FROM pago_reembolsos')->fetchColumn(),1,'ORIGINAL_NO_DEVUELTO_POR_DUPLICADO');
    $s->cancelarUsuario($r,$usuario);$s->cancelarUsuario($r,$usuario);
    igual((int)q($admin,'SELECT count(*) FROM pago_reembolsos')->fetchColumn(),2,'CANCELACION_24H_UNICA');
    $r2=$reserva(false,'2099-03-02');$i2=$s->iniciarIntento($r2,$usuario,clave(3));
    q($admin,"UPDATE reservas SET creado_en=clock_timestamp()-interval '20 minutes',pago_vence_en=clock_timestamp()-interval '5 minutes' WHERE id=?",[$r2]);
    igual($s->vencerRetenciones(),1,'VENCER');igual($s->vencerRetenciones(),0,'VENCER_IDEMPOTENTE');
    $snapshot($i2,'tardio');$s->procesarEvento($evento('evento-tardio','tardio'));
    igual(q($admin,'SELECT estado FROM reservas WHERE id=?',[$r2])->fetchColumn(),'vencido','PAGO_TARDIO_NO_REVIVE');
    igual(q($admin,"SELECT motivo FROM pago_reembolsos WHERE fk_pago=?",[$i2['id']])->fetchColumn(),'pago_tardio','DEVOLUCION_TARDIA');
    $r3=$reserva(false,'2099-03-03');$i3=$s->iniciarIntento($r3,$usuario,clave(4));
    foreach(['cuenta'=>'ajena','importe'=>'101.00','moneda'=>'USD','referencia'=>clave(999),'ambiente'=>'produccion','inmediato'=>false] as $campo=>$valor){
        $pid='invalido-'.$campo;$snapshot($i3,$pid,'aprobado',[$campo=>$valor]);
        rechaza(fn()=>$s->procesarEvento($evento('evento-'.$pid,$pid)),'CORRELACION_'.$campo);
    }
    igual(q($admin,'SELECT estado FROM reservas WHERE id=?',[$r3])->fetchColumn(),'pendiente_pago','INVALIDOS_NO_CONFIRMAN');
    // Caida de worker: el lease vencido se recupera; el vigente no se roba.
    $snapshot($i3,'recuperado');$ev3=$evento('recuperacion','recuperado');
    q($admin,"UPDATE pago_webhook_eventos SET estado='procesando',bloqueo_hasta=clock_timestamp()+interval '1 minute' WHERE id=?",[$ev3]);
    igual($s->procesarEvento($ev3),'sin_trabajo','LEASE_VIGENTE');
    q($admin,"UPDATE pago_webhook_eventos SET bloqueo_hasta=clock_timestamp()-interval '1 second' WHERE id=?",[$ev3]);
    igual($s->procesarEvento($ev3),'procesado','RECUPERACION_LEASE');
    // Concurrencia real con procesos PHP separados y conexiones PostgreSQL separadas.
    $r4=$reserva(false,'2099-03-04');
    $parallel=function(array $args):array {
        $workers=[];
        for($n=0;$n<2;$n++){
            $pipes=[];$proc=proc_open(array_merge([PHP_BINARY,'-c',getenv('MULTIPRO_TEST_PHP_INI'),__FILE__,'worker'],$args),
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($proc))throw new RuntimeException('WORKER_NO_INICIADO');
            fclose($pipes[0]);$workers[]=[$proc,$pipes];
        }
        $out=[];foreach($workers as [$proc,$pipes]){$out[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException('WORKER_FALLO_'.$err);}
        return $out;
    };
    $out=$parallel(['intento',(string)$r4,(string)$usuario,clave(5)]);igual($out[0],$out[1],'CONCURRENCIA_INTENTO');
    igual((int)q($admin,'SELECT count(*) FROM pago_transacciones WHERE fk_reserva=?',[$r4])->fetchColumn(),1,'UN_SOLO_INTENTO');
    $i4=q($admin,'SELECT * FROM pago_transacciones WHERE fk_reserva=?',[$r4])->fetch();
    $ev4=$evento('concurrencia','concurrente');$parallel(['evento',(string)$ev4,(string)$i4['id']]);
    igual((int)q($admin,'SELECT intentos FROM pago_webhook_eventos WHERE id=?',[$ev4])->fetchColumn(),1,'UN_SOLO_PROCESADOR');
    igual(q($admin,'SELECT estado FROM reservas WHERE id=?',[$r4])->fetchColumn(),'reservado','CONCURRENCIA_CONFIRMA');
    // Reserva gratuita y auditoria de cancelacion con el mismo servicio y rol real.
    $gratis=(int)q($p,"INSERT INTO reservas(fk_espacio,fk_usuario,fecha_de_asistencia,horario) VALUES(?,?,'2099-03-05','01:00') RETURNING id",[$espacio['id'],$usuario])->fetchColumn();$creadas[]=$gratis;
    $s->cancelarUsuario($gratis,$usuario);
    igual((int)q($admin,'SELECT count(*) FROM pago_transacciones WHERE fk_reserva=?',[$gratis])->fetchColumn(),0,'GRATUITA_SIN_PAGO');
    igual(q($admin,'SELECT cancelacion_origen FROM reservas WHERE id=?',[$gratis])->fetchColumn(),'usuario','AUDITORIA_GRATUITA');
    // Cancelar un cobro ya confirmado a menos de 24 horas no genera devolucion.
    $cerca=q($admin,"SELECT (clock_timestamp() AT TIME ZONE 'America/Argentina/Buenos_Aires'+interval '2 hours')::date AS fecha,
        to_char(date_trunc('hour',clock_timestamp() AT TIME ZONE 'America/Argentina/Buenos_Aires'+interval '2 hours'),'HH24:MI') AS hora")->fetch();
    $r5=$reserva(false,$cerca['fecha'],$cerca['hora']);$i5=$s->iniciarIntento($r5,$usuario,clave(6));
    $snapshot($i5,'cerca');$s->procesarEvento($evento('cerca-inicial','cerca'));$s->cancelarUsuario($r5,$usuario);
    $s->procesarEvento($evento('cerca-repetido','cerca'));
    igual((int)q($admin,'SELECT count(*) FROM pago_reembolsos WHERE fk_pago=?',[$i5['id']])->fetchColumn(),0,'MENOS_24H_SIN_REEMBOLSO');
    // Restriccion estructural: el cobro confirmador no se puede borrar del historial.
    rechaza(fn()=>q($admin,'UPDATE pago_transacciones SET confirmo_reserva=false WHERE id=?',[$i5['id']]),'CONFIRMADOR_INMUTABLE');
    // El historico anterior a los fixtures no debe variar.
    $lista=implode(',',array_map('intval',$creadas));
    igual(q($admin,"SELECT md5(string_agg(to_jsonb(r)::text,',' ORDER BY id)) FROM reservas r WHERE id NOT IN($lista)")->fetchColumn(),$huella,'HISTORICO_INTACTO');
    foreach(['pago_conexiones','pago_transacciones','pago_webhook_eventos','pago_reembolsos'] as $t)igual(q($admin,"SELECT has_table_privilege('multipro_app_local',?,'DELETE')",[$t])->fetchColumn(),false,'SIN_DELETE');
    // Regresion HTTP real sobre servidor y base temporales, autenticando como Alumno.
    $alumno=(int)q($admin,"SELECT u.id FROM usuarios u JOIN roles r ON r.id=u.fk_rol WHERE lower(r.nombre)='alumno' AND u.estado=true LIMIT 1")->fetchColumn();
    if(!$alumno)throw new RuntimeException('FALTA_ALUMNO_FIXTURE');
    $entidadApi=(int)q($admin,"INSERT INTO entidades(nombre,direccion,horario_apertura,horario_cierre,estado) VALUES('Fixture MultiPro','Fixture','00:00','23:00',true) RETURNING id")->fetchColumn();
    $espacioApi=(int)q($admin,"INSERT INTO espacios(nombre,fk_entidad,estado) VALUES('Fixture API',?,true) RETURNING id",[$entidadApi])->fetchColumn();
    $token=bin2hex(random_bytes(32));
    q($admin,"INSERT INTO api_sesiones(fk_usuario,token_hash,vence_en) VALUES(?,?,clock_timestamp()+interval '5 minutes')",[$alumno,hash('sha256',$token)]);
    $entorno=array_merge(getenv(),['PGHOST'=>'127.0.0.1','PGPORT'=>'5433','PGDATABASE'=>getenv('MULTIPRO_TEST_DB'),
        'PGUSER'=>'multipro_app_local','PGPASSWORD'=>getenv('MULTIPRO_TEST_APP_PASSWORD'),'PGSSLMODE'=>'disable']);
    $ocupado=@fsockopen('127.0.0.1',8082,$errno,$errstr,0.05);
    if($ocupado){fclose($ocupado);throw new RuntimeException('PUERTO_FIXTURE_OCUPADO');}
    $logServidor=tempnam(sys_get_temp_dir(),'multipro-http-');
    $pipes=[];$server=proc_open([PHP_BINARY,'-c',getenv('MULTIPRO_TEST_PHP_INI'),'-S','127.0.0.1:8082','-t',realpath(__DIR__.'/../../web')],
        [0=>['pipe','r'],1=>['file',$logServidor,'a'],2=>['file',$logServidor,'a']],$pipes,null,$entorno);
    if(!is_resource($server))throw new RuntimeException('SERVIDOR_FIXTURE_NO_INICIADO');
    fclose($pipes[0]);
    try{
        $listo=false;for($n=0;$n<40;$n++){$socket=@fsockopen('127.0.0.1',8082,$errno,$errstr,0.05);if($socket){fclose($socket);$listo=true;break;}usleep(50000);}
        if(!$listo)throw new RuntimeException('SERVIDOR_FIXTURE_NO_LISTO');
        $http=function(string $ruta,array $datos)use($token):array{
            $ctx=stream_context_create(['http'=>['method'=>'POST','timeout'=>5,'ignore_errors'=>true,
                'header'=>"Content-Type: application/json\r\nAuthorization: Bearer ".$token."\r\n",'content'=>json_encode($datos)]]);
            $body=file_get_contents('http://127.0.0.1:8082/api/v1/reservas/'.$ruta.'.php',false,$ctx);
            preg_match('/ ([0-9]{3}) /',$http_response_header[0]??'',$m);
            return [(int)($m[1]??0),json_decode($body?:'{}',true)];
        };
        [$status,$data]=$http('crear',['espacio_id'=>$espacioApi,'fecha'=>'2099-04-01','horario'=>'01:00']);igual($status,201,'API_GRATUITA_CREA');
        $apiId=(int)$data['data']['id'];
        [$status]=$http('cancelar',['reserva_id'=>$apiId]);igual($status,200,'API_GRATUITA_CANCELA');
        igual(q($admin,'SELECT cancelado_por_usuario FROM reservas WHERE id=?',[$apiId])->fetchColumn(),$alumno,'API_AUDITORIA_TITULAR');
        q($admin,'UPDATE espacios SET precio_hora_ars=100 WHERE id=?',[$espacioApi]);
        [$status]=$http('crear',['espacio_id'=>$espacioApi,'fecha'=>'2099-04-02','horario'=>'01:00']);igual($status,409,'API_PRECIO_NO_ELUDIBLE');
        echo "OK: HTTP autenticado crear/cancelar gratis y rechazar espacio con precio.\n";
        // Administracion web: usuarios y contrasenas efimeros solo en esta base temporal.
        $passwordFixture=bin2hex(random_bytes(24));
        $crearUsuario=function(string $rol,string $email)use($admin,$entidadApi,$passwordFixture):int {
            return (int)q($admin,"INSERT INTO usuarios(nombre,apellido,dni,email,contrasena,fk_entidad,fk_rol,estado)
                SELECT 'Fixture','MultiPro',?,?,?, ?,id,true FROM roles WHERE nombre=? RETURNING id",
                ['fixture-'.bin2hex(random_bytes(5)),$email,password_hash($passwordFixture,PASSWORD_DEFAULT),$entidadApi,$rol])->fetchColumn();
        };
        $adminFixture=$crearUsuario('Administrador','admin-fixture@multipro.invalid');
        $alumnoFixture=$crearUsuario('Alumno','alumno-fixture@multipro.invalid');
        $web=function(string $ruta,?array $datos,string &$cookie):array {
            $opciones=['method'=>$datos===null?'GET':'POST','timeout'=>5,'ignore_errors'=>true,'follow_location'=>0];
            $opciones['header']='Cookie: '.$cookie."\r\n";
            if($datos!==null){$opciones['header'].="Content-Type: application/x-www-form-urlencoded\r\n";$opciones['content']=http_build_query($datos);}
            $html=file_get_contents('http://127.0.0.1:8082/'.$ruta,false,stream_context_create(['http'=>$opciones]));
            foreach($http_response_header??[] as $h){if(preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i',$h,$match))$cookie=$match[1];}
            preg_match('/ ([0-9]{3}) /',$http_response_header[0]??'',$m);
            return [(int)($m[1]??0),$html?:''];
        };
        $cookieAdmin='';$cookieAlumno='';
        [$status]=$web('login.php',['action'=>'login','email'=>'admin-fixture@multipro.invalid','contrasena'=>$passwordFixture],$cookieAdmin);igual($status,302,'LOGIN_ADMIN');
        [$status,$html]=$web('abm/espacios.php',null,$cookieAdmin);igual($status,200,'FORM_ADMIN');
        preg_match('/name="csrf_espacios" value="([a-f0-9]+)"/',$html,$m);$csrf=$m[1]??'';if($csrf==='')throw new RuntimeException('CSRF_NO_PRESENTE');
        $baseForm=['action'=>'crear','csrf_espacios'=>$csrf,'nombre'=>'Salon cobros experimental 80001','descripcion'=>'Fixture','imagenURL'=>'','precio_hora_ars'=>'0','pago_presencial_habilitado'=>'1'];
        [$status,$html]=$web('abm/espacios.php',$baseForm,$cookieAdmin);igual($status,200,'ALTA_WEB');
        $config=q($admin,'SELECT * FROM espacios WHERE nombre=? AND fk_entidad=?',[$baseForm['nombre'],$entidadApi])->fetch();
        if(!$config)throw new RuntimeException('ALTA_NO_GUARDADA');
        igual($config['precio_hora_ars'],'0.00','CERO_GRATIS');igual($config['pago_presencial_habilitado'],false,'CERO_SIN_COBRO');
        $configId=(int)$config['id'];$baseForm['action']='editar';$baseForm['id']=(string)$configId;
        $baseForm['precio_hora_ars']='1250,50';
        [$status,$html]=$web('abm/espacios.php',$baseForm,$cookieAdmin);
        $config=q($admin,'SELECT * FROM espacios WHERE id=?',[$configId])->fetch();
        igual($config['precio_hora_ars'],'1250.50','COMA_DECIMAL');igual($config['pago_presencial_habilitado'],true,'PRESENCIAL_GUARDADO');igual($config['cobro_online_habilitado'],false,'ONLINE_BLOQUEADO');
        igual(str_contains($html,'$ 1.250,50 ARS / hora'),true,'PRECIO_VISIBLE');
        $doc=new DOMDocument();@$doc->loadHTML($html);$xpath=new DOMXPath($doc);
        igual($xpath->query('//div[@id="editEspacioModal'.$configId.'"]//input[@name="precio_hora_ars"]')->item(0)?->getAttribute('value'),'1250.50','PRECIO_RECARGADO');
        igual($xpath->query('//div[@id="editEspacioModal'.$configId.'"]//input[@data-pago-presencial and @checked]')->length,1,'CHECK_RECARGADO');
        igual($xpath->query('//input[starts-with(@id,"online") and not(@disabled)]')->length,0,'ONLINE_DISABLED');
        foreach(['-1','NaN','1e3','1.234,56','1.234','10000000000','<script>',''] as $invalido){
            $form=$baseForm;$form['precio_hora_ars']=$invalido;[$status,$respuesta]=$web('abm/espacios.php',$form,$cookieAdmin);
            igual(q($admin,'SELECT precio_hora_ars FROM espacios WHERE id=?',[$configId])->fetchColumn(),'1250.50','RECHAZO_PRECIO');
            igual(str_contains($respuesta,'Ingresá un precio'),true,'ERROR_PRECIO_VISIBLE');
        }
        $form=$baseForm;$form['cobro_online_habilitado']='1';$web('abm/espacios.php',$form,$cookieAdmin);
        igual(q($admin,'SELECT cobro_online_habilitado FROM espacios WHERE id=?',[$configId])->fetchColumn(),false,'POST_ONLINE_RECHAZADO');
        $form=$baseForm;$form['csrf_espacios']='invalido';$form['precio_hora_ars']='2';$web('abm/espacios.php',$form,$cookieAdmin);
        igual(q($admin,'SELECT precio_hora_ars FROM espacios WHERE id=?',[$configId])->fetchColumn(),'1250.50','CSRF_RECHAZADO');
        $ajeno=$espacio['id'];$antesAjeno=q($admin,'SELECT to_jsonb(e)::text FROM espacios e WHERE id=?',[$ajeno])->fetchColumn();
        $form=$baseForm;$form['id']=(string)$ajeno;$form['nombre']='Solicitud ajena 90123';$web('abm/espacios.php',$form,$cookieAdmin);
        igual(q($admin,'SELECT to_jsonb(e)::text FROM espacios e WHERE id=?',[$ajeno])->fetchColumn(),$antesAjeno,'OTRA_ENTIDAD_PROTEGIDA');
        [$status]=$web('login.php',['action'=>'login','email'=>'alumno-fixture@multipro.invalid','contrasena'=>$passwordFixture],$cookieAlumno);igual($status,302,'LOGIN_ALUMNO_WEB');
        [$status]=$web('abm/espacios.php',null,$cookieAlumno);igual($status,302,'ALUMNO_NO_ADMIN');
        q($admin,'UPDATE espacios SET pago_presencial_habilitado=false WHERE id=?',[$configId]);
        [$status,$html]=$web('espacio.php?id='.$configId.'&fecha=2099-04-03',null,$cookieAlumno);
        igual(str_contains($html,'no tiene un medio de pago disponible'),true,'AVISO_PUBLICO_PAGO');
        igual(str_contains($html,'value="reservar"'),false,'SIN_FORM_PAGO');
        // Obtener CSRF desde una pagina de perfil de la misma sesion.
        [$status,$perfil]=$web('perfil.php',null,$cookieAlumno);
        // No hay reservas para este alumno aun: la pagina del espacio gratuito incluye el formulario.
        [$status,$paginaGratis]=$web('espacio.php?id='.$espacioApi.'&fecha=2099-04-03',null,$cookieAlumno);
        q($admin,'UPDATE espacios SET precio_hora_ars=0 WHERE id=?',[$espacioApi]);
        [$status,$paginaGratis]=$web('espacio.php?id='.$espacioApi.'&fecha=2099-04-03',null,$cookieAlumno);
        preg_match('/name="csrf_reservas" value="([a-f0-9]+)"/',$paginaGratis,$mt);$csrfAlumno=$mt[1]??'';
        if($csrfAlumno==='')throw new RuntimeException('FALTA_CSRF_RESERVA');
        $resForm=['action'=>'reservar','fecha'=>'2099-04-03','horario'=>'01:00:00','precio_visto'=>'0.00','modalidad_pago'=>'gratuito','csrf_reservas'=>$csrfAlumno];
        $web('espacio.php?id='.$configId.'&fecha=2099-04-03',$resForm,$cookieAlumno);
        igual((int)q($admin,'SELECT count(*) FROM reservas WHERE fk_espacio=?',[$configId])->fetchColumn(),0,'POST_PAGO_NO_GRATIS');
        $baseForm['precio_hora_ars']='0';$web('abm/espacios.php',$baseForm,$cookieAdmin);
        [$status,$html]=$web('espacio.php?id='.$configId.'&fecha=2099-04-03',$resForm,$cookieAlumno);
        igual(str_contains($html,'¡Reserva completada con éxito!'),true,'RESERVA_WEB_GRATIS');
        $reservada=q($admin,'SELECT importe_total_ars,modalidad_pago FROM reservas WHERE fk_espacio=?',[$configId])->fetch();
        igual($reservada['importe_total_ars'],'0.00','WEB_IMPORTE_CERO');igual($reservada['modalidad_pago'],'gratuito','WEB_MODALIDAD_GRATIS');
        $baseForm['precio_hora_ars']='2500.75';$web('abm/espacios.php',$baseForm,$cookieAdmin);
        igual(q($admin,'SELECT importe_total_ars FROM reservas WHERE fk_espacio=?',[$configId])->fetchColumn(),'0.00','PRECIO_NO_CAMBIA_HISTORICO');
        require __DIR__.'/presencial.php';
        require __DIR__.'/api_presencial.php';
        $web('logout.php',null,$cookieAdmin);$web('logout.php',null,$cookieAlumno);
        echo "OK: administracion web precio/presencial, validaciones, CSRF, aislamiento por entidad y reserva web gratuita; sin medio de pago no permite reservar.\n";
    }finally{
        proc_terminate($server);proc_close($server);unlink($logServidor);
    }
    echo "OK: servicio PHP, titular, correlacion, idempotencia, duplicados, devolucion con timeout, vencimientos, concurrencia real y reserva gratuita.\n";
} catch(Throwable $e) {
    if ($e instanceof PDOException && preg_match('/permission denied for (?:table|sequence) [a-z0-9_]+/', $e->getMessage(), $detalle)) fwrite(STDERR,$detalle[0]."\n");
    fwrite(STDERR,($e instanceof PDOException ? 'ERROR_DB_'.$e->getCode() : $e->getMessage())."\n");exit(1);
}
