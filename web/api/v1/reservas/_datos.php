<?php
declare(strict_types=1);
require_once __DIR__.'/../_auth.php';
require_once __DIR__.'/../../../includes/pagos/ServicioReservas.php';

function reservaApiEntero(array $datos,string $campo): int {
    $v=$datos[$campo]??null;
    if ((!is_int($v)&&!is_string($v)) || filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)
        apiResponder(['error'=>$campo.' debe ser un entero positivo.'],422);
    return (int)$v;
}

function reservaApiSeleccion(): string {
    return "SELECT r.id,r.fk_espacio,r.fecha_de_asistencia,r.horario,r.estado,r.modalidad_pago,r.importe_total_ars,r.moneda,
        esp.nombre AS espacio_nombre,e.id AS entidad_id,e.nombre AS entidad_nombre,e.direccion AS entidad_direccion,
        EXISTS(SELECT 1 FROM reserva_movimientos_presenciales m WHERE m.fk_reserva=r.id AND m.tipo='cobro') AS presencial_cobrado,
        EXISTS(SELECT 1 FROM reserva_movimientos_presenciales m WHERE m.fk_reserva=r.id AND m.tipo='devolucion_solicitada') AS devolucion_solicitada,
        EXISTS(SELECT 1 FROM reserva_movimientos_presenciales m WHERE m.fk_reserva=r.id AND m.tipo='devolucion') AS devolucion_realizada
        FROM reservas r JOIN espacios esp ON esp.id=r.fk_espacio JOIN entidades e ON e.id=esp.fk_entidad";
}

function reservaApiDatos(array $r): array {
    return ['id'=>(int)$r['id'],'espacio_id'=>(int)$r['fk_espacio'],'espacio_nombre'=>$r['espacio_nombre'],
        'entidad_id'=>(int)$r['entidad_id'],'entidad_nombre'=>$r['entidad_nombre'],'entidad_direccion'=>$r['entidad_direccion'],
        'fecha'=>$r['fecha_de_asistencia'],'horario'=>$r['horario'],'estado'=>$r['estado'],
        'modalidad_pago'=>$r['modalidad_pago'],'importe_total_ars'=>$r['importe_total_ars'],'moneda'=>$r['moneda'],
        'presencial_cobrado'=>(bool)$r['presencial_cobrado'],
        'devolucion_presencial'=>$r['devolucion_realizada']?'realizada':($r['devolucion_solicitada']?'pendiente':'no_solicitada')];
}

function reservaApiPropia(PDO $pdo,int $id,int $usuario): array {
    $s=$pdo->prepare(reservaApiSeleccion()." WHERE r.id=? AND r.fk_usuario=? AND r.estado<>'vencido'");
    $s->execute([$id,$usuario]);$r=$s->fetch(PDO::FETCH_ASSOC);
    if(!$r)apiResponder(['error'=>'Reserva no encontrada.'],404);
    return reservaApiDatos($r);
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__) apiResponder(['error'=>'Recurso no encontrado.'],404);
