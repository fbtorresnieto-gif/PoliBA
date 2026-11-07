<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/web/includes/CatalogoGeoref.php';
require dirname(__DIR__,2).'/web/includes/pagos/ServicioReservas.php';
$base=getenv('MULTIPRO_QA_DB');$carpeta=getenv('MULTIPRO_QA_DIR');
if(!is_string($base)||!preg_match('/^multipro_qa_pantallas_[0-9]{14}$/D',$base)||!is_dir((string)$carpeta))throw new RuntimeException('Solo se admite el entorno QA aislado.');
$p=new PDO('pgsql:host=127.0.0.1;port=5433;dbname='.$base.';sslmode=disable',(string)getenv('MULTIPRO_TEST_USER'),(string)getenv('MULTIPRO_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function qi(PDO $p,string $sql,array $v=[]):int {$s=$p->prepare($sql.' RETURNING id');$s->execute($v);return (int)$s->fetchColumn();}
CatalogoGeoref::importar($p,dirname(__DIR__,2).'/database/catalogos/georef');
$roles=[];foreach(['Gestor','Administrador','Profesor','Alumno'] as $rol)$roles[$rol]=qi($p,'INSERT INTO roles(nombre) VALUES(?)',[$rol]);
$dias=[];foreach(['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'] as $i=>$dia)$dias[]=qi($p,'INSERT INTO dias(nombre,orden) VALUES(?,?)',[$dia,$i+1]);
$cat=qi($p,"INSERT INTO categoria(nombre,edad_minima,edad_maxima) VALUES('General QA',0,99)");
$e=qi($p,"INSERT INTO entidades(cuit,nombre,direccion,horario_apertura,horario_cierre,fk_dia_apertura,fk_dia_cierre) VALUES('30987654321','QA Entidad de prueba','Dirección ficticia de QA','08:00','22:00',?,?)",[$dias[0],$dias[6]]);
$clave=bin2hex(random_bytes(18));$cuentas=[];
foreach(['Gestor'=>'gestor','Administrador'=>'admin','Profesor'=>'profesor','Alumno'=>'alumno'] as $rol=>$slug) {
    $email=$slug.'@qa.multipro.invalid';$id=qi($p,"INSERT INTO usuarios(nombre,apellido,dni,email,contrasena,fecha_nacimiento,fk_entidad,fk_rol) VALUES(?,'Prueba local',?,?,?,'2000-01-01',?,?)",['QA '.$rol,'QA-'.$slug,$email,password_hash($clave,PASSWORD_DEFAULT),$e,$roles[$rol]]);
    $cuentas[$slug]=['id'=>$id,'email'=>$email];
}
$al2=qi($p,"INSERT INTO usuarios(nombre,apellido,dni,email,contrasena,fecha_nacimiento,fk_entidad,fk_rol) VALUES('QA Alumno espera','Prueba local','QA-espera','espera@qa.multipro.invalid',?,'2001-01-01',?,?)",[password_hash($clave,PASSWORD_DEFAULT),$e,$roles['Alumno']]);
$a=qi($p,"INSERT INTO actividades(nombre,texto,fk_entidad) VALUES('QA Actividad','Actividad ficticia para validar suspensión y reapertura.',?)",[$e]);
$s1=qi($p,"INSERT INTO espacios(nombre,descripcion,fk_entidad,precio_hora_ars,pago_presencial_habilitado) VALUES('QA Espacio origen','Espacio ficticio; importes simulados de QA.',?,100,true)",[$e]);
$s2=qi($p,"INSERT INTO espacios(nombre,descripcion,fk_entidad) VALUES('QA Espacio destino','Destino ficticio para reubicar el módulo.',?)",[$e]);
$m=qi($p,"INSERT INTO modulos(nombre,descripcion,horario_inicio,horario_cierre,cupo_maximo,fk_usuario_profesor,fk_actividad,fk_espacio,fk_categoria,fk_entidad) VALUES('QA Módulo','Módulo de prueba local','15:00','16:00',1,?,?,?,?,?)",[$cuentas['profesor']['id'],$a,$s1,$cat,$e]);
$p->prepare('INSERT INTO dias_modulos(fk_modulo,fk_dia) VALUES(?,?)')->execute([$m,$dias[0]]);
$i1=qi($p,'INSERT INTO inscripcion(fk_modulo,fk_usuario,lista_espera) VALUES(?,?,false)',[$m,$cuentas['alumno']['id']]);
$i2=qi($p,'INSERT INTO inscripcion(fk_modulo,fk_usuario,lista_espera) VALUES(?,?,true)',[$m,$al2]);
$fecha=(new DateTimeImmutable('tomorrow',new DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');
$servicio=new \MultiPro\Pagos\ServicioReservas($p);
$r1=$servicio->crear($s1,$cuentas['alumno']['id'],$fecha,'10:00:00','100.00','presencial');
$r2=$servicio->crear($s1,$al2,$fecha,'11:00:00','100.00','presencial');
$publico=['base'=>$base,'url'=>'http://127.0.0.1:8087/','entidad'=>$e,'actividad'=>$a,'modulo'=>$m,'origen'=>$s1,'destino'=>$s2,'inscripciones'=>[$i1,$i2],'reservas'=>[(int)$r1['id'],(int)$r2['id']],'fecha'=>$fecha,'cuentas'=>$cuentas];
file_put_contents($carpeta.'/fixture-privada.json',json_encode($publico+['clave'=>$clave],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
file_put_contents($carpeta.'/fixture-publica.json',json_encode($publico,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo 'FIXTURE_QA_PANTALLAS_OK sin_correos sin_dinero_real'.PHP_EOL;
