<?php
declare(strict_types=1);
require_once __DIR__.'/_datos.php';
apiAceptarMetodo('POST');
$id=reservaApiEntero(apiLeerJson(),'reserva_id');
try {
    [$pdo,$usuario]=apiContextoAutenticado('Alumno');
    reservaApiPropia($pdo,$id,(int)$usuario['id']);
    (new \MultiPro\Pagos\ServicioReservas($pdo))->cancelarTitular($id,(int)$usuario['id']);
    notificacionesDespacharSilencioso($pdo);
    apiResponder(['data'=>reservaApiPropia($pdo,$id,(int)$usuario['id'])]);
} catch(PDOException $e) {
    error_log('MultiPro API cancelar reserva: fallo de base de datos');apiResponder(['error'=>'No se pudo cancelar la reserva.'],500);
} catch(RuntimeException $e) {
    apiResponder(['error'=>$e->getMessage()],409);
} catch(Throwable $e) {
    error_log('MultiPro API cancelar reserva: fallo interno');apiResponder(['error'=>'No se pudo cancelar la reserva.'],500);
}
