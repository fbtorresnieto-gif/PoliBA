<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/web/includes/ServicioEstados.php';
require dirname(__DIR__,2).'/web/includes/FiltroEntidades.php';
require dirname(__DIR__,2).'/web/includes/UbicacionEntidades.php';

function cdb(bool $app=true): PDO {
    $base=getenv('MULTIPRO_TEST_DB');
    if(!is_string($base) || !preg_match('/^multipro_test_pagos_[0-9]{14}$/D',$base))throw new RuntimeException('Solo se admiten bases temporales.');
    $p=new PDO('pgsql:host=127.0.0.1;port=5433;dbname='.$base.';sslmode=disable',(string)getenv('MULTIPRO_TEST_USER'),(string)getenv('MULTIPRO_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if($app)$p->exec('SET ROLE multipro_app_local');return $p;
}
function cq(PDO $p,string $sql,array $v=[]): PDOStatement {$s=$p->prepare($sql);$s->execute($v);return $s;}
$casos=0;
function ca(bool $ok,string $mensaje): void {global $casos;$casos++;if(!$ok)throw new RuntimeException('FALLO: '.$mensaje);}
function cr(callable $f,string $mensaje): void {try{$f();}catch(Throwable $e){ca(true,$mensaje);return;}ca(false,$mensaje);}
if(($argv[1]??'')==='worker') {
    try {
    $p=cdb();$s=new ServicioEstados($p);
    if($argv[2]==='baja')$s->cambiar('espacio',(int)$argv[3],false,(int)$argv[4],'Baja concurrente');
    elseif($argv[2]==='cobro')(new \MultiPro\Pagos\ServicioReservas($p))->registrarCobro((int)$argv[3],(int)$argv[4]);
    elseif($argv[2]==='crear')(new \MultiPro\Pagos\ServicioReservas($p))->crear((int)$argv[3],(int)$argv[4],$argv[5],$argv[6],'0.00','gratuito');
    elseif($argv[2]==='admin')$s->cambiarAdministrador((int)$argv[3],false,(int)$argv[4],'Baja concurrente de responsables');
    echo 'OK';exit;
    } catch(RuntimeException $e) {echo 'RECHAZADA';exit(2);}
}
$root=cdb(false);$p=cdb();$s=new ServicioEstados($p);$reservas=new \MultiPro\Pagos\ServicioReservas($p);
$id=static fn(string $sql,array $v=[])=>(int)cq($root,$sql.' RETURNING id',$v)->fetchColumn();
$e=$id("INSERT INTO entidades(nombre,direccion,horario_apertura,horario_cierre,fk_dia_apertura,fk_dia_cierre) VALUES('Fixture cascada','Dirección ficticia','00:00','23:59:59',(SELECT id FROM dias WHERE orden=1 LIMIT 1),(SELECT id FROM dias WHERE orden=7 LIMIT 1))");
$otra=$id("INSERT INTO entidades(nombre,direccion,horario_apertura,horario_cierre) VALUES('Otra fixture','Dirección ficticia','00:00','23:59:59')");
$usuario=static function(string $rol,int $entidad,string $sufijo)use($id):int {
    return $id("INSERT INTO usuarios(nombre,apellido,dni,email,contrasena,fecha_nacimiento,fk_entidad,fk_rol) VALUES('Fixture','Cascada',?,?,'sin-login','2000-01-01',?,(SELECT id FROM roles WHERE lower(nombre)=lower(?) LIMIT 1))",['cascada-'.$sufijo,$sufijo.'@example.invalid',$entidad,$rol]);
};
$gestor=$usuario('Gestor',$e,'gestor');$admin=$usuario('Administrador',$e,'admin');$admin2=$usuario('Administrador',$e,'admin2');$ajeno=$usuario('Administrador',$otra,'ajeno');
$alumno=$usuario('Alumno',$e,'alumno');$alumno2=$usuario('Alumno',$e,'alumno2');$alumno3=$usuario('Alumno',$e,'alumno3');$profesor=$usuario('Profesor',$e,'profesor');
$a=$id("INSERT INTO actividades(nombre,fk_entidad) VALUES('Fixture actividad',?)",[$e]);
$espacio=$id("INSERT INTO espacios(nombre,fk_entidad) VALUES('Fixture libre',?)",[$e]);
$pago=$id("INSERT INTO espacios(nombre,fk_entidad,precio_hora_ars,pago_presencial_habilitado) VALUES('Fixture presencial',?,100,true)",[$e]);
$otroEspacio=$id("INSERT INTO espacios(nombre,fk_entidad) VALUES('Fixture reubicación',?)",[$e]);
$m=$id("INSERT INTO modulos(nombre,horario_inicio,horario_cierre,cupo_maximo,fk_actividad,fk_entidad,fk_espacio,fk_usuario_profesor) VALUES('Fixture módulo','15:00','16:00',2,?,?,?,?)",[$a,$e,$espacio,$profesor]);
$inactivo=$id("INSERT INTO modulos(nombre,horario_inicio,horario_cierre,cupo_maximo,fk_actividad,fk_entidad,estado) VALUES('Inactivo previo','17:00','18:00',2,?,?,false)",[$a,$e]);
$i1=$id("INSERT INTO inscripcion(fk_modulo,fk_usuario,lista_espera) VALUES(?,?,false)",[$m,$alumno]);
$i2=$id("INSERT INTO inscripcion(fk_modulo,fk_usuario,lista_espera) VALUES(?,?,true)",[$m,$alumno2]);
$cancelada=$id("INSERT INTO inscripcion(fk_modulo,fk_usuario,estado) VALUES(?,?,'cancelado')",[$m,$alumno3]);
$asistencia=$id("INSERT INTO asistencia(fk_modulo,fk_inscripcion,asistencia,fecha) VALUES(?,?,'presente','2026-01-01')",[$m,$i1]);
$fecha= new DateTimeImmutable('now',new DateTimeZone('America/Argentina/Buenos_Aires'));
$futuro=$fecha->modify('+1 day')->format('Y-m-d');
cr(fn()=>cq($p,"INSERT INTO asistencia(fk_modulo,fk_inscripcion,asistencia,fecha,registrado_por) VALUES(?,?,'presente',?,?)",[$m,$i1,$futuro,$profesor]),'base rechaza asistencia futura');
cq($p,"UPDATE asistencia SET asistencia='ausente',registrado_por=?,actualizado_en=clock_timestamp() WHERE id=?",[$profesor,$asistencia]);
$auditoria=cq($p,'SELECT asistencia,registrado_por,creado_en,actualizado_en FROM asistencia WHERE id=?',[$asistencia])->fetch();
ca($auditoria['asistencia']==='ausente' && (int)$auditoria['registrado_por']===$profesor,'auditoría registra profesor y corrección');
ca(strtotime($auditoria['actualizado_en'])>=strtotime($auditoria['creado_en']),'auditoría conserva fechas');
$turno=$fecha->modify('+2 hours');if($turno->format('H')==='23')$turno=$turno->modify('+1 hour');
$dia=$turno->format('Y-m-d');$hora=$turno->format('H').':00:00';
$rLibre=$reservas->crear($espacio,$alumno,$dia,$hora,'0.00','gratuito');
$rPagada=$reservas->crear($pago,$alumno2,$dia,$hora,'100.00','presencial');$reservas->registrarCobro((int)$rPagada['id'],$admin);
$dia2=$turno->modify('+2 days')->format('Y-m-d');
$rSinCobro=$reservas->crear($pago,$alumno3,$dia2,$hora,'100.00','presencial');
$pasada=$id("INSERT INTO reservas(fk_espacio,fk_usuario,fecha_de_asistencia,horario,estado,modalidad_pago,importe_total_ars) VALUES(?,?,'2020-01-01','08:00','reservado','gratuito',0)",[$espacio,$alumno]);

// Ubicación normalizada, homónimos por provincia, entidades sin ubicación e inactivas.
$p->beginTransaction();$lp=UbicacionEntidades::guardar($p,UbicacionEntidades::normalizar('  Buenos   Aires '),'Capital');$lc=UbicacionEntidades::guardar($p,'Córdoba','Capital');$igual=UbicacionEntidades::guardar($p,'buenos aires','capital');$p->commit();
ca($lp===$igual && $lp!==$lc,'normalización y localidades homónimas');
cq($p,'UPDATE entidades SET fk_localidad=? WHERE id=?',[$lp,$e]);cq($p,'UPDATE entidades SET fk_localidad=? WHERE id=?',[$lc,$otra]);
$provincia=(int)cq($p,'SELECT fk_provincia FROM localidades WHERE id=?',[$lp])->fetchColumn();
$f=FiltroEntidades::cargar($p,['provincias'=>[$provincia],'localidades'=>[$lp],'entidades'=>[$e]]);[$sql,$args]=$f->condicion('e.id');
ca(array_map('intval',cq($p,'SELECT e.id FROM entidades e WHERE e.estado'.$sql,$args)->fetchAll(PDO::FETCH_COLUMN))===[$e],'filtro PostgreSQL combinado');
cr(fn()=>$s->cambiar('espacio',$espacio,false,$ajeno,'Ajeno'),'aislamiento por entidad');
cr(fn()=>$s->cambiar('entidad',$e,false,$admin,'Sin rol'),'solo Gestor cierra entidad');
cr(fn()=>$s->cambiar('espacio',$espacio,false,$admin,'Bloqueado'),'espacio con módulo activo bloqueado');
ca(cq($p,'SELECT estado FROM reservas WHERE id=?',[$rLibre['id']])->fetchColumn()==='reservado','baja bloqueada no cancela reserva');
ca(cq($p,'SELECT estado FROM inscripcion WHERE id=?',[$i1])->fetchColumn()==='activo','baja bloqueada no suspende');
$preview=$s->previsualizar('espacio',$pago,$admin);
$resExtra=$reservas->crear($pago,$alumno,$turno->modify('+3 days')->format('Y-m-d'),$hora,'100.00','presencial');
cr(fn()=>$s->cambiar('espacio',$pago,false,$admin,'Vista vieja',$preview['firma']),'cambio de impacto obliga a revisar');
ca((bool)cq($p,'SELECT estado FROM espacios WHERE id=?',[$pago])->fetchColumn(),'vista vieja no cambia estado');
// Actividad -> módulo e inscripciones; recuperación explícita, conserva asiento/espera y canceladas.
$s->cambiar('actividad',$a,false,$admin,'Suspensión actividad');
ca(cq($p,'SELECT estado FROM inscripcion WHERE id=?',[$i1])->fetchColumn()==='suspendido','suspende inscripción');
ca((bool)cq($p,'SELECT lista_espera FROM inscripcion WHERE id=?',[$i2])->fetchColumn(),'preserva espera');
cr(fn()=>$s->cambiar('modulo',$m,true,$admin,'Aún no'),'actividad inactiva impide módulo');
cr(fn()=>cq($p,"INSERT INTO asistencia(fk_modulo,fk_inscripcion,asistencia,fecha) VALUES(?,?,'presente','2026-01-02')",[$m,$i1]),'asistencia bloqueada suspendida');
$s->cambiar('actividad',$a,true,$admin,'Reabrir actividad');
ca(!cq($p,'SELECT estado FROM modulos WHERE id=?',[$m])->fetchColumn(),'actividad no reactiva hijos');
ca(cq($p,'SELECT desactivacion_operacion FROM modulos WHERE id=?',[$inactivo])->fetchColumn()===null,'conserva causa de inactivo previo');
$s->cambiar('modulo',$m,true,$admin,'Reabrir módulo');
ca($s->reactivarInscripciones($m,$admin,'Recuperar conjunto')===2,'reactivación conjunta');
ca(cq($p,'SELECT estado FROM inscripcion WHERE id=?',[$cancelada])->fetchColumn()==='cancelado','canceladas no reviven');
ca((bool)cq($p,'SELECT lista_espera FROM inscripcion WHERE id=?',[$i2])->fetchColumn(),'espera no pasa a cupo');
ca($s->reactivarInscripciones($m,$admin,'Repetir')===0,'reactivación idempotente');
ca((int)cq($p,"SELECT count(*) FROM operaciones_estado o, jsonb_array_elements(o.situacion_anterior->'inscripciones') i WHERE o.fk_entidad=? AND o.accion='desactivar' AND i->>'id'=? AND i->>'estado'='activo'",[$e,(string)$i1])->fetchColumn()>0,'historia de suspensión conservada tras reactivar');
// Reubicación: la baja afecta al espacio, el módulo sigue operando en el destino.
cq($p,'UPDATE modulos SET fk_espacio=? WHERE id=?',[$otroEspacio,$m]);
$s->cambiar('espacio',$espacio,false,$admin,'Espacio reubicado');
ca(cq($p,'SELECT estado FROM reservas WHERE id=?',[$rLibre['id']])->fetchColumn()==='cancelado','cancela gratuita futura');
ca((bool)cq($p,'SELECT estado FROM modulos WHERE id=?',[$m])->fetchColumn(),'módulo reubicado sigue activo');
ca(cq($p,'SELECT estado FROM reservas WHERE id=?',[$pasada])->fetchColumn()==='reservado','no toca turno pasado');
$s->cambiar('espacio',$espacio,true,$admin,'Reabrir espacio');
ca(cq($p,'SELECT estado FROM reservas WHERE id=?',[$rLibre['id']])->fetchColumn()==='cancelado','reabrir no restaura reserva');
// Cierre de entidad por Gestor: devolución completa dentro de 24h, actor real y ningún dinero entregado.
$s->cambiar('entidad',$e,false,$gestor,'Cierre de oferta');
ca(cq($p,'SELECT estado FROM reservas WHERE id=?',[$rPagada['id']])->fetchColumn()==='cancelado','cancela presencial cobrada');
ca((int)cq($p,"SELECT count(*) FROM reserva_movimientos_presenciales WHERE fk_reserva=? AND tipo='devolucion_solicitada' AND fk_actor=?",[$rPagada['id'],$gestor])->fetchColumn()===1,'solicitud auditada con Gestor real');
ca((int)cq($p,"SELECT count(*) FROM reserva_movimientos_presenciales WHERE fk_reserva=? AND tipo='devolucion'",[$rPagada['id']])->fetchColumn()===0,'no entrega dinero');
ca(cq($p,'SELECT estado FROM reservas WHERE id=?',[$rSinCobro['id']])->fetchColumn()==='cancelado','cancela pendiente sin deuda');
ca((int)cq($p,"SELECT count(*) FROM reserva_movimientos_presenciales WHERE fk_reserva=?",[$rSinCobro['id']])->fetchColumn()===0,'sin cobro no genera devolución');
ca((int)cq($p,'SELECT multipro_pendientes_economicos(?)',[$e])->fetchColumn()===1,'solo devolución bloquea cierre');
cr(fn()=>$s->cerrarAdministracion($e,$gestor,'No debe cerrar'),'bloquea cierre con pendientes');
$s->cambiarAdministrador($admin,false,$gestor,'Segundo responsable');
cr(fn()=>$s->cambiarAdministrador($admin2,false,$gestor,'Último responsable'),'protege último responsable');
cr(fn()=>$reservas->registrarDevolucion((int)$rPagada['id'],$gestor),'Gestor no registra entrega');
$antes=(int)cq($p,'SELECT count(*) FROM reserva_movimientos_presenciales')->fetchColumn();
$s->cambiar('entidad',$e,false,$gestor,'Repetir cierre');
ca((int)cq($p,'SELECT count(*) FROM reserva_movimientos_presenciales')->fetchColumn()===$antes,'no duplica movimientos');
$reservas->registrarDevolucion((int)$rPagada['id'],$admin2);
$reservas->registrarDevolucion((int)$rPagada['id'],$admin2);
ca((int)cq($p,'SELECT multipro_pendientes_economicos(?)',[$e])->fetchColumn()===0,'entrega real resuelve pendiente con entidad inactiva');
$s->cerrarAdministracion($e,$gestor,'Sin pendientes');
ca((bool)cq($p,'SELECT cierre_administrativo_en FROM entidades WHERE id=?',[$e])->fetchColumn(),'cierre administrativo independiente');
$s->cambiarAdministrador($admin2,false,$gestor,'Sin pendientes');
$s->cambiarAdministrador($admin2,true,$gestor,'Responsable de reapertura');
$s->cambiar('entidad',$e,true,$gestor,'Reabrir entidad');
ca(!cq($p,'SELECT estado FROM modulos WHERE id=?',[$m])->fetchColumn(),'entidad no reactiva hijos');
ca(cq($p,'SELECT estado FROM inscripcion WHERE id=?',[$i1])->fetchColumn()==='suspendido','conserva suspendidas para reapertura');
ca((int)cq($p,'SELECT count(*) FROM asistencia WHERE id=?',[$asistencia])->fetchColumn()===1,'conserva asistencia histórica');
foreach(['entidades'=>$e,'modulos'=>$m,'inscripcion'=>$i1,'asistencia'=>$asistencia] as $tabla=>$registro)cr(fn()=>cq($p,"DELETE FROM $tabla WHERE id=?",[$registro]),'rechaza borrado físico '.$tabla);
cr(fn()=>cq($p,"UPDATE operaciones_estado SET motivo='alterado' WHERE fk_entidad=?",[$e]),'auditoría inmutable');

// Conflicto de capacidad al reabrir: no reasigna lugares ni reactiva parcialmente.
$s->cambiar('actividad',$a,true,$admin2,'Reabrir actividad');$s->cambiar('espacio',$otroEspacio,true,$admin2,'Reabrir destino');$s->cambiar('modulo',$m,true,$admin2,'Reabrir módulo');
cq($p,'UPDATE modulos SET cupo_maximo=1 WHERE id=?',[$m]);
$nuevo=$id("INSERT INTO inscripcion(fk_modulo,fk_usuario,lista_espera) VALUES(?,?,false)",[$m,$alumno3]);
cr(fn()=>$s->reactivarInscripciones($m,$admin2,'Sin cupo'),'reactivación sin capacidad bloqueada');
ca(cq($p,'SELECT estado FROM inscripcion WHERE id=?',[$i1])->fetchColumn()==='suspendido','sin reactivación parcial');
cq($p,'UPDATE modulos SET cupo_maximo=2 WHERE id=?',[$m]);
ca($s->reactivarInscripciones($m,$admin2,'Capacidad corregida')===2,'recuperación tras corregir cupo');
$diaModulo=(int)cq($p,'SELECT id FROM dias WHERE orden=1 LIMIT 1')->fetchColumn();
cq($p,'INSERT INTO dias_modulos(fk_modulo,fk_dia) VALUES(?,?)',[$m,$diaModulo]);
$conflicto=$id("INSERT INTO modulos(nombre,horario_inicio,horario_cierre,cupo_maximo,fk_actividad,fk_entidad,fk_espacio,estado) VALUES('Superposición','15:00','16:00',2,?,?,?,false)",[$a,$e,$otroEspacio]);
cq($p,'INSERT INTO dias_modulos(fk_modulo,fk_dia) VALUES(?,?)',[$conflicto,$diaModulo]);
cr(fn()=>$s->cambiar('modulo',$conflicto,true,$admin2,'Horario ocupado'),'reactivación valida agenda');
ca(!cq($p,'SELECT estado FROM modulos WHERE id=?',[$conflicto])->fetchColumn(),'conflicto no deja módulo activo');
$s->cambiar('modulo',$m,false,$admin2,'Revisión de condiciones');
$categoria=$id("INSERT INTO categoria(nombre,edad_minima,edad_maxima) VALUES('Fixture categoría amplia',0,99)");
$subcategoria=$id("INSERT INTO subcategorias(nombre,edad_minima,edad_maxima,fk_actividad,fk_categoria,fk_entidad) VALUES('Fixture fuera de edad',70,99,?,?,?)",[$a,$categoria,$e]);
cq($p,'UPDATE modulos SET fk_categoria=?,fk_subcategoria=? WHERE id=?',[$categoria,$subcategoria,$m]);
$s->cambiar('modulo',$m,true,$admin2,'Reabrir con condiciones cambiadas');
cr(fn()=>$s->reactivarInscripciones($m,$admin2,'Fuera de edad'),'condiciones de edad no se ignoran');
cq($p,'UPDATE subcategorias SET estado=FALSE WHERE id=?',[$subcategoria]);
ca($s->reactivarInscripciones($m,$admin2,'Subcategoría inactiva')===3,'subcategoría inactiva usa el rango de la categoría');

// Procesos independientes: una baja concurrente no deja reservas activas ni pierde obligaciones.
function concurrentes(array $operaciones): array {
    $procesos=[];
    foreach($operaciones as $args) {
        $pipes=[];$proceso=proc_open(array_merge([PHP_BINARY,'-c',(string)getenv('MULTIPRO_TEST_PHP_INI'),__FILE__,'worker'],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proceso))throw new RuntimeException('No se pudo iniciar el proceso de concurrencia.');
        fclose($pipes[0]);$procesos[]=[$proceso,$pipes];
    }
    $estados=[];
    foreach($procesos as [$proceso,$pipes]) {
        $salida=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        $codigo=proc_close($proceso);ca(in_array($codigo,[0,2],true),'proceso concurrente controlado: '.$error.$salida);$estados[]=$codigo;
    }
    return $estados;
}
$s->cambiar('espacio',$pago,true,$admin2,'Preparar concurrencia');
$rr=$reservas->crear($pago,$alumno,$turno->modify('+5 days')->format('Y-m-d'),$hora,'100.00','presencial');
concurrentes([['cobro',(string)$rr['id'],(string)$admin2],['baja',(string)$pago,(string)$admin2]]);
ca(cq($p,'SELECT estado FROM reservas WHERE id=?',[$rr['id']])->fetchColumn()==='cancelado','cobro vs baja termina cancelada');
$cobros=(int)cq($p,"SELECT count(*) FROM reserva_movimientos_presenciales WHERE fk_reserva=? AND tipo='cobro'",[$rr['id']])->fetchColumn();
$devoluciones=(int)cq($p,"SELECT count(*) FROM reserva_movimientos_presenciales WHERE fk_reserva=? AND tipo='devolucion_solicitada'",[$rr['id']])->fetchColumn();
ca($cobros===$devoluciones,'cobro concurrente conserva devolución');
if($devoluciones)$reservas->registrarDevolucion((int)$rr['id'],$admin2);
$s->cambiar('espacio',$espacio,true,$admin2,'Preparar concurrencia gratuita');
$diaConcurrente=$turno->modify('+6 days')->format('Y-m-d');
concurrentes([['crear',(string)$espacio,(string)$alumno,$diaConcurrente,$hora],['baja',(string)$espacio,(string)$admin2]]);
ca((int)cq($p,"SELECT count(*) FROM reservas WHERE fk_espacio=? AND fecha_de_asistencia=? AND estado IN ('reservado','pendiente_presencial')",[$espacio,$diaConcurrente])->fetchColumn()===0,'ninguna reserva escapa a la baja');
$s->cambiar('espacio',$pago,true,$admin2,'Preparar responsables');
$rr=$reservas->crear($pago,$alumno,$turno->modify('+7 days')->format('Y-m-d'),$hora,'100.00','presencial');
$reservas->registrarCobro((int)$rr['id'],$admin2);$reservas->cancelarAdministrador((int)$rr['id'],$admin2,'Devolución por prueba');
$s->cambiarAdministrador($admin,true,$gestor,'Dos responsables');
$resultados=concurrentes([['admin',(string)$admin,(string)$gestor],['admin',(string)$admin2,(string)$gestor]]);
sort($resultados);ca($resultados===[0,2],'dos bajas concurrentes conservan un responsable');
$responsable=(int)cq($p,"SELECT u.id FROM usuarios u JOIN roles r ON r.id=u.fk_rol WHERE u.fk_entidad=? AND u.estado AND lower(r.nombre)='administrador'",[$e])->fetchColumn();
$reservas->registrarDevolucion((int)$rr['id'],$responsable);
require __DIR__.'/web.php';
echo 'CASCADA_Y_UBICACION_PG_OK casos='.$casos."\n";
