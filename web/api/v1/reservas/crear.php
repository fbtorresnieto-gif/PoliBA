<?php
declare(strict_types=1);
require_once __DIR__.'/_datos.php';
apiAceptarMetodo('POST');
$datos=apiLeerJson();
$espacio=reservaApiEntero($datos,'espacio_id');
foreach(['fecha','horario'] as $campo)if(!is_string($datos[$campo]??null))apiResponder(['error'=>'Completá fecha y horario.'],422);
$fecha=trim($datos['fecha']);$hora=trim($datos['horario']);
$f=DateTimeImmutable::createFromFormat('!Y-m-d',$fecha);
if(!$f||$f->format('Y-m-d')!==$fecha)apiResponder(['error'=>'La fecha debe tener formato YYYY-MM-DD.'],422);
if(!preg_match('/^([01][0-9]|2[0-3]):00(?::00)?$/D',$hora))apiResponder(['error'=>'El turno debe comenzar en una hora completa.'],422);
if(strlen($hora)===5)$hora.=':00';
$modalidad=$datos['modalidad_pago']??'gratuito';
if(!is_string($modalidad)||!in_array($modalidad,['gratuito','presencial'],true))apiResponder(['error'=>'La modalidad no está disponible. El pago online sigue bloqueado.'],422);
// Compatibilidad: clientes anteriores solo pueden solicitar gratuitas con precio cero.
$precio=$datos['precio_visto']??($modalidad==='gratuito'?'0.00':null);
if(!is_string($precio)||!preg_match('/^(0|[1-9][0-9]{0,9})\.[0-9]{2}$/D',$precio))
    apiResponder(['error'=>'precio_visto debe ser texto decimal con dos decimales, por ejemplo 1500.00.'],422);
try {
    [$pdo,$usuario]=apiContextoAutenticado('Alumno');
    $r=(new \MultiPro\Pagos\ServicioReservas($pdo))->crear($espacio,(int)$usuario['id'],$fecha,$hora,$precio,$modalidad);
    notificacionesDespacharSilencioso($pdo);
    apiResponder(['data'=>reservaApiPropia($pdo,(int)$r['id'],(int)$usuario['id'])],201);
} catch(PDOException $e) {
    if($e->getCode()==='23505')apiResponder(['error'=>'El turno ya no está disponible o ya tenés una reserva en ese horario.'],409);
    error_log('MultiPro API crear reserva: fallo de base de datos');apiResponder(['error'=>'No se pudo registrar la reserva.'],500);
} catch(RuntimeException $e) {
    apiResponder(['error'=>$e->getMessage()],409);
} catch(Throwable $e) {
    error_log('MultiPro API crear reserva: fallo interno');apiResponder(['error'=>'No se pudo registrar la reserva.'],500);
}
