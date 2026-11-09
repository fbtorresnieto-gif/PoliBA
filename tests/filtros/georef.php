<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/web/includes/CatalogoGeoref.php';
require dirname(__DIR__,2).'/web/includes/UbicacionEntidades.php';
$base=getenv('MULTIPRO_TEST_DB');
if(!is_string($base) || !preg_match('/^multipro_test_pagos_[0-9]{14}$/D',$base))throw new RuntimeException('Solo bases temporales.');
$db=new PDO('pgsql:host=127.0.0.1;port=5433;dbname='.$base.';sslmode=disable',(string)getenv('MULTIPRO_TEST_USER'),(string)getenv('MULTIPRO_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function gq(PDO $p,string $sql,array $v=[]): PDOStatement {$s=$p->prepare($sql);$s->execute($v);return $s;}
$total=0;
function ga(bool $ok,string $msg):void {global $total;$total++;if(!$ok)throw new RuntimeException('GEOREF_FALLO: '.$msg);}
function gr(callable $f,string $msg):void {try{$f();}catch(Throwable $e){ga(true,$msg);return;}ga(false,$msg);}
$directorio=dirname(__DIR__,2).'/database/catalogos/georef';
$datos=CatalogoGeoref::leer($directorio);
$huella=gq($db,"SELECT md5(string_agg(id||':'||COALESCE(fk_localidad::text,'null'),',' ORDER BY id)) FROM entidades")->fetchColumn();
$resultado=CatalogoGeoref::importar($db,$directorio);
ga($resultado===['provincias'=>24,'localidades'=>4028],'cantidades verificadas contra descarga completa');
ga((int)gq($db,'SELECT count(*) FROM provincias WHERE codigo_georef IS NOT NULL')->fetchColumn()===24,'24 jurisdicciones oficiales');
ga((int)gq($db,'SELECT count(*) FROM localidades WHERE codigo_georef IS NOT NULL')->fetchColumn()===4028,'4028 localidades con código propio');
ga(gq($db,"SELECT nombre FROM provincias WHERE codigo_georef='14'")->fetchColumn()==='Córdoba','UTF-8 y acentos intactos');
ga((int)gq($db,"SELECT count(*) FROM localidades l JOIN provincias p ON p.id=l.fk_provincia WHERE p.codigo_georef='06' AND l.nombre='La Plata' AND l.codigo_georef IS NOT NULL")->fetchColumn()===2,'no colapsa nombres homónimos de distinta categoría');
ga(gq($db,"SELECT md5(string_agg(id||':'||COALESCE(fk_localidad::text,'null'),',' ORDER BY id)) FROM entidades")->fetchColumn()===$huella,'conserva todos los vínculos de entidades existentes');
$ids=gq($db,"SELECT md5(string_agg(id||':'||codigo_georef,',' ORDER BY id)) FROM localidades WHERE codigo_georef IS NOT NULL")->fetchColumn();
CatalogoGeoref::importar($db,$directorio);
ga(gq($db,"SELECT md5(string_agg(id||':'||codigo_georef,',' ORDER BY id)) FROM localidades WHERE codigo_georef IS NOT NULL")->fetchColumn()===$ids,'reimportación idempotente conserva IDs');
$provincia=(int)gq($db,"SELECT id FROM provincias WHERE codigo_georef='06'")->fetchColumn();
$localidad=(int)gq($db,"SELECT id FROM localidades WHERE codigo_georef='06441030'")->fetchColumn();
$otra=(int)gq($db,"SELECT id FROM localidades WHERE codigo_georef='14014010'")->fetchColumn();
ga(UbicacionEntidades::seleccionar($db,(string)$provincia,(string)$localidad)===$localidad,'selección por identificador local');
gr(fn()=>UbicacionEntidades::seleccionar($db,$provincia,$otra),'rechaza localidad de otra provincia');
gr(fn()=>UbicacionEntidades::seleccionar($db,[$provincia],$localidad),'rechaza estructura manipulada');
$db->beginTransaction();try {gr(fn()=>UbicacionEntidades::guardar($db,'Buenos Aires','La Plata'),'carga manual ambigua exige catálogo');}finally{$db->rollBack();}
$db->beginTransaction();$manual=UbicacionEntidades::guardar($db,'Buenos Aires','Localidad ficticia no oficial de QA');$db->commit();
ga(gq($db,'SELECT codigo_georef FROM localidades WHERE id=?',[$manual])->fetchColumn()===null,'permite excepción manual sin adjudicar código oficial');
$labels=[];foreach($datos['localidades'] as $l){$label=$l['provincia_id'].'|'.UbicacionEntidades::etiqueta($l);ga(!isset($labels[$label]),'etiqueta oficial inequívoca');$labels[$label]=true;}
// Archivo alterado: se rechaza antes de iniciar la escritura.
$temporal=sys_get_temp_dir().'/multipro-georef-'.bin2hex(random_bytes(6));mkdir($temporal);
try {
    copy($directorio.'/manifest.json',$temporal.'/manifest.json');copy($directorio.'/localidades.json',$temporal.'/localidades.json');file_put_contents($temporal.'/provincias.json','[]');
    gr(fn()=>CatalogoGeoref::importar($db,$temporal),'archivo manipulado rechazado');
} finally {foreach(['manifest.json','localidades.json','provincias.json'] as $archivo)unlink($temporal.'/'.$archivo);rmdir($temporal);}

// HTTP real: catálogo privado, formulario Gestor y validación de pertenencia al guardar.
$clave=bin2hex(random_bytes(20));$tag=bin2hex(random_bytes(5));$email='georef-'.$tag.'@example.invalid';
gq($db,"INSERT INTO usuarios(nombre,apellido,dni,email,contrasena,fk_rol) VALUES('Fixture','Georef',?,?,?,(SELECT id FROM roles WHERE lower(nombre)='gestor' LIMIT 1))",['geo-'.$tag,$email,password_hash($clave,PASSWORD_DEFAULT)]);
$entorno=array_merge(getenv(),['PGHOST'=>'127.0.0.1','PGPORT'=>'5433','PGDATABASE'=>$base,'PGUSER'=>'multipro_app_local','PGPASSWORD'=>getenv('MULTIPRO_TEST_APP_PASSWORD'),'PGSSLMODE'=>'disable','MAIL_ENABLED'=>'false']);
$socket=@fsockopen('127.0.0.1',8086,$errno,$error,0.1);if($socket){fclose($socket);throw new RuntimeException('Puerto QA ocupado.');}
$log=tempnam(sys_get_temp_dir(),'multipro-geo-http-');$pipes=[];
$server=proc_open([PHP_BINARY,'-c',getenv('MULTIPRO_TEST_PHP_INI'),'-S','127.0.0.1:8086','-t',realpath(__DIR__.'/../../web')],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,null,$entorno);fclose($pipes[0]);
try {
    $listo=false;for($n=0;$n<40;$n++){$sock=@fsockopen('127.0.0.1',8086,$errno,$error,0.1);if($sock){fclose($sock);$listo=true;break;}usleep(50000);}ga($listo,'servidor HTTP listo');
    $cookie='';
    $http=static function(string $ruta,?array $datos=null)use(&$cookie):array {
        $op=['method'=>$datos===null?'GET':'POST','timeout'=>10,'ignore_errors'=>true,'follow_location'=>0,'header'=>'Cookie: '.$cookie."\r\n"];
        if($datos!==null){$op['header'].="Content-Type: application/x-www-form-urlencoded\r\n";$op['content']=http_build_query($datos);}
        $html=file_get_contents('http://127.0.0.1:8086/'.$ruta,false,stream_context_create(['http'=>$op]));
        foreach($http_response_header??[] as $h)if(preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i',$h,$m))$cookie=$m[1];
        preg_match('/ ([0-9]{3}) /',$http_response_header[0]??'',$m);return [(int)($m[1]??0),(string)$html];
    };
    [$codigo]=$http('abm/ubicaciones.php?provincia_id='.$provincia);ga($codigo===302,'catálogo administrativo requiere autenticación');
    [$codigo]=$http('login.php',['action'=>'login','email'=>$email,'contrasena'=>$clave]);ga($codigo===302,'login fixture');
    [$codigo,$html]=$http('abm/ubicaciones.php?provincia_id='.$provincia);$opciones=json_decode($html,true,512,JSON_THROW_ON_ERROR)['data'];
    ga($codigo===200 && count($opciones)>100,'catálogo local por provincia');
    ga(count(array_filter($opciones,fn($o)=>$o['id']===$localidad))===1,'cada opción conserva identificador');
    [$codigo]=$http('abm/ubicaciones.php?provincia_id=0');ga($codigo===422,'provincia inválida rechazada');
    [$codigo,$html]=$http('abm/entidades.php');ga($codigo===200 && str_contains($html,'name="provincia_id"') && str_contains($html,'name="localidad_id"') && str_contains($html,'ubicaciones.js'),'formulario de selección dependiente');
    preg_match('/name="csrf_entidades" value="([a-f0-9]+)"/',$html,$m);$csrf=$m[1]??'';ga($csrf!=='','formulario protegido');
    $form=['action'=>'crear','csrf_entidades'=>$csrf,'nombre'=>'Fixture catálogo '.$tag,'cuit'=>'30'.random_int(10000000,99999999).'1','tipo_entidad'=>'publica','direccion'=>'Dirección ficticia de QA','horario_apertura'=>'08:00','horario_cierre'=>'22:00','coordenadas'=>'','informacion'=>'QA','imagenURL'=>'','ubicacion_modo'=>'catalogo','provincia_id'=>$provincia,'localidad_id'=>$localidad];
    [$codigo,$html]=$http('abm/entidades.php',$form);ga($codigo===200 && str_contains($html,'Entidad creada con éxito'),'alta de entidad con localidad oficial');
    ga((int)gq($db,'SELECT fk_localidad FROM entidades WHERE nombre=?',[$form['nombre']])->fetchColumn()===$localidad,'vincula por ID sin generar ubicación nueva');
    $form['nombre'].=' inválida';$form['localidad_id']=$otra;
    [$codigo,$html]=$http('abm/entidades.php',$form);ga($codigo===200 && str_contains($html,'no pertenece'),'formulario rechaza combinación manipulada');
    ga((int)gq($db,'SELECT count(*) FROM entidades WHERE nombre=?',[$form['nombre']])->fetchColumn()===0,'sin alta parcial');
} finally {proc_terminate($server);proc_close($server);unlink($log);}
echo 'GEOREF_OFICIAL_OK provincias=24 localidades=4028 comprobaciones='.$total.PHP_EOL;
