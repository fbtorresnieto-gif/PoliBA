<?php
declare(strict_types=1);
namespace MultiPro\Pagos;

require_once dirname(__DIR__).'/NotificacionesEmail.php';

use PDO;
use Throwable;
use RuntimeException;
use DateTimeImmutable;
use DateTimeZone;

/** Reservas gratuitas y presenciales. Identidades obtenidas de la sesion, nunca del formulario. */
final class ServicioReservas
{
    public function __construct(private PDO $db) {}
    private function q(string $sql,array $p=[]): \PDOStatement {
        $s=$this->db->prepare($sql);$s->execute($p);return $s;
    }
    private function tx(callable $f): mixed {
        if($this->db->inTransaction())throw new RuntimeException('Operación no disponible dentro de otra transacción.');
        $this->db->beginTransaction();
        try {$this->q("SET LOCAL lock_timeout='5s'");$v=$f();$this->db->commit();return $v;}
        catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function alumno(int $id): void {
        if(!$this->q("SELECT 1 FROM usuarios u JOIN roles r ON r.id=u.fk_rol WHERE u.id=? AND u.estado AND lower(r.nombre)='alumno'",[$id])->fetchColumn())
            throw new RuntimeException('La reserva requiere una cuenta de alumno activa.');
    }
    private function reserva(int $id): array {
        $r=$this->q('SELECT * FROM reservas WHERE id=? FOR UPDATE',[$id])->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new RuntimeException('Reserva no disponible.');return $r;
    }
    private function admin(array $r,int $actor): int {
        $e=$this->q('SELECT fk_entidad FROM espacios WHERE id=? FOR SHARE',[$r['fk_espacio']])->fetchColumn();
        if(!$this->q("SELECT 1 FROM usuarios u JOIN roles ro ON ro.id=u.fk_rol WHERE u.id=? AND u.estado AND u.fk_entidad=? AND lower(ro.nombre)='administrador'",[$actor,$e])->fetchColumn())
            throw new RuntimeException('No tenés permiso para administrar esta reserva.');
        return (int)$e;
    }
    private function importeEtiqueta(string $valor): string {
        [$entero,$decimales]=array_pad(explode('.',$valor,2),2,'00');
        return '$ '.preg_replace('/\B(?=(\d{3})+(?!\d))/','.',$entero).','.str_pad(substr($decimales,0,2),2,'0');
    }
    private function encolarReserva(array $r,string $tipo,string $sufijo): void {
        $d=$this->q('SELECT r.fk_usuario,r.fecha_de_asistencia,to_char(r.horario,\'HH24:MI\') hora,
            r.importe_total_ars,e.nombre espacio,en.nombre entidad FROM reservas r
            JOIN espacios e ON e.id=r.fk_espacio JOIN entidades en ON en.id=e.fk_entidad WHERE r.id=?',[$r['id']])->fetch(PDO::FETCH_ASSOC);
        if(!$d)throw new RuntimeException('No fue posible preparar la notificación.');
        \NotificacionesEmail::encolar($this->db,'reserva:'.$r['id'].':'.$sufijo,$tipo,(int)$d['fk_usuario'],[
            'fecha'=>(new DateTimeImmutable($d['fecha_de_asistencia']))->format('d/m/Y'),'hora'=>$d['hora'],
            'importe'=>$this->importeEtiqueta((string)$d['importe_total_ars']),'espacio'=>$d['espacio'],'entidad'=>$d['entidad']],(int)$r['id']);
    }
    private function encolarRecordatorio(array $r): void {
        $d=$this->q("SELECT r.fk_usuario,r.fecha_de_asistencia,to_char(r.horario,'HH24:MI') hora,
            ((r.fecha_de_asistencia+r.horario) AT TIME ZONE 'America/Argentina/Buenos_Aires')-INTERVAL '24 hours' programada,
            e.nombre espacio,en.nombre entidad FROM reservas r JOIN espacios e ON e.id=r.fk_espacio
            JOIN entidades en ON en.id=e.fk_entidad WHERE r.id=?",[$r['id']])->fetch(PDO::FETCH_ASSOC);
        if(!$d)throw new RuntimeException('No fue posible programar el recordatorio.');
        \NotificacionesEmail::encolar($this->db,'reserva:'.$r['id'].':recordatorio_24h','recordatorio_turno',(int)$d['fk_usuario'],[
            'fecha'=>(new DateTimeImmutable($d['fecha_de_asistencia']))->format('d/m/Y'),'hora'=>$d['hora'],
            'espacio'=>$d['espacio'],'entidad'=>$d['entidad']],(int)$r['id'],(string)$d['programada'],true);
    }
    public function crear(int $espacio,int $usuario,string $fecha,string $horario,string $precioVisto,string $modalidad): array {
        $zona=new DateTimeZone('America/Argentina/Buenos_Aires');
        $dia=DateTimeImmutable::createFromFormat('!Y-m-d',$fecha,$zona);
        if(!$dia || $dia->format('Y-m-d')!==$fecha || !preg_match('/^([01][0-9]|2[0-3]):00:00$/D',$horario))
            throw new RuntimeException('Seleccioná una fecha válida y un turno de una hora completa.');
        if(new DateTimeImmutable($fecha.' '.$horario,$zona)<=new DateTimeImmutable('now',$zona))
            throw new RuntimeException('No podés reservar un horario que ya pasó.');
        if(!in_array($modalidad,['gratuito','presencial'],true))throw new RuntimeException('El pago online todavía no está disponible.');
        return $this->tx(function()use($espacio,$usuario,$fecha,$horario,$precioVisto,$modalidad,$dia){
            $this->alumno($usuario);
            $e=$this->q('SELECT * FROM espacios WHERE id=? AND estado FOR SHARE',[$espacio])->fetch(PDO::FETCH_ASSOC);
            if(!$e)throw new RuntimeException('El espacio no está disponible.');
            $entidad=$this->q('SELECT en.*,da.orden AS abre,dc.orden AS cierra FROM entidades en
                LEFT JOIN dias da ON da.id=en.fk_dia_apertura LEFT JOIN dias dc ON dc.id=en.fk_dia_cierre
                WHERE en.id=? AND en.estado FOR SHARE OF en',[$e['fk_entidad']])->fetch(PDO::FETCH_ASSOC);
            if(!$entidad)throw new RuntimeException('La entidad no está disponible.');
            if($precioVisto!==$e['precio_hora_ars'])throw new RuntimeException('El precio cambió. Recargá la página y confirmá el nuevo importe.');
            $gratis=$e['precio_hora_ars']==='0.00';
            if(($gratis && $modalidad!=='gratuito') || (!$gratis && ($modalidad!=='presencial' || !$e['pago_presencial_habilitado'])))
                throw new RuntimeException('La modalidad de pago seleccionada no está disponible.');
            $n=(int)$dia->format('N');$abre=(int)($entidad['abre']??1);$cierra=(int)($entidad['cierra']??7);
            $abierto=$abre<=$cierra?($n>=$abre&&$n<=$cierra):($n>=$abre||$n<=$cierra);
            $inicio=(int)substr($horario,0,2)*3600;
            $segundos=static fn(string $h):int=>(int)substr($h,0,2)*3600+(int)substr($h,3,2)*60+(int)substr($h,6,2);
            if(!$abierto || $inicio<$segundos($entidad['horario_apertura']) || $inicio+3600>$segundos($entidad['horario_cierre']))
                throw new RuntimeException('La entidad está cerrada en ese turno.');
            if($this->q('SELECT 1 FROM modulos m JOIN dias_modulos dm ON dm.fk_modulo=m.id JOIN dias d ON d.id=dm.fk_dia
                WHERE m.fk_espacio=? AND m.estado AND d.orden=? AND m.horario_inicio<?::time AND m.horario_cierre>?::time LIMIT 1',
                [$espacio,$n,sprintf('%02d:00:00',(int)substr($horario,0,2)+1),$horario])->fetchColumn())
                throw new RuntimeException('El turno está ocupado por un módulo.');
            $existente=$this->q("SELECT * FROM reservas WHERE fk_usuario=? AND fecha_de_asistencia=? AND horario=?
                AND estado IN ('reservado','pendiente_pago','pendiente_presencial')",[$usuario,$fecha,$horario])->fetch(PDO::FETCH_ASSOC);
            if($existente){
                if((int)$existente['fk_espacio']===$espacio && $existente['modalidad_pago']===$modalidad && $existente['importe_total_ars']===$precioVisto){
                    if($modalidad==='presencial')$this->encolarReserva($existente,'reserva_presencial_creada','creada_presencial');
                    else {$this->encolarReserva($existente,'reserva_gratuita_creada','creada_gratuita');$this->encolarRecordatorio($existente);}
                    return $existente;
                }
                throw new RuntimeException('Ya tenés una reserva en ese horario.');
            }
            $nueva=$this->q('INSERT INTO reservas(fk_espacio,fk_usuario,fecha_de_asistencia,horario,estado,modalidad_pago,importe_total_ars)
                VALUES(?,?,?,?,?,?,?) RETURNING *',[$espacio,$usuario,$fecha,$horario,$gratis?'reservado':'pendiente_presencial',$modalidad,$e['precio_hora_ars']])->fetch(PDO::FETCH_ASSOC);
            if($modalidad==='presencial')$this->encolarReserva($nueva,'reserva_presencial_creada','creada_presencial');
            else {$this->encolarReserva($nueva,'reserva_gratuita_creada','creada_gratuita');$this->encolarRecordatorio($nueva);}
            return $nueva;
        });
    }
    private function movimiento(array $r,int $entidad,string $tipo,int $actor): void {
        $this->q('INSERT INTO reserva_movimientos_presenciales(fk_reserva,fk_entidad,tipo,importe_ars,fk_actor)
            VALUES(?,?,?,?,?)',[$r['id'],$entidad,$tipo,$r['importe_total_ars'],$actor]);
    }
    public function registrarCobro(int $reserva,int $actor): void {
        $this->tx(function()use($reserva,$actor){
            $r=$this->reserva($reserva);$entidad=$this->admin($r,$actor);
            if($this->q("SELECT 1 FROM reserva_movimientos_presenciales WHERE fk_reserva=? AND tipo='cobro'",[$reserva])->fetchColumn())return;
            if($r['modalidad_pago']!=='presencial'||$r['estado']!=='pendiente_presencial')throw new RuntimeException('La reserva no está pendiente de cobro presencial.');
            $this->movimiento($r,$entidad,'cobro',$actor);
            $this->q("UPDATE reservas SET estado='reservado',actualizado_en=clock_timestamp() WHERE id=?",[$reserva]);
            $this->encolarReserva($r,'cobro_presencial_registrado','cobro_presencial');
            $this->encolarRecordatorio($r);
        });
    }
    public function cancelarTitular(int $reserva,int $actor): void {$this->cancelar($reserva,$actor,false,'Cancelación solicitada por titular');}
    public function cancelarAdministrador(int $reserva,int $actor,string $motivo): void {
        $motivo=trim($motivo);if($motivo===''||mb_strlen($motivo)>500)throw new RuntimeException('Indicá un motivo de cancelación de hasta 500 caracteres.');
        $this->cancelar($reserva,$actor,true,$motivo);
    }
    private function cancelar(int $reserva,int $actor,bool $admin,string $motivo): void {
        $this->tx(function()use($reserva,$actor,$admin,$motivo){
            $r=$this->reserva($reserva);
            if($admin)$entidad=$this->admin($r,$actor);
            else{
                $this->alumno($actor);
                if((int)$r['fk_usuario']!==$actor)throw new RuntimeException('Reserva no disponible.');
                $entidad=(int)$this->q('SELECT fk_entidad FROM espacios WHERE id=? FOR SHARE',[$r['fk_espacio']])->fetchColumn();
            }
            if($r['modalidad_pago']==='online')throw new RuntimeException('La cancelación online todavía no está habilitada en esta pantalla.');
            if($r['estado']==='cancelado')return;
            if(!in_array($r['estado'],['reservado','pendiente_presencial'],true))throw new RuntimeException('La reserva no se puede cancelar.');
            $this->q("UPDATE reservas SET estado='cancelado',cancelado_en=clock_timestamp(),cancelacion_origen=?,
                cancelado_por_usuario=?,motivo_cancelacion=?,actualizado_en=clock_timestamp() WHERE id=?",[$admin?'administrador':'usuario',$actor,$motivo,$reserva]);
            $devolver=$this->q("SELECT ((fecha_de_asistencia+horario) AT TIME ZONE 'America/Argentina/Buenos_Aires')>=cancelado_en+interval '24 hours' FROM reservas WHERE id=?",[$reserva])->fetchColumn();
            $devolucionPendiente=false;
            if(($admin||$devolver)&&$r['modalidad_pago']==='presencial'&&$this->q("SELECT 1 FROM reserva_movimientos_presenciales WHERE fk_reserva=? AND tipo='cobro'",[$reserva])->fetchColumn()){
                $this->movimiento($r,$entidad,'devolucion_solicitada',$actor);
                $devolucionPendiente=true;
            }
            $this->encolarReserva($r,'reserva_cancelada','cancelada');
            if($devolucionPendiente)$this->encolarReserva($r,'devolucion_pendiente','devolucion_pendiente');
        });
    }
    public function registrarDevolucion(int $reserva,int $actor): void {
        $this->tx(function()use($reserva,$actor){
            $r=$this->reserva($reserva);$entidad=$this->admin($r,$actor);
            if($this->q("SELECT 1 FROM reserva_movimientos_presenciales WHERE fk_reserva=? AND tipo='devolucion'",[$reserva])->fetchColumn())return;
            if(!$this->q("SELECT 1 FROM reserva_movimientos_presenciales WHERE fk_reserva=? AND tipo='devolucion_solicitada'",[$reserva])->fetchColumn())
                throw new RuntimeException('No hay una devolución pendiente para esta reserva.');
            $this->movimiento($r,$entidad,'devolucion',$actor);
            $this->encolarReserva($r,'devolucion_realizada','devolucion_realizada');
        });
    }
}
