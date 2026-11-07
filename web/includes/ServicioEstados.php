<?php
declare(strict_types=1);
require_once __DIR__.'/pagos/ServicioReservas.php';

/** Baja pública y cierre administrativo. Nunca entrega dinero ni restaura reservas canceladas. */
final class ServicioEstados
{
    private const TABLAS=['entidad'=>'entidades','actividad'=>'actividades','espacio'=>'espacios','modulo'=>'modulos'];
    public function __construct(private PDO $db) {}
    private function q(string $sql,array $valores=[]): PDOStatement {
        $s=$this->db->prepare($sql);$s->execute($valores);return $s;
    }
    private function tx(callable $f): mixed {
        if($this->db->inTransaction())throw new RuntimeException('La operación requiere una transacción independiente.');
        $this->db->beginTransaction();
        try {
            $this->q("SET LOCAL lock_timeout='5s'");
            // Mismo cerrojo que reservas e inscripciones: el impacto se confirma sin operaciones intercaladas.
            $this->q('SELECT pg_advisory_xact_lock(76730009)');
            $v=$f();$this->db->commit();return $v;
        } catch(Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function autorizar(int $actor,int $entidad,bool $gestor=false): void {
        $u=$this->q('SELECT u.*,lower(r.nombre) rol FROM usuarios u JOIN roles r ON r.id=u.fk_rol WHERE u.id=? AND u.estado FOR SHARE OF u',[$actor])->fetch(PDO::FETCH_ASSOC);
        if(!$u || ($gestor ? $u['rol']!=='gestor' : ($u['rol']!=='administrador' || (int)$u['fk_entidad']!==$entidad)))throw new RuntimeException('No tenés permiso para esta operación.');
    }
    private function motivo(string $motivo): string {
        $motivo=trim($motivo);
        if($motivo==='' || mb_strlen($motivo)>500)throw new RuntimeException('Indicá un motivo de hasta 500 caracteres.');
        return $motivo;
    }
    private function auditar(string $tipo,int $id,int $entidad,int $actor,string $accion,string $motivo): int {
        $d=$this->impacto($entidad)['datos'];
        $d['modulos']=array_values(array_filter($d['modulos'],fn($m)=>$tipo==='entidad' || ($tipo==='actividad' && (int)$m['fk_actividad']===$id) || ($tipo==='modulo' && (int)$m['id']===$id) || ($tipo==='espacio' && (int)$m['fk_espacio']===$id)));
        $ids=array_column($d['modulos'],'id');
        $d['inscripciones']=array_values(array_filter($d['inscripciones'],fn($i)=>in_array($i['fk_modulo'],$ids)));
        $d['reservas']=array_values(array_filter($d['reservas'],fn($r)=>$tipo==='entidad' || ($tipo==='espacio' && (int)$r['fk_espacio']===$id)));
        $ids=array_column($d['reservas'],'id');
        $d['movimientos']=array_values(array_filter($d['movimientos'],fn($m)=>in_array($m['fk_reserva'],$ids)));
        $d['actividades']=array_values(array_filter($d['actividades'],fn($r)=>$tipo==='entidad' || ($tipo==='actividad' && (int)$r['id']===$id)));
        $d['espacios']=array_values(array_filter($d['espacios'],fn($r)=>$tipo==='entidad' || ($tipo==='espacio' && (int)$r['id']===$id)));
        $d['administradores']=array_values(array_filter($d['administradores'],fn($r)=>$tipo==='administrador' && (int)$r['id']===$id));
        return (int)$this->q('INSERT INTO operaciones_estado(recurso,recurso_id,fk_entidad,fk_actor,accion,motivo,situacion_anterior) VALUES(?,?,?,?,?,?,?::jsonb) RETURNING id',[$tipo,$id,$entidad,$actor,$accion,$motivo,json_encode($d,JSON_THROW_ON_ERROR)])->fetchColumn();
    }
    private function entidad(int $id): array {
        $e=$this->q('SELECT * FROM entidades WHERE id=? FOR UPDATE',[$id])->fetch(PDO::FETCH_ASSOC);
        if(!$e)throw new RuntimeException('La entidad no está disponible.');return $e;
    }
    private function suspender(array $modulos,int $operacion): void {
        foreach($modulos as $id) {
            $this->q('UPDATE modulos SET estado=FALSE,desactivacion_operacion=? WHERE id=? AND estado',[$operacion,$id]);
            $this->q("UPDATE inscripcion SET estado='suspendido',suspension_operacion=? WHERE fk_modulo=? AND estado='activo'",[$operacion,$id]);
        }
    }
    private function impacto(int $entidad): array {
        $datos=[];
        foreach([
            'entidad'=>'SELECT id,estado,cierre_administrativo_en FROM entidades WHERE id=?',
            'actividades'=>'SELECT id,estado FROM actividades WHERE fk_entidad=? ORDER BY id',
            'espacios'=>'SELECT id,estado FROM espacios WHERE fk_entidad=? ORDER BY id',
            'modulos'=>'SELECT id,estado,fk_espacio,fk_actividad,cupo_maximo,fk_usuario_profesor FROM modulos WHERE fk_entidad=? ORDER BY id',
            'inscripciones'=>'SELECT i.* FROM inscripcion i JOIN modulos m ON m.id=i.fk_modulo WHERE m.fk_entidad=? ORDER BY i.id',
            'reservas'=>"SELECT r.* FROM reservas r JOIN espacios s ON s.id=r.fk_espacio WHERE s.fk_entidad=? AND r.estado IN ('reservado','pendiente_presencial','pendiente_pago') AND ((r.fecha_de_asistencia+r.horario) AT TIME ZONE 'America/Argentina/Buenos_Aires')>clock_timestamp() ORDER BY r.id",
            'movimientos'=>'SELECT id,fk_reserva,tipo,importe_ars FROM reserva_movimientos_presenciales WHERE fk_entidad=? ORDER BY id',
            'administradores'=>"SELECT u.id,u.estado FROM usuarios u JOIN roles r ON r.id=u.fk_rol WHERE u.fk_entidad=? AND lower(r.nombre)='administrador' ORDER BY u.id"
        ] as $clave=>$sql)$datos[$clave]=$this->q($sql,[$entidad])->fetchAll(PDO::FETCH_ASSOC);
        return ['firma'=>hash('sha256',json_encode($datos,JSON_THROW_ON_ERROR)),'datos'=>$datos];
    }
    private function verificarImpacto(int $entidad,?string $firma): void {
        if($firma!==null && !hash_equals($this->impacto($entidad)['firma'],$firma))throw new RuntimeException('El impacto cambió desde la vista previa. Revisá nuevamente antes de confirmar. No se realizó ningún cambio.');
    }
    public function previsualizar(string $tipo,int $id,int $actor): array {
        $tabla=$tipo==='administrador'?'usuarios':(self::TABLAS[$tipo]??null);
        if(!$tabla)throw new RuntimeException('Recurso no válido.');
        return $this->tx(function()use($tipo,$id,$actor,$tabla){
            $r=$this->q("SELECT * FROM $tabla WHERE id=?",[$id])->fetch(PDO::FETCH_ASSOC);
            if(!$r)throw new RuntimeException('Recurso no disponible.');
            $entidad=$tipo==='entidad'?$id:(int)$r['fk_entidad'];
            $this->autorizar($actor,$entidad,in_array($tipo,['entidad','administrador'],true));
            $impacto=$this->impacto($entidad);$d=$impacto['datos'];
            $modulos=array_filter($d['modulos'],fn($m)=>$tipo==='entidad' || ($tipo==='actividad' && (int)$m['fk_actividad']===$id) || ($tipo==='modulo' && (int)$m['id']===$id) || ($tipo==='espacio' && (int)$m['fk_espacio']===$id));
            $ids=array_column($modulos,'id');
            $inscripciones=array_filter($d['inscripciones'],fn($i)=>in_array($i['fk_modulo'],$ids) && in_array($i['estado'],['activo','suspendido'],true));
            $reservas=in_array($tipo,['entidad','espacio'],true)?array_filter($d['reservas'],fn($r)=>$tipo==='entidad' || (int)$r['fk_espacio']===$id):[];
            $cobradas=array_column(array_filter($d['movimientos'],fn($m)=>$m['tipo']==='cobro'),'fk_reserva');
            $centavos=0;$devoluciones=0;
            foreach($reservas as $reserva)if($reserva['modalidad_pago']==='presencial' && in_array($reserva['id'],$cobradas)){$centavos+=(int)str_replace('.','',$reserva['importe_total_ars']);$devoluciones++;}
            return ['firma'=>$impacto['firma'],'nombre'=>$r['nombre'],'modulos_activos'=>count(array_filter($modulos,fn($m)=>$m['estado'])),'inscripciones'=>count($inscripciones),'reservas'=>count($reservas),'devoluciones'=>$devoluciones,'importe'=>number_format($centavos/100,2,',','.'),'pendientes'=>(int)$this->q('SELECT multipro_pendientes_economicos(?)',[$entidad])->fetchColumn()];
        });
    }
    public function cambiar(string $tipo,int $id,bool $activar,int $actor,string $motivo,?string $firma=null): array {
        if(!isset(self::TABLAS[$tipo]))throw new RuntimeException('Tipo de recurso no válido.');
        $motivo=$this->motivo($motivo);
        return $this->tx(function()use($tipo,$id,$activar,$actor,$motivo,$firma){
            $tabla=self::TABLAS[$tipo];
            $r=$this->q("SELECT * FROM $tabla WHERE id=? FOR UPDATE",[$id])->fetch(PDO::FETCH_ASSOC);
            if(!$r)throw new RuntimeException('El recurso no está disponible.');
            $entidad=$tipo==='entidad'?$id:(int)$r['fk_entidad'];
            $this->autorizar($actor,$entidad,$tipo==='entidad');
            $e=$this->entidad($entidad);
            $this->verificarImpacto($entidad,$firma);
            if((bool)$r['estado']===$activar)return ['reservas'=>0,'modulos'=>0,'sin_cambios'=>true];
            if($activar && $tipo!=='entidad' && (!$e['estado'] || $e['cierre_administrativo_en']))throw new RuntimeException('Reactivá primero la entidad.');
            if($activar && $tipo==='modulo' && !$this->q('SELECT 1 FROM actividades WHERE id=? AND fk_entidad=? AND estado',[$r['fk_actividad'],$entidad])->fetchColumn())throw new RuntimeException('Reactivá primero la actividad del módulo.');
            if(!$activar && $tipo==='espacio' && $this->q('SELECT 1 FROM modulos WHERE fk_espacio=? AND estado LIMIT 1',[$id])->fetchColumn())throw new RuntimeException('No se puede desactivar el espacio: reubicá primero sus módulos activos. No se realizó ningún cambio.');
            if(!$activar && in_array($tipo,['entidad','espacio'],true) && $this->q("SELECT 1 FROM reservas r JOIN espacios s ON s.id=r.fk_espacio WHERE s.fk_entidad=? AND (?=0 OR s.id=?) AND r.modalidad_pago='online' AND r.estado IN ('reservado','pendiente_pago') AND ((r.fecha_de_asistencia+r.horario) AT TIME ZONE 'America/Argentina/Buenos_Aires')>clock_timestamp() LIMIT 1",[$entidad,$tipo==='espacio'?$id:0,$id])->fetchColumn())throw new RuntimeException('Hay reservas online que requieren revisión antes de la baja.');
            if(!$activar && in_array($tipo,['entidad','espacio'],true)
               && $this->q("SELECT 1 FROM reservas r JOIN espacios s ON s.id=r.fk_espacio JOIN reserva_movimientos_presenciales c ON c.fk_reserva=r.id AND c.tipo='cobro' WHERE s.fk_entidad=? AND (?=0 OR s.id=?) AND r.estado='reservado' AND ((r.fecha_de_asistencia+r.horario) AT TIME ZONE 'America/Argentina/Buenos_Aires')>clock_timestamp() LIMIT 1",[$entidad,$tipo==='espacio'?$id:0,$id])->fetchColumn()
               && !$this->q("SELECT 1 FROM usuarios u JOIN roles r ON r.id=u.fk_rol WHERE u.fk_entidad=? AND u.estado AND lower(r.nombre)='administrador' LIMIT 1",[$entidad])->fetchColumn())throw new RuntimeException('Asigná un administrador activo antes de generar devoluciones pendientes.');
            $op=$this->auditar($tipo,$id,$entidad,$actor,$activar?'activar':'desactivar',$motivo);
            $modulos=[];$reservas=[];
            if(!$activar) {
                if($tipo==='modulo')$modulos=[$id];
                elseif($tipo==='actividad')$modulos=$this->q('SELECT id FROM modulos WHERE fk_actividad=? ORDER BY id FOR UPDATE',[$id])->fetchAll(PDO::FETCH_COLUMN);
                elseif($tipo==='entidad')$modulos=$this->q('SELECT id FROM modulos WHERE fk_entidad=? ORDER BY id FOR UPDATE',[$entidad])->fetchAll(PDO::FETCH_COLUMN);
                $this->suspender($modulos,$op);
                if($tipo==='entidad') {
                    $this->q('UPDATE actividades SET estado=FALSE,desactivacion_operacion=? WHERE fk_entidad=? AND estado',[$op,$entidad]);
                    $this->q('UPDATE espacios SET estado=FALSE,desactivacion_operacion=? WHERE fk_entidad=? AND estado',[$op,$entidad]);
                }
                if(in_array($tipo,['entidad','espacio'],true)) {
                    $reservas=$this->q("SELECT r.id FROM reservas r JOIN espacios s ON s.id=r.fk_espacio WHERE s.fk_entidad=? AND (?=0 OR s.id=?) AND r.estado IN ('reservado','pendiente_presencial') AND ((r.fecha_de_asistencia+r.horario) AT TIME ZONE 'America/Argentina/Buenos_Aires')>clock_timestamp() ORDER BY r.id FOR UPDATE OF r",[$entidad,$tipo==='espacio'?$id:0,$id])->fetchAll(PDO::FETCH_COLUMN);
                    $servicio=new \MultiPro\Pagos\ServicioReservas($this->db);
                    foreach($reservas as $reserva)$servicio->cancelarPorBaja((int)$reserva,$actor,'Baja de '.$tipo.': '.$motivo);
                }
            }
            $this->q("UPDATE $tabla SET estado=?,desactivacion_operacion=?".($tipo==='entidad'&&$activar?',cierre_administrativo_en=NULL':'').' WHERE id=?',[$activar?'true':'false',$activar?null:$op,$id]);
            return ['reservas'=>count($reservas),'modulos'=>count($modulos),'sin_cambios'=>false];
        });
    }
    public function reactivarInscripciones(int $modulo,int $actor,string $motivo,?string $firma=null): int {
        $motivo=$this->motivo($motivo);
        return $this->tx(function()use($modulo,$actor,$motivo,$firma){
            $m=$this->q('SELECT * FROM modulos WHERE id=? FOR UPDATE',[$modulo])->fetch(PDO::FETCH_ASSOC);
            if(!$m)throw new RuntimeException('El módulo no está disponible.');
            $this->autorizar($actor,(int)$m['fk_entidad']);
            $this->verificarImpacto((int)$m['fk_entidad'],$firma);
            if(!$this->q('SELECT multipro_modulo_disponible(?)',[$modulo])->fetchColumn())throw new RuntimeException('Reactivá primero la entidad, actividad, espacio y módulo.');
            $filas=$this->q("SELECT * FROM inscripcion WHERE fk_modulo=? AND estado IN ('activo','suspendido') ORDER BY id FOR UPDATE",[$modulo])->fetchAll(PDO::FETCH_ASSOC);
            $cupos=0;$personas=[];$total=0;
            $rango=$this->q('SELECT COALESCE(s.edad_minima,c.edad_minima,0) minima,COALESCE(s.edad_maxima,c.edad_maxima,99) maxima FROM modulos m LEFT JOIN categoria c ON c.id=m.fk_categoria LEFT JOIN subcategorias s ON s.id=m.fk_subcategoria WHERE m.id=?',[$modulo])->fetch(PDO::FETCH_ASSOC);
            foreach($filas as $i) {
                $clave=$i['fk_usuario']?'u'.$i['fk_usuario']:'m'.$i['fk_menor'];
                if(isset($personas[$clave]))throw new RuntimeException('Hay inscripciones duplicadas. Revisalas antes de reactivar el conjunto.');
                $personas[$clave]=true;if(!$i['lista_espera'])$cupos++;
                if($i['estado']==='suspendido') {
                    $persona=$i['fk_usuario']?$this->q('SELECT fecha_nacimiento,estado FROM usuarios WHERE id=?',[$i['fk_usuario']])->fetch(PDO::FETCH_ASSOC):$this->q('SELECT m.fecha_nacimiento,u.estado FROM menores m JOIN usuarios u ON u.id=m.fk_usuario WHERE m.id=?',[$i['fk_menor']])->fetch(PDO::FETCH_ASSOC);
                    if(!$persona || !$persona['estado'] || !$persona['fecha_nacimiento'])throw new RuntimeException('Hay una persona o tutor inactivo o sin fecha de nacimiento. Revisá el conjunto antes de reactivarlo.');
                    $edad=(new DateTimeImmutable($persona['fecha_nacimiento']))->diff(new DateTimeImmutable('today',new DateTimeZone('America/Argentina/Buenos_Aires')))->y;
                    if($edad<(int)$rango['minima'] || $edad>(int)$rango['maxima'])throw new RuntimeException('Hay inscripciones suspendidas fuera del rango de edad actual. Resolvé el conflicto antes de reactivar el conjunto.');
                    $total++;
                }
            }
            if($cupos>(int)$m['cupo_maximo'])throw new RuntimeException('El cupo actual no alcanza para restaurar las inscripciones. No se modificó la lista de espera.');
            if(!$total)return 0;
            $this->auditar('modulo',$modulo,(int)$m['fk_entidad'],$actor,'reactivar_inscripciones',$motivo);
            $this->q("UPDATE inscripcion SET estado='activo' WHERE fk_modulo=? AND estado='suspendido' AND suspension_operacion IS NOT NULL",[$modulo]);
            return $total;
        });
    }
    public function cerrarAdministracion(int $entidad,int $actor,string $motivo,?string $firma=null): void {
        $motivo=$this->motivo($motivo);
        $this->tx(function()use($entidad,$actor,$motivo,$firma){
            $this->autorizar($actor,$entidad,true);$e=$this->entidad($entidad);
            $this->verificarImpacto($entidad,$firma);
            if($e['estado'])throw new RuntimeException('Desactivá primero la operación pública de la entidad.');
            if($this->q('SELECT multipro_pendientes_economicos(?)',[$entidad])->fetchColumn()>0)throw new RuntimeException('No se puede cerrar: quedan cobros o devoluciones pendientes.');
            if($e['cierre_administrativo_en'])return;
            $this->auditar('entidad',$entidad,$entidad,$actor,'cerrar_administracion',$motivo);
            $this->q('UPDATE entidades SET cierre_administrativo_en=clock_timestamp() WHERE id=?',[$entidad]);
        });
    }
    public function cambiarAdministrador(int $id,bool $activar,int $actor,string $motivo,?string $firma=null): void {
        $motivo=$this->motivo($motivo);
        $this->tx(function()use($id,$activar,$actor,$motivo,$firma){
            $u=$this->q("SELECT u.* FROM usuarios u JOIN roles r ON r.id=u.fk_rol WHERE u.id=? AND lower(r.nombre)='administrador' FOR UPDATE OF u",[$id])->fetch(PDO::FETCH_ASSOC);
            if(!$u)throw new RuntimeException('El administrador no está disponible.');
            $this->autorizar($actor,(int)$u['fk_entidad'],true);
            $this->verificarImpacto((int)$u['fk_entidad'],$firma);
            if((bool)$u['estado']===$activar)return;
            if(!$activar && (int)$this->q('SELECT multipro_pendientes_economicos(?)',[$u['fk_entidad']])->fetchColumn()>0
               && !$this->q("SELECT 1 FROM usuarios u JOIN roles r ON r.id=u.fk_rol WHERE u.fk_entidad=? AND u.id<>? AND u.estado AND lower(r.nombre)='administrador' LIMIT 1",[$u['fk_entidad'],$id])->fetchColumn())throw new RuntimeException('No podés desactivar al último administrador: quedan cobros o devoluciones pendientes.');
            $this->auditar('administrador',$id,(int)$u['fk_entidad'],$actor,$activar?'activar':'desactivar',$motivo);
            $this->q('UPDATE usuarios SET estado=? WHERE id=?',[$activar?'true':'false',$id]);
        });
    }
}
